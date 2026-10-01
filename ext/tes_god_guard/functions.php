<?php
/*
 * tes_god_guard: validates the Narrator's God_Command text BEFORE the core
 * (herikaQueueGodCommands in herika-actions.patch) queues console commands.
 *
 *  - allowlist of console verbs instead of a block list (roadmap stage B, validator);
 *  - actor targets must be known: {npc:Name} must resolve, a bare hex RefID must be
 *    a known NPC (the Narrator once guessed Amren's RefID wrong);
 *  - mass spawns are capped, identical commands within 30 s are dropped as repeats;
 *  - every decision goes to public.tes_god_guard_log, which tes_god_journal shows
 *    to the Narrator ("ЗАБЛОКИРОВАНО: причина").
 *
 * Loaded by functions/functions.php (requireFunctionFilesRecursively) before the
 * core post-filter is appended, so this filter runs first.
 */

if (!function_exists('tesGodGuardValidate')) {
    function tesGodGuardEnsureTable(): void
    {
        if (!empty($GLOBALS['TES_GOD_GUARD_TABLE_OK'])) {
            return;
        }
        $GLOBALS['db']->execQuery("
            CREATE TABLE IF NOT EXISTS public.tes_god_guard_log (
                id bigserial PRIMARY KEY,
                created_at timestamptz NOT NULL DEFAULT now(),
                raw_text text NOT NULL,
                kept_text text NOT NULL DEFAULT '',
                verdict text NOT NULL,
                reasons text NOT NULL DEFAULT ''
            )
        ");
        // The log tables grow unbounded otherwise (journal reads only the last 30 minutes).
        // Prune rows older than a week; 1-in-20 requests to keep the cost negligible.
        if (random_int(1, 20) === 1) {
            $GLOBALS['db']->execQuery("DELETE FROM public.tes_god_guard_log WHERE created_at < now() - interval '7 days'");
        }
        $GLOBALS['TES_GOD_GUARD_TABLE_OK'] = true;
    }

    function tesGodGuardKnownNpc(string $name): bool
    {
        $db = $GLOBALS['db'];
        $n = $db->escape(trim($name));
        $row = $db->fetchOne("SELECT 1 AS ok FROM public.core_npc_master WHERE npc_name ILIKE '{$n}' OR npc_name ILIKE '{$n} %' OR npc_name ILIKE '{$n} [%' LIMIT 1");
        if (!empty($row['ok'])) {
            return true;
        }
        $row = $db->fetchOne("SELECT 1 AS ok FROM public.eventlog WHERE type = 'addnpc' AND (data ILIKE '{$n}@%' OR data ILIKE '{$n} %@%') LIMIT 1");
        return !empty($row['ok']);
    }

    // Titles change ("Кай" became "Командир Кай" once he was promoted in-game) while CHIM's
    // row is keyed by the current display name, so RelationshipManager::resolveNpcByName's
    // exact/in-range match can miss a bare given name. Falls back to "name is a word inside
    // the stored npc_name" (word-boundary, so "Кай" matches "Командир Кай" but not "Карлотта"),
    // and only when it picks out exactly one row.
    function tesGodGuardResolveNpcLoose(string $name)
    {
        $npc = RelationshipManager::resolveNpcByName($name);
        if ($npc) {
            return $npc;
        }
        $needle = mb_strtolower(trim($name));
        if ($needle === '') {
            return null;
        }
        // Word-level match done in PHP with \p{L} (Postgres ~* and PHP's \W are both
        // ASCII-only here, since the DB runs a C locale - Cyrillic bytes don't count as
        // "word" characters to them, so a DB-side word-boundary regex would silently
        // degrade to a substring match, e.g. "Карл" wrongly hitting "Карлотта").
        $rows = $GLOBALS['db']->fetchAll("SELECT * FROM public.core_npc_master WHERE npc_name <> 'The Narrator'");
        $hit = null;
        foreach (is_array($rows) ? $rows : [] as $row) {
            $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(strval($row['npc_name'])), -1, PREG_SPLIT_NO_EMPTY);
            if (in_array($needle, $words, true)) {
                if ($hit !== null) {
                    return null;  // ambiguous: more than one NPC has that word in their name
                }
                $hit = $row;
            }
        }
        return $hit;
    }

    // public.tes_game_index (tools/game_index.py + load_game_index.sh): every record of
    // the load order with its runtime FormID and in-game name.
    function tesGodGuardIndexReady(): bool
    {
        if (!isset($GLOBALS['TES_GAME_INDEX_READY'])) {
            $row = $GLOBALS['db']->fetchOne("SELECT to_regclass('public.tes_game_index') IS NOT NULL AS ok");
            $GLOBALS['TES_GAME_INDEX_READY'] = in_array($row['ok'] ?? '', [true, 't', 'true', 1, '1'], true);
        }
        return $GLOBALS['TES_GAME_INDEX_READY'];
    }

    // Index record of these kinds with exactly this name or EditorID (case-insensitive via
    // the precomputed *_lc keys: the DB's C locale lower() ignores Cyrillic). Duplicates
    // are usually mod copies, so the earliest FormID (the original) wins; for actors a
    // name shared by more than $maxHits people ("Стражник Вайтрана") is ambiguous -> ''.
    function tesGodGuardIndexUnique(string $name, array $kinds, string $column = 'formid', int $maxHits = 0): string
    {
        if (!tesGodGuardIndexReady()) {
            return '';
        }
        $db = $GLOBALS['db'];
        $n = $db->escape(mb_strtolower(trim($name)));
        $k = implode(',', array_map(function ($kind) use ($db) { return "'" . $db->escape($kind) . "'"; }, $kinds));
        $rows = $db->fetchAll("
            SELECT {$column} AS v FROM public.tes_game_index
            WHERE kind IN ({$k}) AND (name_lc = '{$n}' OR editor_id_lc = '{$n}')
            ORDER BY (editor_id_lc LIKE '%ench%'), formid
            LIMIT 20
        ");
        if (!is_array($rows) || empty($rows) || ($maxHits > 0 && count($rows) > $maxHits)) {
            return '';
        }
        return strval($rows[0]['v'] ?? '');
    }

    // {item:Name} -> FormID. The core resolver only knows English names from its item
    // descriptions and silently drops what it can't find (the Narrator then claimed
    // "the mace is in your hands"), so resolve everything here: Russian name/EditorID
    // from the index, English words against EditorIDs (skipping parts, replicas),
    // then the core resolver. '' = unknown.
    function tesGodGuardResolveItem(string $name, array $kinds = ['item']): string
    {
        $formId = tesGodGuardIndexUnique($name, $kinds);
        if ($formId !== '') {
            return $formId;
        }
        $db = $GLOBALS['db'];
        if (tesGodGuardIndexReady() && preg_match('/^[\x20-\x7E]+$/', $name)) {
            // Words under 3 chars ("f", "ab") are too short to trust as a stem - found live:
            // a bare "f" (from an unvalidated additem argument) matched an unrelated item
            // whose EditorID just happened to contain a standalone "f" token. Drop them
            // rather than let a near-empty query match almost anything.
            $words = array_values(array_diff(
                array_filter(preg_split('/[^a-z0-9]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY), function ($w) {
                    return strlen($w) >= 3;
                }),
                ['of', 'the', 'a', 'an', 's']
            ));
            if (!empty($words)) {
                // SQL narrows by 4-letter stems ("clothes" ~ Cloth), PHP then requires
                // every word to match a whole EditorID token (Ale must not hit CloakScale).
                $where = implode(' AND ', array_map(function ($w) use ($db) {
                    return "editor_id_lc LIKE '%" . $db->escape(substr($w, 0, 4)) . "%'";
                }, $words));
                $skip = array_diff(['hilt', 'scabbard', 'pommel', 'gem', 'blade', 'stone', 'replica',
                    'broken', 'fragment', 'dup', 'copy', 'test'], $words);
                $minor = array_diff(['head', 'feet', 'hand', 'hands', 'glove', 'gloves', 'boot', 'boots',
                    'helm', 'helmet', 'hood', 'circlet'], $words);
                $rows = $db->fetchAll("
                    SELECT formid, editor_id FROM public.tes_game_index
                    WHERE kind IN ('" . implode("','", $kinds) . "') AND {$where}
                      AND editor_id_lc !~ '(" . implode('|', $skip) . ")'
                    ORDER BY (editor_id_lc ~ '(" . implode('|', $minor) . ")'), length(editor_id), formid
                    LIMIT 200
                ");
                foreach (is_array($rows) ? $rows : [] as $row) {
                    $tokens = preg_split('/[^a-z0-9]+/', strtolower(preg_replace('/(?<=[a-z])(?=[A-Z])/', '_', strval($row['editor_id']))), -1, PREG_SPLIT_NO_EMPTY);
                    $all = true;
                    foreach ($words as $w) {
                        $hit = false;
                        foreach ($tokens as $t) {
                            if ($t === $w || (strlen($t) >= 4 && (str_starts_with($w, $t) || str_starts_with($t, $w)))) {
                                $hit = true;
                                break;
                            }
                        }
                        if (!$hit) {
                            $all = false;
                            break;
                        }
                    }
                    if ($all) {
                        return strval($row['formid']);
                    }
                }
            }
        }
        // Same idea as the English EditorID fuzzy match above, but for Russian display
        // names (name_lc, precomputed - this DB's C locale can't lower() Cyrillic itself).
        // Added 2026-09-29: the Narrator kept guessing plausible-sounding Russian item
        // phrases ("Одежда ярла", "Изысканная одежда") that don't exist verbatim, and the
        // exact-match-only path above just refused every time - a real item with a close
        // but not identical name (word order, an extra adjective, a case ending) had no
        // fallback at all, unlike English names.
        if (tesGodGuardIndexReady() && preg_match('/\p{Cyrillic}/u', $name)) {
            // Same length guard as the English fuzzy match above - a 1-2 letter stem is too
            // short to trust (found live: an unvalidated bare "f" matched an unrelated item).
            $words = array_values(array_diff(
                array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name), -1, PREG_SPLIT_NO_EMPTY), function ($w) {
                    return mb_strlen($w) >= 3;
                }),
                ['из', 'для', 'и', 'с', 'на', 'от', 'к']
            ));
            if (!empty($words)) {
                $where = implode(' AND ', array_map(function ($w) use ($db) {
                    return "name_lc LIKE '%" . $db->escape(mb_substr($w, 0, 4)) . "%'";
                }, $words));
                // Real risk found on review before this ever ran live: a lone word like
                // "одежда" also matches MCM config-toggle rows ("01 [+] Одежда ярлов и
                // управителей", editor_id CCF_OptionDisableJarlOutfits) - not a wearable
                // item at all. Excluding the "NN [x] " checklist-label pattern and
                // CCF_Option* editor IDs, the two concrete junk shapes found in this index.
                $rows = $db->fetchAll("
                    SELECT formid, name FROM public.tes_game_index
                    WHERE kind IN ('" . implode("','", $kinds) . "') AND {$where}
                      AND name !~ '^[0-9]+ \[.\] '
                      AND editor_id NOT LIKE 'CCF\\_Option%'
                    ORDER BY length(name), formid
                    LIMIT 200
                ");
                foreach (is_array($rows) ? $rows : [] as $row) {
                    $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(strval($row['name'])), -1, PREG_SPLIT_NO_EMPTY);
                    $all = true;
                    foreach ($words as $w) {
                        $hit = false;
                        foreach ($tokens as $t) {
                            if ($t === $w || (mb_strlen($t) >= 4 && mb_strlen($w) >= 4
                                && (str_starts_with($w, mb_substr($t, 0, 4)) || str_starts_with($t, mb_substr($w, 0, 4))))) {
                                $hit = true;
                                break;
                            }
                        }
                        if (!$hit) {
                            $all = false;
                            break;
                        }
                    }
                    if ($all) {
                        return strval($row['formid']);
                    }
                }
            }
        }
        if ($kinds === ['item'] && function_exists('herikaResolveSpawnItemDescriptionMatch')) {
            $item = herikaResolveSpawnItemDescriptionMatch($name);
            $formId = strtoupper(strval($item['runtime_formid'] ?? ''));
            if (preg_match('/^(0x)?[0-9A-F]{1,8}$/', $formId)) {
                return str_pad(preg_replace('/^0X/', '', $formId), 8, '0', STR_PAD_LEFT);
            }
        }
        return '';
    }

    // Words of a search request for the index: >= 3 chars, stopwords and short junk
    // dropped, longest first (found live: a bare "f" matched an unrelated item).
    function tesGodGuardIndexWords(string $name): array
    {
        $stop = ['из', 'для', 'и', 'с', 'на', 'от', 'к', 'the', 'a', 'an', 'of'];
        $words = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(trim($name)), -1, PREG_SPLIT_NO_EMPTY),
            function ($w) use ($stop) { return mb_strlen($w) >= 3 && !in_array($w, $stop, true); }
        ));
        usort($words, function ($a, $b) { return mb_strlen($b) <=> mb_strlen($a); });
        return $words;
    }

    // Closest real names for a failed lookup, so a refusal teaches instead of just refusing.
    // Live 2026-09-29: "деревянная_палка" / "Наряд Седобородых" / "Dovahkiin Tunic" each
    // burned a retry - the refusal said "назови точно, как в игре" but gave no way to see
    // what names actually exist. Matches whole request words (longest first, up to two
    // stems tried) against both the in-game name and the EditorID; display name wins.
    function tesGodGuardSuggestNames(string $name, array $kinds, int $limit = 3): array
    {
        if (!tesGodGuardIndexReady() || trim($name) === '') {
            return [];
        }
        $db = $GLOBALS['db'];
        $k = implode(',', array_map(function ($kind) use ($db) { return "'" . $db->escape($kind) . "'"; }, $kinds));
        $out = [];
        foreach (array_slice(tesGodGuardIndexWords($name), 0, 2) as $word) {
            $stem = $db->escape(mb_substr($word, 0, 6));
            $rows = $db->fetchAll("
                SELECT label FROM (
                    SELECT DISTINCT CASE WHEN name <> '' THEN name ELSE editor_id END AS label
                    FROM public.tes_game_index
                    WHERE kind IN ({$k}) AND (name_lc LIKE '%{$stem}%' OR editor_id_lc LIKE '%{$stem}%')
                ) s
                ORDER BY length(label), label LIMIT 10
            ");
            foreach (is_array($rows) ? $rows : [] as $row) {
                $label = trim(strval($row['label']));
                if ($label !== '' && !in_array($label, $out, true)) {
                    $out[] = $label;
                }
                if (count($out) >= $limit) {
                    return $out;
                }
            }
        }
        return $out;
    }

    // The same search, explicit ("find предмет мантия"), top 5: the Narrator can look an
    // exact name up instead of guessing it. For actors the CHIM table (people the player
    // actually met) is merged in - the index alone only knows static load-order records.
    function tesGodGuardFindNames(array $kinds, string $query, int $limit = 5): array
    {
        $out = tesGodGuardSuggestNames($query, $kinds, $limit);
        if (in_array('actor', $kinds, true)) {
            $db = $GLOBALS['db'];
            foreach (array_slice(tesGodGuardIndexWords($query), 0, 2) as $word) {
                $stem = $db->escape(mb_substr($word, 0, 6));
                $rows = $db->fetchAll("
                    SELECT npc_name FROM (
                        SELECT DISTINCT npc_name FROM public.core_npc_master
                        WHERE npc_name ILIKE '%{$stem}%' AND npc_name <> 'The Narrator'
                    ) s
                    ORDER BY length(npc_name) LIMIT 8
                ");
                foreach (is_array($rows) ? $rows : [] as $row) {
                    $label = trim(strval($row['npc_name']));
                    if ($label !== '' && !in_array($label, $out, true)) {
                        $out[] = $label;
                    }
                    if (count($out) >= $limit) {
                        break 2;
                    }
                }
            }
        }
        return $out;
    }

    // A found FormID's EditorID often has the shape "..._Body_<suffix>" in this modlist's
    // clothing (Requiem/RfaD split garments into separate body/feet/hands pieces) - returns
    // the FormIDs of the matching "_Feet_"/"_Hands_" siblings that also exist in the index,
    // so equipping "one item" doesn't leave the NPC visibly missing shoes/gloves. '' in,
    // [] out if there's no _Body_ piece or no siblings.
    function tesGodGuardFindClothingSiblings(string $formId): array
    {
        if (!tesGodGuardIndexReady()) {
            return [];
        }
        $db = $GLOBALS['db'];
        $row = $db->fetchOne("SELECT editor_id FROM public.tes_game_index WHERE formid = '" . $db->escape(strtoupper($formId)) . "' AND kind = 'item' LIMIT 1");
        $editorId = strval($row['editor_id'] ?? '');
        if ($editorId === '' || strpos($editorId, '_Body_') === false) {
            return [];
        }
        $siblings = [];
        foreach (['_Feet_', '_Hands_'] as $slot) {
            $candidate = str_replace('_Body_', $slot, $editorId);
            $sibRow = $db->fetchOne("SELECT formid FROM public.tes_game_index WHERE editor_id = '" . $db->escape($candidate) . "' AND kind = 'item' LIMIT 1");
            if ($sibRow) {
                $siblings[] = strval($sibRow['formid']);
            }
        }
        return $siblings;
    }

    // Server-side god commands that change CHIM's memory of an NPC, not the game world:
    //   {npc:Name}.character [personality|occupation|speechstyle|goals|appearance:] text
    //   {npc:Name}.relation <affinity -100..100> <type> [note]   (towards the player)
    // Returns [ok, message]; the message records "было → стало" for rollback.
    // A rumor in CHIM's rumors table for the player's current hold: every NPC of the hold
    // gets it in the prompt (<rumor>, up to 3 active) for $days game days.
    function tesGodGuardAddRumor(string $content, int $days = 14): string
    {
        $db = $GLOBALS['db'];
        $hold = function_exists('DataLastKnownCanonicalHoldHuman') ? trim(strval(DataLastKnownCanonicalHoldHuman(false))) : '';
        if ($hold === '') {
            $hold = 'Skyrim';
        }
        $gamets = intval($GLOBALS['gameRequest'][2] ?? 0);
        if ($gamets <= 0 && function_exists('DataLastKnownGameTS')) {
            $gamets = intval(DataLastKnownGameTS());
        }
        $db->insert('rumors', [
            'gamets' => $gamets,
            'ts' => time(),
            'hold' => $hold,
            'content' => mb_substr(trim($content), 0, 400),
            'type' => 'Local news',
            'rumor_length_days' => $days,
        ]);
        return $hold;
    }

    // Permanent memory: a "[Помнит]" block at the end of npc_static_bio (in every prompt of
    // this NPC as its background). Keeps the last 8 lines. Returns the new block.
    function tesGodGuardRemember(int $id, string $text): string
    {
        $db = $GLOBALS['db'];
        $row = $db->fetchOne("SELECT COALESCE(npc_static_bio, '') AS bio FROM public.core_npc_master WHERE id = {$id}");
        $bio = strval($row['bio'] ?? '');
        $marker = "\n\n[Помнит]\n";
        $pos = mb_strpos($bio, $marker);
        $base = $pos === false ? rtrim($bio) : mb_substr($bio, 0, $pos);
        $lines = $pos === false ? [] : array_values(array_filter(explode("\n", mb_substr($bio, $pos + mb_strlen($marker)))));
        $lines[] = '- ' . mb_substr(trim($text), 0, 300);
        $lines = tesGodGuardHygieneLines($lines);
        $lines = array_slice(array_values(array_unique($lines)), -8);
        $db->execQuery("UPDATE public.core_npc_master SET npc_static_bio = '" . $db->escape($base . $marker . implode("\n", $lines)) . "' WHERE id = {$id}");
        return implode(' / ', $lines);
    }

    // 2026-10-01: memory hygiene. .remember used to APPEND facts blindly; an NPC could
    // carry mutually exclusive self-facts at once ("жалкая попрошайка" + "сказочно
    // богата", "вернул юность" + "пожилая седая") - the model assembled a broken identity
    // out of all of them and rambled incoherently (Лилит Ткачиха, seen live, the player:
    // "бред несет нейро"). A new self-fact now DISPLACES the older lines it makes
    // obsolete, so the block stays a coherent "current self". Self-state pairs only:
    // third-party facts (Хеймскр убит) are reality-checked by the journal, not here.
    function tesGodGuardMemoryConflicts(): array
    {
        static $pairs = [
            // [trigger in the NEW line, drop older lines matching this]
            ['/богат|разбогат|шелк|казн|сокровищ/i', '/нищ|попрошайк|бос(ая|ой)?\b|голод|оборван|посинел|рван|нищенк|не было даже|нет даже/i'],
            ['/пожил|в годах|сед|не молод|стар(а|ая|ому|ым)?\b/i', '/юност|юность|молод(а|ая|ой|ого)?\b|вернул(а)?\s+(мне\s+)?юност/i'],
            ['/помогаю|помогать|хочу помогать/i', '/презира|ненавиж|презрени/i'],
            ['/имею дом|моя усадьб|мой дом|свой дом/i', '/живу на улице|без крова/i'],
        ];
        return $pairs;
    }

    // $lines: memory lines WITHOUT the leading dash, oldest first (newest = last).
    function tesGodGuardHygieneLines(array $lines): array
    {
        $n = count($lines);
        if ($n < 2) {
            return $lines;
        }
        $newest = $lines[$n - 1];
        $kept = [];
        for ($i = 0; $i < $n - 1; $i++) {
            foreach (tesGodGuardMemoryConflicts() as [$trigger, $obsolete]) {
                if (preg_match($trigger, $newest) && preg_match($obsolete, $lines[$i])) {
                    continue 2; // the new fact makes this older line obsolete
                }
            }
            $kept[] = $lines[$i];
        }
        $kept[] = $newest;
        return $kept;
    }

    function tesGodGuardSetRelation(array $npc, string $target, int $aff, string $type, string $note): void
    {
        RelationshipManager::setRelationship(strval($npc['npc_name']), $target, $aff, $type);
        if ($note !== '') {
            $db = $GLOBALS['db'];
            $db->execQuery("UPDATE public.core_npc_master SET extended_data = jsonb_set(extended_data, ARRAY['relationships', '" . $db->escape($target) . "', 'note'], to_jsonb('" . $db->escape(mb_substr($note, 0, 200)) . "'::text), true) WHERE id = " . intval($npc['id']));
        }
    }

    // One marriage per person: tes_world_facts keeps spouse(A)=B. Marrying A to B makes the
    // previous spouses exes (relation + memory), then sets the couple, memories and a rumor.
    function tesGodGuardMarry(array $a, array $b): string
    {
        $db = $GLOBALS['db'];
        $db->execQuery("
            CREATE TABLE IF NOT EXISTS public.tes_world_facts (
                subject text NOT NULL,
                predicate text NOT NULL,
                object text NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (subject, predicate)
            )
        ");
        $an = strval($a['npc_name']);
        $bn = strval($b['npc_name']);
        $notes = [];
        foreach ([[$an, $bn], [$bn, $an]] as [$who, $newSpouse]) {
            $w = $db->escape($who);
            $prev = $db->fetchOne("SELECT object FROM public.tes_world_facts WHERE subject = '{$w}' AND predicate = 'spouse'");
            $prevName = strval($prev['object'] ?? '');
            if ($prevName !== '' && $prevName !== $newSpouse) {
                $prevNpc = RelationshipManager::resolveNpcByName($prevName);
                $whoNpc = RelationshipManager::resolveNpcByName($who);
                if ($prevNpc && $whoNpc) {
                    tesGodGuardSetRelation($prevNpc, $who, 10, 'ex', "брак распался: {$who} теперь с {$newSpouse}");
                    tesGodGuardSetRelation($whoNpc, $prevName, 10, 'ex', 'бывший супруг');
                    tesGodGuardRemember(intval($prevNpc['id']), "Брак с {$who} распался: теперь {$who} в браке с {$newSpouse}.");
                }
                $db->execQuery("DELETE FROM public.tes_world_facts WHERE subject = '" . $db->escape($prevName) . "' AND predicate = 'spouse'");
                $notes[] = "{$prevName} теперь бывший супруг {$who}";
            }
        }
        // Any other romance of either spouse, in both directions, is over now.
        foreach ([[$an, $bn], [$bn, $an]] as [$who, $spouse]) {
            $w = $db->escape($who);
            $s = $db->escape($spouse);
            $mine = $db->fetchOne("SELECT extended_data->'relationships' AS r FROM public.core_npc_master WHERE npc_name = '{$w}' LIMIT 1");
            foreach ((array) json_decode(strval($mine['r'] ?? '{}'), true) as $other => $rel) {
                if ($other === $spouse || $other === 'Player' || !in_array(strval($rel['type'] ?? ''), ['romantic', 'crush', 'obsessed'], true)) {
                    continue;
                }
                $whoNpc = RelationshipManager::resolveNpcByName($who);
                if ($whoNpc) {
                    tesGodGuardSetRelation($whoNpc, $other, 10, 'ex', "в прошлом; теперь в браке с {$spouse}");
                    $notes[] = "{$who} больше не влюблён(а) в {$other}";
                }
            }
            $admirers = $db->fetchAll("
                SELECT id, npc_name FROM public.core_npc_master
                WHERE npc_name <> '{$s}' AND extended_data->'relationships'->'{$w}'->>'type' IN ('romantic', 'crush', 'obsessed')
            ");
            foreach (is_array($admirers) ? $admirers : [] as $admirer) {
                tesGodGuardSetRelation($admirer, $who, 10, 'ex', "{$who} теперь в браке с {$spouse}");
                tesGodGuardRemember(intval($admirer['id']), "{$who} женился/вышла замуж за {$spouse}; между нами всё кончено.");
                $notes[] = "{$admirer['npc_name']} знает, что {$who} теперь в браке";
            }
        }
        foreach ([[$a, $bn], [$b, $an]] as [$npc, $spouse]) {
            tesGodGuardSetRelation($npc, $spouse, 90, 'romantic', 'супруги');
            tesGodGuardRemember(intval($npc['id']), "В браке с {$spouse}: недавно поженились, живём вместе.");
            $db->execQuery("
                INSERT INTO public.tes_world_facts (subject, predicate, object) VALUES ('" . $db->escape(strval($npc['npc_name'])) . "', 'spouse', '" . $db->escape($spouse) . "')
                ON CONFLICT (subject, predicate) DO UPDATE SET object = EXCLUDED.object, created_at = now()
            ");
        }
        $hold = tesGodGuardAddRumor("Говорят, {$an} и {$bn} поженились.");
        return "{$an} и {$bn} теперь супруги (любовь, память, слух по холду {$hold})" . ($notes ? '; ' . implode('; ', $notes) : '');
    }

    // Server commands (character/relation/remember/marry) need an existing CHIM profile
    // row, with no in-game fallback (unlike console commands, which can fall back to
    // {near:Name}). A flat "not in CHIM" refusal doesn't tell the Narrator anything it can
    // act on - "как он поймёт, чего не хватает?" this distinguishes:
    //   - a real NPC of the load order who just hasn't talked to the player yet -> the
    //     concrete, actionable fix is to greet them in game first;
    //   - a name that matches nobody at all -> the fix is a different, exact name.
    function tesGodGuardWhyNoProfile(string $name): string
    {
        if (tesGodGuardIndexUnique($name, ['actor'], 'formid', 3) !== '' || tesGodGuardKnownNpc($name)) {
            return "«{$name}» есть в игре, но ещё ни разу не говорил(а) с игроком — сперва подойди и поздоровайся с ним/ней, потом это сработает";
        }
        $similar = tesGodGuardFindNames(['actor'], $name);
        return "«{$name}»: нет такого персонажа — назови точно, как его зовут в игре"
            . ($similar ? '; похожие имена: ' . implode(', ', $similar) : '');
    }

    function tesGodGuardRunServer(array $cmd): array
    {
        $db = $GLOBALS['db'];
        if ($cmd['verb'] === 'rumor') {
            if (mb_strlen($cmd['args']) < 10) {
                return [false, 'слух слишком короткий'];
            }
            $hold = tesGodGuardAddRumor($cmd['args']);
            return [true, "по холду {$hold} пошёл слух: «" . mb_substr($cmd['args'], 0, 120) . "»"];
        }
        $who = $cmd['npc'];
        if (preg_match('/^[0-9A-Fa-f]{8}$/', $who)) {
            $r = $db->escape(strtoupper($who));
            $row = $db->fetchOne("SELECT npc_name FROM public.core_npc_master WHERE upper(refid) = '{$r}' LIMIT 1");
            $who = strval($row['npc_name'] ?? $who);
        }
        if (!class_exists('RelationshipManager')) {
            $lib = dirname(__DIR__, 2) . '/lib/relationship_manager.php';
            require_once file_exists($lib) ? $lib : '/var/www/html/HerikaServer/lib/relationship_manager.php';
        }
        $npc = tesGodGuardResolveNpcLoose($who);
        if (!$npc) {
            return [false, tesGodGuardWhyNoProfile($who)];
        }
        $name = strval($npc['npc_name']);
        $id = intval($npc['id']);

        if ($cmd['verb'] === 'remember') {
            if (mb_strlen($cmd['args']) < 5) {
                return [false, "«{$name}»: пустое воспоминание"];
            }
            return [true, "{$name} теперь помнит: " . mb_substr(tesGodGuardRemember($id, $cmd['args']), -300)];
        }
        if ($cmd['verb'] === 'marry') {
            $other = tesGodGuardResolveNpcLoose(trim($cmd['args']));
            if (!$other) {
                return [false, tesGodGuardWhyNoProfile(trim($cmd['args']))];
            }
            if (intval($other['id']) === $id) {
                return [false, "«{$name}»: нельзя жениться на себе"];
            }
            return [true, tesGodGuardMarry($npc, $other)];
        }

        if ($cmd['verb'] === 'character') {
            $field = 'personality';
            $text = $cmd['args'];
            if (preg_match('/^(personality|occupation|speechstyle|goals|appearance)\s*:\s*(.+)$/isu', $text, $m)) {
                $field = strtolower($m[1]);
                $text = trim($m[2]);
            }
            if (mb_strlen($text) < 3) {
                return [false, "«{$name}»: пустое описание для {$field}"];
            }
            $old = mb_substr(trim(strval($npc[$field] ?? '')), 0, 120);
            $db->execQuery("UPDATE public.core_npc_master SET {$field} = '" . $db->escape($text) . "' WHERE id = {$id}");
            $news = '';
            if ($field === 'occupation') {
                // Family and neighbours should hear about it (the son didn't know his father got rich).
                $hold = tesGodGuardAddRumor("Говорят, {$name} теперь {$text}.");
                $news = "; по холду {$hold} пошёл слух";
            }
            return [true, "{$name}: {$field} было «{$old}» → стало «" . mb_substr($text, 0, 120) . "»{$news}"];
        }

        // relation [to <Name>] <aff> <type> [note] - towards the player unless "to <Name>"
        // (without it the Narrator once wrote "in love with Хельга" into the player slot).
        if (!preg_match('/^(?:(?:to|к)\s+(.+?)\s+)?(-?\d{1,3})\s+([a-z_]+)\s*(.*)$/isu', $cmd['args'], $m)) {
            return [false, "«{$name}»: relation ждёт «[to Имя] число тип заметка», например relation to Хельга 80 romantic любит её"];
        }
        // The cheatsheet promises -100..100, but nothing stopped "relation 500 obsessed"
        // from writing an out-of-range affinity into CHIM (only the display clamps).
        $aff = max(-100, min(100, intval($m[2])));
        if ($aff !== intval($m[2])) {
            $cmd['args'] = preg_replace('/' . preg_quote($m[2], '/') . '/', strval($aff), $cmd['args'], 1);
        }
        $toName = trim($m[1]);
        $target = 'Player';
        $targetLabel = 'игроку';
        if ($toName !== '' && RelationshipManager::normalizeTargetName($toName) !== 'Player') {
            $other = tesGodGuardResolveNpcLoose($toName);
            if (!$other) {
                return [false, tesGodGuardWhyNoProfile($toName)];
            }
            $target = strval($other['npc_name']);
            $targetLabel = $target;
        }
        $before = RelationshipManager::getRelationship($name, $target);
        $oldText = is_array($before) ? (($before['aff'] ?? '?') . ' ' . ($before['type'] ?? '?') . ' «' . ($before['note'] ?? '') . '»') : 'нет';
        if (!RelationshipManager::setRelationship($name, $target, $aff, strtolower($m[3]))) {
            return [false, "«{$name}»: CHIM не принял изменение отношения"];
        }
        $note = trim($m[4]);
        if ($note !== '') {
            $db->execQuery("UPDATE public.core_npc_master SET extended_data = jsonb_set(extended_data, ARRAY['relationships', '" . $db->escape($target) . "', 'note'], to_jsonb('" . $db->escape(mb_substr($note, 0, 200)) . "'::text), true) WHERE id = {$id}");
        }
        $after = RelationshipManager::getRelationship($name, $target);
        $newText = is_array($after) ? (($after['aff'] ?? '?') . ' ' . ($after['type'] ?? '?') . ' «' . ($after['note'] ?? '') . '»') : '?';
        return [true, "{$name}: отношение к {$targetLabel} было {$oldText} → стало {$newText}"];
    }

    // "equipitem <HEX>" for an NPC -> "tesdress <signed decimal>" (bridge: EquipItem with
    // prevent-removal; Papyrus has no hex parsing). Anything else is returned unchanged.
    function tesGodGuardDressBody(string $body): string
    {
        if (!preg_match('/^equipitem\s+([0-9A-Fa-f]{8})\s*$/', $body, $m)) {
            return $body;
        }
        $dec = hexdec($m[1]);
        return 'tesdress ' . ($dec > 0x7FFFFFFF ? $dec - 4294967296 : $dec);
    }

    function tesGodGuardKnownRefId(string $refId): bool
    {
        $db = $GLOBALS['db'];
        $r = $db->escape(strtoupper($refId));
        if (tesGodGuardIndexReady()) {
            $row = $db->fetchOne("SELECT 1 AS ok FROM public.tes_game_index WHERE formid = '{$r}' AND kind = 'actor' LIMIT 1");            if (!empty($row['ok'])) {
                return true;
            }
        }
        $row = $db->fetchOne("SELECT 1 AS ok FROM public.core_npc_master WHERE upper(refid) = '{$r}' LIMIT 1");
        if (!empty($row['ok'])) {
            return true;
        }
        $row = $db->fetchOne("SELECT 1 AS ok FROM public.eventlog WHERE type = 'addnpc' AND upper(data) LIKE '%@{$r}@%' LIMIT 1");
        return !empty($row['ok']);
    }

    /**
     * @return array{kept: string[], reasons: string[]}
     */
    function tesGodGuardValidate(string $text): array
    {
        // Safe verbs (the God_Command cheat sheet in settings/chim_settings.sql plus
        // close relatives). Anything else is refused with a reason.
        $allowed = [
            'resurrect', 'kill', 'restoreav', 'modav', 'setav', 'forceav', 'additem', 'removeitem',
            'equipitem', 'unequipitem', 'addspell', 'removespell', 'addperk', 'fw', 'sw', 'set',
            'advlevel', 'incpcs', 'tgm', 'setrelationshiprank', 'stopcombat', 'setscale', 'moveto',
            'placeatme', 'addfac', 'removefac', 'setplayerteammate', 'recycleactor', 'evp', 'resetai',
            'setessential', 'pushactoraway', 'setlevel', 'coc', 'sgtm', 'setownership', 'unequipall', 'tesroutine', 'tesheal', 'heal',
        ];
        $refused = [
            'disable' => 'disable/enable ломает модель NPC',
            'enable' => 'disable/enable ломает модель NPC',
            'markfordelete' => 'удаление объектов запрещено',
            'delete' => 'удаление объектов запрещено',
            'killall' => 'массовое убийство запрещено',
            'setstage' => 'стадии квестов меняются только с подтверждения игрока',
            'completequest' => 'стадии квестов меняются только с подтверждения игрока',
            'resetquest' => 'стадии квестов меняются только с подтверждения игрока',
            'caqs' => 'стадии квестов меняются только с подтверждения игрока',
        ];

        $kept = [];
        $nearby = [];
        $server = [];
        $scriptproxy = [];
        $searches = [];
        $reasons = [];
        foreach (preg_split('/[;\n]+/u', $text) as $command) {
            $command = trim($command);
            if ($command === '') {
                continue;
            }
            $target = '';
            $body = $command;
            // Accept a "0x" prefix on a bare RefID (the Narrator uses both forms) - strip it
            // right away so every check below sees the plain 8-hex-digit form.
            $command = preg_replace('/\b0[xX]([0-9A-Fa-f]{8})\b/', '$1', $command);
            if (preg_match('/^(\{(?:npc|near):[^}]+\}|[0-9A-Fa-f]{8}|player)\s*\.\s*(.+)$/iu', $command, $m)) {
                $target = $m[1];
                $body = trim($m[2]);
            }
            // The Narrator sometimes writes {npc:<player's own character name>} instead of
            // "player" (seen live: {npc:Шаман}.character/.additem/.equipitem, where "Шаман"
            // is PLAYER_NAME, not a real NPC) - core_npc_master has no such row, so this used
            // to fail as "unknown character" or silently fall back to the less reliable
            // {near:} in-game name search instead of the direct "player" path. Substitute it
            // before any of that runs.
            $playerName = trim(strval($GLOBALS['PLAYER_NAME'] ?? ''));
            if ($playerName !== '' && preg_match('/^\{npc:([^}]+)\}$/iu', $target, $pm) && mb_strtolower(trim($pm[1])) === mb_strtolower($playerName)) {
                $target = 'player';
                $command = 'player.' . $body;
            }
            // find: explicit name search ("find предмет мантия", "find персонаж Амрен") so
            // the Narrator can look exact names up instead of guessing them. Runs entirely
            // server-side; results go to the god journal (verdict 'search') and are visible
            // on the Narrator's NEXT turn - the command itself never reaches the game.
            if (preg_match('/^(?:find|найди|поиск)\s+(.+)$/isu', $body, $fm)) {
                $kindMap = [
                    'item' => [['item'], 'предмет'], 'предмет' => [['item'], 'предмет'], 'вещь' => [['item'], 'предмет'],
                    'spell' => [['spell'], 'заклинание'], 'заклинание' => [['spell'], 'заклинание'],
                    'perk' => [['perk'], 'способность'], 'способность' => [['perk'], 'способность'],
                    'npc' => [['actor'], 'персонаж'], 'actor' => [['actor'], 'персонаж'],
                    'персонаж' => [['actor'], 'персонаж'], 'кто' => [['actor'], 'персонаж'],
                    'faction' => [['faction'], 'фракция'], 'фракция' => [['faction'], 'фракция'],
                    'place' => [['cell', 'location', 'world'], 'место'], 'cell' => [['cell'], 'место'],
                    'место' => [['cell', 'location', 'world'], 'место'], 'город' => [['cell', 'location', 'world'], 'место'],
                    'локация' => [['cell', 'location', 'world'], 'место'],
                    'существо' => [['npc', 'leveled_npc'], 'существо'], 'creature' => [['npc', 'leveled_npc'], 'существо'],
                ];
                $rest = trim($fm[1]);
                $kinds = ['item'];
                $kindRu = 'предмет';
                if (preg_match('/^(\S+)\s+(.+)$/su', $rest, $km) && isset($kindMap[mb_strtolower(trim($km[1]))])) {
                    $map = $kindMap[mb_strtolower(trim($km[1]))];
                    $kinds = $map[0];
                    $kindRu = $map[1];
                    $rest = trim($km[2]);
                }
                $searches[] = ['kind' => $kindRu, 'query' => $rest, 'result' => tesGodGuardFindNames($kinds, $rest)];
                continue;
            }
            // {cell:Name} -> cell EditorID (for coc); {item:Name} -> FormID (see above);
            // {spell:Name} -> FormID (roadmap B validator: additem/addspell should be
            // checked against the index like equipitem already is, not passed through
            // blind - added 2026-09-29, addspell/removespell had no resolution at all
            // before this, unlike additem/equipitem which already went through {item:}).
            // {ench:Name} resolves too (below), but review found there is NO console command
            // or ScriptProxy call that actually applies an enchantment to anything -
            // SetEnchantment only exists in SKSE (Armor/Weapon/ObjectReference/WornObject),
            // not in AIAgentScriptProxy.psc - so any command using it is refused outright,
            // regardless of whether the name resolves, right after this block.
            $hadEnch = (bool) preg_match('/\{ench:/i', $body);
            $unresolved = '';
            $unresolvedKind = '';
            $unresolvedName = '';
            $body = preg_replace_callback('/\{(cell|item|spawn|spell|perk|ench|faction):([^}]+)\}/iu', function ($m) use (&$unresolved, &$unresolvedKind, &$unresolvedName) {
                $kind = strtolower($m[1]);
                $what = trim($m[2]);
                if ($kind === 'spawn' && in_array(strtolower($what), ['bandit', 'mage', 'archer', 'boss'], true)) {
                    return $m[0];  // the core's own spawn table
                }
                if ($kind === 'cell') {
                    $value = tesGodGuardIndexUnique($what, ['cell'], 'editor_id');
                    if ($value === '') {
                        // A city/world/location name (Рифтен): its "<Name>Origin" or "<Name>" cell.
                        $n = $GLOBALS['db']->escape(mb_strtolower($what));
                        $places = tesGodGuardIndexReady() ? $GLOBALS['db']->fetchAll("
                            SELECT editor_id FROM public.tes_game_index
                            WHERE kind IN ('world', 'location') AND name_lc = '{$n}'
                            ORDER BY (kind = 'world') DESC, formid LIMIT 10") : [];
                        foreach (is_array($places) ? $places : [] as $place) {
                            $base = preg_replace('/(World|Location)$/', '', strval($place['editor_id']));
                            foreach ([$base . 'Origin', $base] as $candidate) {
                                if ($base !== '' && tesGodGuardIndexUnique($candidate, ['cell'], 'editor_id') !== '') {
                                    $value = $candidate;
                                    break 2;
                                }
                            }
                        }
                    }
                } elseif ($kind === 'spawn') {
                    $value = tesGodGuardResolveItem($what, ['npc', 'leveled_npc']);
                    if ($value !== '' && tesGodGuardIndexReady()) {
                        // A unique person (placed once in the world): placeatme makes a clone
                        // (the Narrator once summoned a second Хельга from Riften).
                        $v = $GLOBALS['db']->escape($value);
                        $placed = $GLOBALS['db']->fetchOne("SELECT count(*) AS n FROM public.tes_game_index WHERE kind = 'actor' AND extra->>'base' = '{$v}'");
                        if (intval($placed['n'] ?? 0) === 1) {
                            $unresolved = "существа «{$what}»: это уникальный персонаж, призыв сделает его клона. Самого — {npc:{$what}}.moveto player, нового человека — Create_New_NPC";
                            return $m[0];
                        }
                    }
                } elseif ($kind === 'spell') {
                    $value = tesGodGuardResolveItem($what, ['spell']);
                } elseif ($kind === 'perk') {
                    $value = tesGodGuardResolveItem($what, ['perk']);
                } elseif ($kind === 'ench') {
                    // Resolves the base enchantment record's FormID (e.g. for a ScriptProxy
                    // command that takes one directly) - not to be confused with an
                    // already-enchanted {item:Name}.
                    $value = tesGodGuardResolveItem($what, ['enchantment']);
                } elseif ($kind === 'faction') {
                    // addfac/removefac had no resolution at all before this - only a raw hex
                    // FormID the Narrator would have to already know.
                    $value = tesGodGuardResolveItem($what, ['faction']);
                } else {
                    $value = tesGodGuardResolveItem($what);
                }
                if ($value === '' && $unresolved === '') {
                    $unresolvedKind = $kind;
                    $unresolvedName = $what;
                    $unresolved = ['cell' => 'места', 'item' => 'предмета', 'spawn' => 'существа', 'spell' => 'заклинания', 'perk' => 'способности', 'ench' => 'зачарования', 'faction' => 'фракции'][$kind] . ' «' . $what . '»';
                }
                return $value !== '' ? $value : $m[0];
            }, $body) ?? $body;
            if ($unresolved !== '') {
                if (strpos($unresolved, 'уникальный') !== false) {
                    $reasons[] = "«{$command}»: {$unresolved}";
                } else {
                    // Teach instead of just refusing: the closest real names from the index,
                    // plus how to search properly (live 2026-09-29: three blind retries in a
                    // row on invented item names - each of these hints would have saved it).
                    $kindInfo = [
                        'cell' => [['cell'], 'место'], 'item' => [['item'], 'предмет'],
                        'spawn' => [['npc', 'leveled_npc'], 'существо'], 'spell' => [['spell'], 'заклинание'],
                        'perk' => [['perk'], 'способность'], 'faction' => [['faction'], 'фракция'],
                    ][$unresolvedKind] ?? [['item'], 'предмет'];
                    $suggestions = tesGodGuardSuggestNames($unresolvedName, $kindInfo[0]);
                    $hint = ($suggestions ? '; похожие: ' . implode(', ', $suggestions) : ' — назови точно, как в игре (по-русски)')
                        . "; или сначала найди: find {$kindInfo[1]} {$unresolvedName}";
                    $reasons[] = "«{$command}»: не знаю {$unresolved}{$hint}";
                }
                continue;
            }
            if ($hadEnch) {
                $reasons[] = "«{$command}»: зачарование само по себе никуда не накладывается — нет консольной команды или ScriptProxy для этого, нужен новый Papyrus-мост (не сделано)";
                continue;
            }
            // Accept a "0x" prefix inside the argument too ("player.moveto 0x0001B058") -
            // strip it here, before $command/$verb are derived from $body.
            $body = preg_replace('/\b0[xX]([0-9A-Fa-f]{8})\b/', '$1', $body);
            $command = ($target !== '' ? $target . '.' : '') . $body;
            $verb = strtolower(strval(preg_split('/\s+/', $body)[0] ?? ''));

            // additem/removeitem/addspell/removespell/addperk with a RAW argument that is
            // neither an already-resolved 8-hex FormID nor came through {item:}/{spell:}/
            // {perk:} above had NO validation at all - found live: "additem f 1000" passed
            // straight through unchanged ("f" is not a real item). Try resolving the raw
            // word as a name (same resolver {item:}/etc. already use); refuse with the same
            // honest reason if it doesn't resolve, instead of passing garbage to the console.
            $rawArgKinds = ['additem' => 'item', 'removeitem' => 'item', 'addspell' => 'spell',
                'removespell' => 'spell', 'addperk' => 'perk'];
            if (isset($rawArgKinds[$verb])) {
                $argPattern = $verb === 'additem' || $verb === 'removeitem'
                    ? '/^' . $verb . '\s+(.+?)(\s+\d+)?\s*$/i'
                    : '/^' . $verb . '\s+(.+?)\s*$/i';
                if (preg_match($argPattern, $body, $am) && !preg_match('/^[0-9A-Fa-f]{8}$/', $am[1])) {
                    $resolved = tesGodGuardResolveItem($am[1], [$rawArgKinds[$verb]]);
                    if ($resolved === '') {
                        $kindLabel = ['item' => 'предмета', 'spell' => 'заклинания', 'perk' => 'способности'][$rawArgKinds[$verb]];
                        $kindWord = ['item' => 'предмет', 'spell' => 'заклинание', 'perk' => 'способность'][$rawArgKinds[$verb]];
                        $suggestions = tesGodGuardSuggestNames($am[1], [$rawArgKinds[$verb]]);
                        $hint = ($suggestions ? '; похожие: ' . implode(', ', $suggestions) : '')
                            . "; или сначала найди: find {$kindWord} {$am[1]}";
                        $reasons[] = "«{$command}»: не знаю {$kindLabel} «{$am[1]}» — назови точно, как в игре, или через {item:Имя}/{spell:Имя}/{perk:Имя}{$hint}";
                        continue;
                    }
                    $body = str_replace($am[1], $resolved, $body);
                    $command = ($target !== '' ? $target . '.' : '') . $body;
                }
            }

            // heal: full health/magicka/stamina restore, revive from bleedout, cure disease
            // (bridge tesheal, acts on the console's selected reference). Works on the
            // player too: the core (herikaQueueGodCommands) turns "RefID.cmd" text into a
            // proper ["prid RefID", cmd] sequence, but only for an 8-hex-digit RefID, not
            // the word "player" - substitute 00000014, the game engine's own constant
            // FormID for the player reference (not a guess: it is fixed by the engine,
            // the same in every Skyrim installation), so that path applies here too.
            if ($verb === 'heal') {
                $body = 'tesheal';
                $healTarget = strtolower($target) === 'player' ? '00000014' : $target;
                $command = ($healTarget !== '' ? $healTarget . '.' : '') . $body;
            }
            // outfit <style>: DISABLED 2026-09-29 - confirmed in game (Лилит Ткачиха) that
            // Actor.SetOutfit() only changes the ActorBase's DEFAULT outfit, it does not
            // force an immediate re-equip. Combined with unequipall (the documented order),
            // this left the NPC naked for the rest of the session - not a one-off, tried
            // three times with the same result. Refused outright until this has a real fix
            // (needs enumerating an OTFT record's contained items and force-equipping them,
            // not currently indexed) rather than left silently broken. equipitem still works.
            if ($verb === 'outfit') {
                $reasons[] = "«{$command}»: outfit сейчас сломан (раздевает NPC и не одевает обратно, подтверждено на Лилит) — используй equipitem {item:...} для конкретной вещи";
                continue;
            }
            // routine here|reset: a new daily life around the player's current spot (bridge
            // tesroutine: marker + CHIM's sandbox package above the NPC's own schedule).
            if ($verb === 'routine') {
                if ($target === '' || strtolower($target) === 'player') {
                    $reasons[] = "«{$command}»: routine только для NPC: {npc:Имя}.routine here";
                    continue;
                }
                $mode = preg_match('/^routine\s+(reset|home|old|прежн|вернуть)/iu', $body) ? 'reset' : 'here';
                $body = 'tesroutine ' . $mode;
                $command = $target . '.' . $body;
                $verb = 'tesroutine';
            }
            // unsummon: remove a person/creature created during play (clone, summon). The
            // bridge refuses anything that is part of the game data (FormID not FFxxxxxx).
            if ($verb === 'unsummon') {
                if (!preg_match('/^\{(?:npc|near):([^}]+)\}$/iu', $target, $m)) {
                    $reasons[] = "«{$command}»: unsummon только так: {near:Имя}.unsummon";
                    continue;
                }
                $nearby[] = ['name' => trim($m[1]), 'body' => 'tesremove'];
                continue;
            }
            if ($verb === 'moveto' && !preg_match('/^moveto\s+(player|[0-9A-Fa-f]{8}|\{npc:[^}]+\})\s*$/iu', $body)) {
                $reasons[] = "«{$command}»: moveto — только к игроку или персонажу; убрать призванного — {near:Имя}.unsummon, самого игрока перенести — coc {cell:Место}";
                continue;
            }
            if ($verb === 'rumor' && $target === '') {
                $server[] = ['npc' => '', 'verb' => 'rumor', 'args' => trim(mb_substr($body, 5))];
                continue;
            }
            if (in_array($verb, ['character', 'relation', 'remember', 'marry'], true)) {
                if (preg_match('/^\{npc:([^}]+)\}$/iu', $target, $m)) {
                    $who = trim($m[1]);
                } elseif (preg_match('/^[0-9A-Fa-f]{8}$/', $target)) {
                    $who = $target;
                } else {
                    $reasons[] = "«{$command}»: {$verb} только для персонажа: {npc:Имя}.{$verb} …";
                    continue;
                }
                $server[] = ['npc' => $who, 'verb' => $verb, 'args' => trim(mb_substr($body, strlen($verb)))];
                continue;
            }

            if (isset($refused[$verb])) {
                $reasons[] = "«{$command}»: {$refused[$verb]}";
                continue;
            }
            if (!in_array($verb, $allowed, true)) {
                $reasons[] = "«{$command}»: команды «{$verb}» нет в списке разрешённых";
                continue;
            }
            if ($verb === 'set' && !preg_match('/^set\s+(gamehour|timescale)\s+to\s+\d+(\.\d+)?$/i', $body)) {
                $reasons[] = "«{$command}»: через set можно менять только gamehour и timescale";
                continue;
            }
            if ($verb === 'sgtm' && (!preg_match('/^sgtm\s+(\d+(\.\d+)?)$/', $body, $sm) || floatval($sm[1]) < 0.2 || floatval($sm[1]) > 3)) {
                $reasons[] = "«{$command}»: замедление времени только от 0.2 до 3 (sgtm 1 — норма)";
                continue;
            }
            if ($verb === 'coc' && ($target !== '' || !preg_match('/^coc\s+[A-Za-z0-9_]+$/', $body))) {
                $reasons[] = "«{$command}»: телепорт только как coc {cell:Название места}";
                continue;
            }
            // {near:Name}: the nearest actor with that display name, found in game.
            if (preg_match('/^\{near:([^}]+)\}$/iu', $target, $m)) {
                if (strpos($body, '{') !== false) {
                    $reasons[] = "«{$command}»: для {near:…} можно только команды без {…}";
                    continue;
                }
                $nearby[] = ['name' => trim($m[1]), 'body' => tesGodGuardDressBody($body)];
                continue;
            }
            // Not in CHIM's NPC table, but a unique named actor of the load order:
            // use its real RefID (the core sends "RefID.cmd" as prid + cmd).
            if (preg_match('/^\{npc:([^}]+)\}$/iu', $target, $m) && !tesGodGuardKnownNpc($m[1])) {
                $indexRef = tesGodGuardIndexUnique($m[1], ['actor'], 'formid', 3);
                if ($indexRef !== '') {
                    $target = $indexRef;
                    $command = $target . '.' . $body;
                }
            }
            if (preg_match('/^\{npc:([^}]+)\}$/iu', $target, $m) && !tesGodGuardKnownNpc($m[1])) {
                // Unknown to the server (never talked to the player), but maybe standing
                // nearby: the TESGodConsoleReport bridge finds actors by display name
                // in game ("tesnear <Name>"). Placeholders can't be resolved on that path.
                if (strpos($body, '{') !== false) {
                    $reasons[] = "«{$command}»: для NPC, которого сервер не знает, можно только команды без {…}";
                    continue;
                }
                $nearby[] = ['name' => trim($m[1]), 'body' => tesGodGuardDressBody($body)];
                continue;
            }
            if (preg_match('/^[0-9A-Fa-f]{8}$/', $target) && !tesGodGuardKnownRefId($target)) {
                $reasons[] = "«{$command}»: неизвестный RefID {$target} — пиши {npc:Имя}";
                continue;
            }
            if ($verb === 'placeatme' && preg_match('/^(placeatme\s+\S+)\s+(\d+)/i', $body, $m) && intval($m[2]) > 10) {
                $body = $m[1] . ' 10';
                $command = ($target !== '' ? $target . '.' : '') . $body;
                $reasons[] = "«{$command}»: урезано до 10, больше за раз нельзя";
            }
            // additem/removeitem had no quantity cap at all, unlike placeatme - a typo'd or
            // deliberately absurd count (player.additem {item:Gold001} 999999999) went
            // straight through. Owner picked 5000 as the ceiling (2026-09-29).
            if (in_array($verb, ['additem', 'removeitem'], true)
                && preg_match('/^(' . $verb . '\s+\S+)\s+(\d+)/i', $body, $m) && intval($m[2]) > 5000) {
                $body = $m[1] . ' 5000';
                $command = ($target !== '' ? $target . '.' : '') . $body;
                $reasons[] = "«{$command}»: урезано до 5000, больше за раз нельзя";
            }
            // Console equipitem on an NPC doesn't stick (they switch back to their outfit).
            // Prefer CHIM's own ScriptProxy EquipItem (cmdID 22, abPreventRemoval) when the
            // target resolves to a real RefID right now - proven live (found today's own
            // dressing attempt actually delivered this way) and needs no bridge/restart.
            if ($target !== '' && strtolower($target) !== 'player'
                && preg_match('/^equipitem\s+([0-9A-Fa-f]{8})\s*$/', $body, $eqm)) {
                $realRefId = tesGodGuardResolveRealRefId($target);
                if ($realRefId !== '') {
                    $scriptproxy[] = ['refid' => $realRefId, 'verb' => 'equip', 'item' => strtoupper($eqm[1])];
                    // Real in-game result 2026-09-29 (Лилит Ткачиха): Requiem/RfaD clothing
                    // is split into separate body-slot items (editor_id ..._Body_...) -
                    // equipping only that piece left her missing feet/hands and still looked
                    // "naked". Queue the matching feet/hands pieces too when they exist.
                    foreach (tesGodGuardFindClothingSiblings($eqm[1]) as $siblingFormId) {
                        $scriptproxy[] = ['refid' => $realRefId, 'verb' => 'equip', 'item' => $siblingFormId];
                    }
                    // The plain console equipitem still runs too: instant visual, harmless,
                    // and a fallback if ScriptProxy ever turns out not to deliver reliably.
                } else {
                    // Can't resolve now (rare - target is already known here): fall back to
                    // the older bridge path (tesdress), which needs a game restart to have
                    // picked up TESGodConsoleReport.
                    $dress = tesGodGuardDressBody($body);
                    if ($dress !== $body) {
                        $kept[] = $command;
                        $body = $dress;
                        $command = $target . '.' . $body;
                    }
                }
            }
            // resurrect/kill: console-only. An earlier commit today added a ScriptProxy
            // "safety net" (cmdID 66/7) for these, but on review that was backwards: the
            // ONLY real evidence of a delivered ScriptProxy row so far is cmdID 22
            // (EquipItem) - cmdID 66/7 have never been confirmed delivered - while console
            // prid+resurrect WAS verified working in game (2026-09-28 15:52, ROADMAP §3).
            // Routing resurrect/kill through the unverified path also broke honest
            // reporting: tesGodJournalLine's life/death check only reads
            // chim_god_command outbox rows, so a ScriptProxy-only resurrect would report
            // "отправлено" instead of "сделано, проверено: жив". Reverted; see
            // docs/applied-log.md for the corrected history.
            $kept[] = $command;
            if (count($kept) >= 8) {
                break;
            }
        }
        return ['kept' => $kept, 'nearby' => $nearby, 'server' => $server, 'scriptproxy' => $scriptproxy, 'searches' => $searches, 'reasons' => $reasons];
    }

    // Autosave before a hard-to-undo world change (roadmap B: "автосейв перед крупной
    // задачей"). Queues "tesautosave" (bridge: Game.RequestAutoSave()) ahead of the real
    // commands in the same outbox batch, rate-limited so a burst of small edits doesn't
    // spam saves. Shared by tes_god_guard and tes_gifts, so it lives on $GLOBALS, not in a
    // class, and is safe to call from either.
    if (!function_exists('tesGodAutosaveIfNeeded')) {
        function tesGodAutosaveIfNeeded(string $reason, int $cooldownMinutes = 5): bool
        {
            $db = $GLOBALS['db'];
            $recent = $db->fetchOne("
                SELECT 1 AS ok FROM public.skyrim_quest_action_outbox
                WHERE beat_id = 'tes_autosave' AND created_at > now() - interval '{$cooldownMinutes} minutes'
                LIMIT 1
            ");
            if (!empty($recent['ok'])) {
                return false;
            }
            if (function_exists('tesGodJournalEnsureChannel')) {
                tesGodJournalEnsureChannel();
            }
            $db->insert('skyrim_quest_action_outbox', [
                'quest_key' => '000_tes_god_channel',
                'beat_id' => 'tes_autosave',
                'action_type' => 'console_command',
                'payload_json' => json_encode(['type' => 'console_command', 'command' => 'tesautosave'], JSON_UNESCAPED_UNICODE),
            ]);
            error_log("[tes_autosave] requested before: {$reason}");
            return true;
        }
    }

    // Whether this batch of resolved commands is hard to casually undo, so it's worth an
    // autosave first: resurrect/kill, a lasting character/routine/outfit change, ownership.
    function tesGodGuardIsBigChange(array $all, array $server): bool
    {
        foreach ($server as $srv) {
            if ($srv['verb'] === 'marry') {
                return true;
            }
        }
        foreach ($all as $command) {
            // "outfit" (not just "tesoutfit") added 2026-09-29: the ScriptProxy outfit path
            // no longer produces a "tesoutfit" console command, only a
            // "{scriptproxy:...}.outfit ..." entry in $all (see tesGodGuardFilterAction).
            if (preg_match('/\b(resurrect|kill|setownership|tesroutine|tesoutfit|outfit)\b/i', $command)) {
                return true;
            }
            // ROADMAP risk 2 / stage B validator: mass spawn needs confirmation or
            // autosave. placeatme is already capped to 10 at once (tesGodGuardValidate);
            // 3+ at once is still hard to clean up by hand.
            if (preg_match('/\bplaceatme\s+\S+\s+([3-9]|10)\b/i', $command)) {
                return true;
            }
        }
        return false;
    }

    // Resolve {npc:Name} or a bare RefID to a real, upper-case 8-hex RefID right now
    // (server-side), for the ScriptProxy safety net below - unlike the console path, this
    // cannot wait for the core's own {npc:} substitution later. '' = not found.
    function tesGodGuardResolveRealRefId(string $target): string
    {
        if (preg_match('/^[0-9A-Fa-f]{8}$/', $target)) {
            return strtoupper($target);
        }
        if (!preg_match('/^\{npc:([^}]+)\}$/iu', $target, $m)) {
            return '';
        }
        $name = trim($m[1]);
        $db = $GLOBALS['db'];
        $n = $db->escape($name);
        $row = $db->fetchOne("SELECT refid FROM public.core_npc_master WHERE npc_name ILIKE '{$n}' OR npc_name ILIKE '{$n} %' LIMIT 1");
        $refId = strtoupper(trim(strval($row['refid'] ?? '')));
        if (preg_match('/^[0-9A-F]{8}$/', $refId)) {
            return $refId;
        }
        $indexed = tesGodGuardIndexUnique($name, ['actor'], 'formid', 3);
        return $indexed !== '' ? strtoupper($indexed) : '';
    }

    // CHIM's own ScriptProxy channel (lib/scriptproxy_papyrus.php -> AIAgentScriptProxy.psc,
    // already used live by other actions, e.g. Drink) makes a real Actor.Resurrect() /
    // Actor.Kill() Papyrus call - unlike the console "resurrect"/"kill" commands, which were
    // found earlier this project to silently do nothing on some targets. Sent as a genuine
    // safety NET alongside the normal console command, never instead of it: if this whole
    // mechanism turns out to be unreliable too, the console path is untouched.
    function tesGodGuardScriptProxyBuilder(): SkyrimCommandBuilder
    {
        static $builder = null;
        if ($builder === null) {
            $lib = dirname(__DIR__, 2) . '/lib/scriptproxy_papyrus.php';
            require_once file_exists($lib) ? $lib : '/var/www/html/HerikaServer/lib/scriptproxy_papyrus.php';
            $builder = new SkyrimCommandBuilder();
        }
        return $builder;
    }

    function tesGodGuardScriptProxySafetyNet(string $refId, string $verb): void
    {
        $builder = tesGodGuardScriptProxyBuilder();
        $target = '0x' . $refId;
        $cmd = $verb === 'kill' ? $builder->Actor->Kill($target) : $builder->Actor->Resurrect($target);
        $builder->send($cmd);
        error_log("[tes_god_guard] ScriptProxy safety net: {$verb} {$target}");
    }

    // {npc:Name}.outfit / equipitem <HEX> on a target CHIM already knows: dispatched
    // through CHIM's own ScriptProxy (SetOutfit/EquipItem, cmdID 59/22) rather than the
    // custom tesoutfit/tesdress Papyrus bridge - real, already-proven infrastructure
    // (found a live `sent=1` EquipItem row from today's own dressing work), and it needs
    // no game restart to pick up, unlike a change to our own bridge .pex.
    function tesGodGuardScriptProxyDress(string $refId, string $itemOrOutfitFormId, bool $isOutfit): void
    {
        $builder = tesGodGuardScriptProxyBuilder();
        $target = '0x' . $refId;
        $form = '0x' . strtoupper($itemOrOutfitFormId);
        $cmd = $isOutfit ? $builder->Actor->SetOutfit($target, $form) : $builder->Actor->EquipItem($target, $form, true, true);
        $builder->send($cmd);
        error_log('[tes_god_guard] ScriptProxy ' . ($isOutfit ? 'outfit' : 'equip') . ": {$target} {$form}");
        if ($isOutfit) {
            // [гипотеза, не проверено] SetOutfit only changes the ActorBase's DEFAULT
            // outfit, it does not force an immediate re-equip (confirmed in game 2026-09-29:
            // Лилит Ткачиха stayed naked after unequipall + outfit). EvaluatePackage forces
            // the actor to re-evaluate their AI, which is the documented trick for making a
            // default-outfit change take effect now instead of "whenever the AI gets to it" -
            // cheap and harmless to try even if it turns out not to help.
            $builder->send($builder->Actor->EvaluatePackage($target));
            error_log("[tes_god_guard] ScriptProxy evaluatepackage (outfit refresh attempt): {$target}");
        }
    }

    function tesGodGuardQueueNearby(string $name, string $body): void
    {
        if (function_exists('tesGodJournalEnsureChannel')) {
            tesGodJournalEnsureChannel();
        }
        $payload = ['type' => 'console_command_sequence', 'commands' => ['tesnear ' . $name, $body]];
        $GLOBALS['db']->insert('skyrim_quest_action_outbox', [
            'quest_key' => '000_tes_god_channel',
            'beat_id' => 'chim_god_command',
            'action_type' => 'console_command_sequence',
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        error_log('[tes_god_guard] queued nearby: ' . json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    // Roadmap B: "цикл план -> шаг -> проверка -> исправление, лимит попыток, остановка и
    // честное сообщение при серии провалов". A soft prompt hint ("try something else") is
    // not enough - the model can and does ignore it and keep retrying variants of the same
    // blocked command (seen in the log: five NPC names in a row all refused as unknown).
    // Counts the most recent consecutive fully-blocked verdicts (newest first, stops at the
    // first non-blocked row), within the same window the journal already shows.
    function tesGodGuardFailureStreak(int $minutes = 30): int
    {
        $rows = $GLOBALS['db']->fetchAll("
            SELECT verdict FROM public.tes_god_guard_log
            WHERE created_at > now() - interval '{$minutes} minutes'
            ORDER BY id DESC LIMIT 12
        ");
        $streak = 0;
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (($row['verdict'] ?? '') !== 'blocked') {
                break;
            }
            $streak++;
        }
        return $streak;
    }

    function tesGodGuardIsRepeat(string $normalized): bool
    {
        $n = $GLOBALS['db']->escape($normalized);
        $row = $GLOBALS['db']->fetchOne("
            SELECT 1 AS ok FROM public.tes_god_guard_log
            WHERE kept_text = '{$n}' AND verdict IN ('ok', 'partial')
              AND created_at > now() - interval '30 seconds'
            LIMIT 1
        ");
        return !empty($row['ok']);
    }

    function tesGodGuardLog(string $raw, string $kept, string $verdict, array $reasons): void
    {
        $db = $GLOBALS['db'];
        $db->insert('tes_god_guard_log', [
            'raw_text' => $raw,
            'kept_text' => $kept,
            'verdict' => $verdict,
            'reasons' => implode("\n", $reasons),
        ]);
    }

    // Returns the action to pass on (null = drop it).
    function tesGodGuardFilterAction(string $action): ?string
    {
        $actionParts = explode('|', $action);
        $actionParts2 = explode('@', strval($actionParts[2] ?? ''));
        $rawParameter = implode('@', array_slice($actionParts2, 1));
        $payload = function_exists('decodeFunctionExecutionParameterPayload')
            ? decodeFunctionExecutionParameterPayload($rawParameter)
            : json_decode($rawParameter, true);
        $text = is_array($payload) ? trim(strval($payload['target'] ?? '')) : trim($rawParameter);
        if ($text === '') {
            error_log('[tes_god_guard] empty God_Command, raw action: ' . $action);
            return $action;  // let the core log it as before
        }

        tesGodGuardEnsureTable();
        $check = tesGodGuardValidate($text);
        // A find/search result is answered straight from the game index and lands in the
        // god journal (tes_god_journal shows verdict 'search'), visible on the NEXT turn.
        foreach ($check['searches'] as $search) {
            $label = "find {$search['kind']} {$search['query']}";
            $result = empty($search['result']) ? 'ничего похожего не найдено' : implode('; ', $search['result']);
            tesGodGuardLog($text, "{$label} → {$result}", 'search', []);
            error_log("[tes_god_guard] search: {$label} => {$result}");
        }
        $kept = implode('; ', $check['kept']);
        $all = $check['kept'];
        foreach ($check['nearby'] as $near) {
            $all[] = '{near:' . $near['name'] . '}.' . $near['body'];
        }
        foreach ($check['server'] as $srv) {
            $all[] = '{npc:' . $srv['npc'] . '}.' . $srv['verb'] . ' ' . $srv['args'];
        }
        // A lone outfit/equip/resurrect/kill routed entirely through ScriptProxy leaves
        // $kept empty - it must still count as "something was done", or it gets
        // misclassified as blocked and its dispatch loop below never runs (found by
        // review 2026-09-29: outfit was silently doing nothing on the live server since
        // the ScriptProxy switch, because of exactly this omission).
        foreach ($check['scriptproxy'] as $sp) {
            $all[] = '{scriptproxy:' . $sp['refid'] . '}.' . $sp['verb'] . (isset($sp['item']) ? ' ' . $sp['item'] : '');
        }
        $summary = implode('; ', $all);
        if ($summary === '') {
            // A pure find/search turn never reaches the game: its results were just logged
            // as verdict 'search' (shown in the journal) - that is not a failure.
            if (empty($check['searches'])) {
                tesGodGuardLog($text, '', 'blocked', $check['reasons']);
                error_log('[tes_god_guard] blocked: ' . $text . ' | ' . implode(' | ', $check['reasons']));
            }
            return null;
        }
        if (tesGodGuardIsRepeat($summary)) {
            tesGodGuardLog($text, $summary, 'repeat', ['то же самое уже отправлено меньше 30 секунд назад']);
            error_log('[tes_god_guard] dropped repeat: ' . $summary);
            return null;
        }
        if (tesGodGuardIsBigChange($all, $check['server'])) {
            tesGodAutosaveIfNeeded($summary);
        }
        tesGodGuardLog($text, $summary, empty($check['reasons']) ? 'ok' : 'partial', $check['reasons']);
        if (!empty($check['reasons'])) {
            error_log('[tes_god_guard] partial: ' . $summary . ' | ' . implode(' | ', $check['reasons']));
        }
        foreach ($check['nearby'] as $near) {
            tesGodGuardQueueNearby($near['name'], $near['body']);
        }
        foreach ($check['scriptproxy'] as $sp) {
            $spLabel = "{npc:{$sp['refid']}}." . $sp['verb'] . (isset($sp['item']) ? ' ' . $sp['item'] : '');
            // outfit's naked-NPC bug (see above) was a real, paid loop: the Narrator kept
            // retrying the same failed dispatch 6 times because nothing ever told it to
            // stop. outfit itself is disabled now, but the same loop risk exists for any
            // ScriptProxy verb (equip, resurrect) since none of their results are actually
            // verified - so refuse a 3rd identical dispatch within 10 minutes outright,
            // rather than let it repeat indefinitely at the owner's expense.
            $spRepeatCount = intval($GLOBALS['db']->fetchOne("
                SELECT count(*) AS n FROM public.tes_god_guard_log
                WHERE verdict = 'scriptproxy' AND kept_text = '" . $GLOBALS['db']->escape($spLabel) . "'
                  AND created_at > now() - interval '10 minutes'
            ")['n'] ?? 0);
            if ($spRepeatCount >= 2) {
                tesGodGuardLog($text, '', 'blocked', ["«{$spLabel}»: уже отправлено {$spRepeatCount} раз(а) за 10 минут, результата не видно — не повторяй, скажи игроку честно, что не получается"]);
                continue;
            }
            try {
                if (in_array($sp['verb'], ['resurrect', 'kill'], true)) {
                    tesGodGuardScriptProxySafetyNet($sp['refid'], $sp['verb']);
                } else {
                    tesGodGuardScriptProxyDress($sp['refid'], $sp['item'], $sp['verb'] === 'outfit');
                }
                // Gives the journal (ext/tes_god_journal) SOME visibility into this channel -
                // before this, a ScriptProxy dispatch (outfit/equip/resurrect-safety-net) was
                // completely invisible to the Narrator's own "было -> стало" reporting.
                tesGodGuardLog($text, $spLabel, 'scriptproxy', []);
            } catch (Throwable $e) {
                error_log('[tes_god_guard] ScriptProxy dispatch failed: ' . $e->getMessage());
                tesGodGuardLog($text, '', 'blocked', ["ScriptProxy {$spLabel}: {$e->getMessage()}"]);
            }
        }
        foreach ($check['server'] as $srv) {
            [$ok, $message] = tesGodGuardRunServer($srv);
            tesGodGuardLog($text, '', $ok ? 'server' : 'blocked', [$message]);
            error_log('[tes_god_guard] server ' . ($ok ? 'ok' : 'failed') . ': ' . $message);
        }
        if ($kept === '') {
            return null;  // everything went through the nearby / server paths
        }
        $actionParts[2] = $actionParts2[0] . '@' . json_encode(['target' => $kept], JSON_UNESCAPED_UNICODE);
        return implode('|', $actionParts);
    }
}

$GLOBALS['action_post_process_fnct_ex'][] = function ($actions) {
    if (!is_array($actions) || !isset($GLOBALS['db'])) {
        return $actions;
    }
    foreach ($actions as $n => $action) {
        try {
            $actionParts = explode('|', strval($action));
            $name = explode('@', strval($actionParts[2] ?? ''))[0];
            $code = function_exists('getFunctionCodeName') ? getFunctionCodeName($name) : false;
            if (($code ?: $name) !== 'GodCommand') {
                continue;
            }
            $filtered = tesGodGuardFilterAction(strval($action));
            if ($filtered === null) {
                unset($actions[$n]);
            } else {
                $actions[$n] = $filtered;
            }
        } catch (Throwable $e) {
            error_log('[tes_god_guard] ' . $e->getMessage());
        }
    }
    return $actions;
};
