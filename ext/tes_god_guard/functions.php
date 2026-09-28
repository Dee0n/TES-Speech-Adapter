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
            $words = array_values(array_diff(
                preg_split('/[^a-z0-9]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY),
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
        if ($kinds === ['item'] && function_exists('herikaResolveSpawnItemDescriptionMatch')) {
            $item = herikaResolveSpawnItemDescriptionMatch($name);
            $formId = strtoupper(strval($item['runtime_formid'] ?? ''));
            if (preg_match('/^(0x)?[0-9A-F]{1,8}$/', $formId)) {
                return str_pad(preg_replace('/^0X/', '', $formId), 8, '0', STR_PAD_LEFT);
            }
        }
        return '';
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
        $lines = array_slice(array_values(array_unique($lines)), -8);
        $db->execQuery("UPDATE public.core_npc_master SET npc_static_bio = '" . $db->escape($base . $marker . implode("\n", $lines)) . "' WHERE id = {$id}");
        return implode(' / ', $lines);
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
        $npc = RelationshipManager::resolveNpcByName($who);
        if (!$npc) {
            return [false, "«{$who}»: этого персонажа нет в памяти CHIM (он ещё ни разу не говорил с игроком)"];
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
            $other = RelationshipManager::resolveNpcByName(trim($cmd['args']));
            if (!$other) {
                return [false, "«" . trim($cmd['args']) . "»: этого персонажа нет в памяти CHIM"];
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
        $toName = trim($m[1]);
        $target = 'Player';
        $targetLabel = 'игроку';
        if ($toName !== '' && RelationshipManager::normalizeTargetName($toName) !== 'Player') {
            $other = RelationshipManager::resolveNpcByName($toName);
            if (!$other) {
                return [false, "«{$toName}»: этого персонажа нет в памяти CHIM"];
            }
            $target = strval($other['npc_name']);
            $targetLabel = $target;
        }
        $before = RelationshipManager::getRelationship($name, $target);
        $oldText = is_array($before) ? (($before['aff'] ?? '?') . ' ' . ($before['type'] ?? '?') . ' «' . ($before['note'] ?? '') . '»') : 'нет';
        if (!RelationshipManager::setRelationship($name, $target, intval($m[2]), strtolower($m[3]))) {
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
            'setessential', 'pushactoraway', 'setlevel', 'coc', 'sgtm', 'setownership', 'unequipall',
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
        $reasons = [];
        foreach (preg_split('/[;\n]+/u', $text) as $command) {
            $command = trim($command);
            if ($command === '') {
                continue;
            }
            $target = '';
            $body = $command;
            if (preg_match('/^(\{(?:npc|near):[^}]+\}|[0-9A-Fa-f]{8}|player)\s*\.\s*(.+)$/iu', $command, $m)) {
                $target = $m[1];
                $body = trim($m[2]);
            }
            // {cell:Name} -> cell EditorID (for coc); {item:Name} -> FormID (see above).
            $unresolved = '';
            $body = preg_replace_callback('/\{(cell|item|spawn):([^}]+)\}/iu', function ($m) use (&$unresolved) {
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
                } else {
                    $value = tesGodGuardResolveItem($what);
                }
                if ($value === '' && $unresolved === '') {
                    $unresolved = ['cell' => 'места', 'item' => 'предмета', 'spawn' => 'существа'][$kind] . ' «' . $what . '»';
                }
                return $value !== '' ? $value : $m[0];
            }, $body) ?? $body;
            if ($unresolved !== '') {
                $reasons[] = strpos($unresolved, 'уникальный') !== false
                    ? "«{$command}»: {$unresolved}"
                    : "«{$command}»: не знаю {$unresolved} — назови точно, как в игре (по-русски)";
                continue;
            }
            $command = ($target !== '' ? $target . '.' : '') . $body;
            $verb = strtolower(strval(preg_split('/\s+/', $body)[0] ?? ''));

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
            // Console equipitem on an NPC doesn't stick (they switch back to their outfit):
            // the bridge's tesdress equips with "prevent removal". Papyrus gets the runtime
            // FormID as a signed decimal (it has no hex parsing).
            if ($target !== '' && strtolower($target) !== 'player') {
                $dress = tesGodGuardDressBody($body);
                if ($dress !== $body) {
                    // Plain equipitem first: it works even with an older bridge (until the
                    // game restarts), then tesdress pins it.
                    $kept[] = $command;
                    $body = $dress;
                    $command = $target . '.' . $body;
                }
            }
            $kept[] = $command;
            if (count($kept) >= 8) {
                break;
            }
        }
        return ['kept' => $kept, 'nearby' => $nearby, 'server' => $server, 'reasons' => $reasons];
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
        $kept = implode('; ', $check['kept']);
        $all = $check['kept'];
        foreach ($check['nearby'] as $near) {
            $all[] = '{near:' . $near['name'] . '}.' . $near['body'];
        }
        foreach ($check['server'] as $srv) {
            $all[] = '{npc:' . $srv['npc'] . '}.' . $srv['verb'] . ' ' . $srv['args'];
        }
        $summary = implode('; ', $all);
        if ($summary === '') {
            tesGodGuardLog($text, '', 'blocked', $check['reasons']);
            error_log('[tes_god_guard] blocked: ' . $text . ' | ' . implode(' | ', $check['reasons']));
            return null;
        }
        if (tesGodGuardIsRepeat($summary)) {
            tesGodGuardLog($text, $summary, 'repeat', ['то же самое уже отправлено меньше 30 секунд назад']);
            error_log('[tes_god_guard] dropped repeat: ' . $summary);
            return null;
        }
        tesGodGuardLog($text, $summary, empty($check['reasons']) ? 'ok' : 'partial', $check['reasons']);
        if (!empty($check['reasons'])) {
            error_log('[tes_god_guard] partial: ' . $summary . ' | ' . implode(' | ', $check['reasons']));
        }
        foreach ($check['nearby'] as $near) {
            tesGodGuardQueueNearby($near['name'], $near['body']);
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
