<?php
/*
 * tes_god_journal: tells the Narrator what really happened to its recent
 * God_Command console commands, so it stops claiming results it cannot know.
 *
 * Read-only. Source of truth:
 *  - skyrim_quest_action_outbox rows with beat_id 'chim_god_command'
 *    (status 'applied' only means the game DISPATCHED the command);
 *  - core_npc_master.metadata.activity_status (is_dead + timestamp) for
 *    resurrect / kill, accepted only when the status is newer than the command.
 *
 * Loaded by main.php via requireFilesRecursively(..., "context_pre.php"),
 * i.e. inside a function: use $GLOBALS only. Any error here must not break
 * dialogue, hence the Throwable guard.
 */

if (!function_exists('tesGodJournalIsNarratorTurn')) {
    function tesGodJournalIsNarratorTurn(): bool
    {
        $type = strval($GLOBALS["gameRequest"][0] ?? '');
        if (in_array($type, ["narrator_inputtext", "narration", "narrator_welcome", "narrator_quest_comment"], true)) {
            return true;
        }
        if (strval($_GET["profile"] ?? '') === md5('The Narrator')) {
            return true;
        }
        return strval($GLOBALS["HERIKA_NAME"] ?? '') === 'The Narrator';
    }

    // herikaQueueGodCommands() (core patch) attaches outbox rows to the first
    // skyrim_quest_instances row. A quest-engine reset empties that table, and then
    // every God_Command is silently dropped. Keep an inert service quest ('000_' sorts
    // first) so the channel always exists: active=false and run_state 'inactive' keep
    // it out of the quest engine; console actions never change quest state on ack.
    function tesGodJournalEnsureChannel(): void
    {
        $db = $GLOBALS["db"];
        $db->execQuery("
            INSERT INTO public.skyrim_quest_definitions (quest_key, quest_editor_id, title, source_plugin, active)
            VALUES ('000_tes_god_channel', 'TESGodChannel', 'TES god console channel (service row)', 'tes_god_journal', false)
            ON CONFLICT (quest_key) DO NOTHING
        ");
        $db->execQuery("
            INSERT INTO public.skyrim_quest_instances (quest_key, quest_editor_id, run_state)
            VALUES ('000_tes_god_channel', 'TESGodChannel', 'inactive')
            ON CONFLICT (quest_key) DO NOTHING
        ");
    }

    function tesGodJournalNpc(string $refId): array
    {
        $db = $GLOBALS["db"];
        $ref = $db->escape(strtoupper($refId));
        $row = $db->fetchOne("SELECT npc_name, metadata::text AS meta FROM public.core_npc_master WHERE upper(refid) = '{$ref}' LIMIT 1");
        if (!is_array($row)) {
            return ['name' => $refId, 'status' => null];
        }
        $meta = json_decode(strval($row['meta'] ?? ''), true);
        $status = is_array($meta) ? ($meta['activity_status'] ?? null) : null;
        if (is_string($status)) {
            $status = json_decode($status, true);
        }
        return ['name' => strval($row['npc_name'] ?? $refId), 'status' => is_array($status) ? $status : null];
    }

    // Console lines logged by ext/tes_god_console for these commands within 3 minutes
    // after queueing. null = no report (override bridge not installed, or not run yet).
    function tesGodJournalConsole(array $commands, float $createdEpoch): ?array
    {
        $db = $GLOBALS["db"];
        $table = $db->fetchOne("SELECT to_regclass('public.tes_god_console_log') IS NOT NULL AS ok");
        if (!is_array($table) || !in_array($table['ok'] ?? '', [true, 't', 'true', 1, '1'], true) || $createdEpoch <= 0) {
            return null;
        }
        $found = false;
        $outputs = [];
        foreach ($commands as $command) {
            $c = $db->escape(trim(strval($command)));
            if ($c === '') {
                continue;
            }
            $row = $db->fetchOne("
                SELECT output FROM public.tes_god_console_log
                WHERE command = '{$c}'
                  AND created_at BETWEEN to_timestamp({$createdEpoch}) AND to_timestamp({$createdEpoch}) + interval '3 minutes'
                ORDER BY id ASC LIMIT 1
            ");
            if (!is_array($row) || !array_key_exists('output', $row)) {
                continue;
            }
            $found = true;
            $out = trim(strval($row['output']));
            if ($out !== '') {
                $outputs[] = $out;
            }
        }
        if (!$found) {
            return null;
        }
        $error = '';
        foreach ($outputs as $out) {
            if (preg_match('/not found|missing|invalid|error|unknown|could not|failed|no reference|not saved/i', $out)) {
                $error = mb_substr($out, 0, 120);
                break;
            }
        }
        return ['error' => $error, 'output' => mb_substr(implode(' / ', $outputs), 0, 160)];
    }

    function tesGodJournalLine(array $row): string
    {
        $payload = json_decode(strval($row['payload_json'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $commands = isset($payload['commands']) && is_array($payload['commands'])
            ? $payload['commands']
            : [strval($payload['command'] ?? '')];

        $allCommands = $commands;
        $refId = '';
        $nearName = '';
        if (preg_match('/^prid\s+([0-9A-Fa-f]{8})$/', trim(strval($commands[0] ?? '')), $m)) {
            $refId = strtoupper($m[1]);
            array_shift($commands);
        } elseif (preg_match('/^tesnear\s+(.+)$/u', trim(strval($commands[0] ?? '')), $m)) {
            $nearName = trim($m[1]);  // actor found by name in game (ext/tes_god_guard)
            array_shift($commands);
        }
        $commandText = trim(implode('; ', array_map('strval', $commands)));
        $npc = $refId !== '' ? tesGodJournalNpc($refId) : ['name' => '', 'status' => null];
        $who = $npc['name'] !== '' ? $npc['name'] : $nearName;
        $label = ($who !== '' ? $who . ': ' : '') . $commandText;

        $status = strtolower(strval($row['status'] ?? ''));
        $ageSec = intval($row['age_sec'] ?? 0);
        if ($status === 'pending') {
            return $ageSec > 20
                ? "{$label} — ещё НЕ выполнено (игра на паузе или мир не принимает команды)."
                : "{$label} — в очереди, результата пока нет.";
        }
        if ($status !== 'applied') {
            $reason = trim(strval($row['result_text'] ?? ''));
            return "{$label} — НЕ вышло" . ($reason !== '' ? " ({$reason})" : '') . '.';
        }

        // Real console output, when the TESGodConsoleReport bridge override is installed
        // (ext/tes_god_console stores it). An error line beats every other signal.
        $console = tesGodJournalConsole($allCommands, floatval($row['created_epoch'] ?? 0));
        if ($console !== null && $console['error'] !== '') {
            return "{$label} — НЕ вышло, консоль ответила: «{$console['error']}».";
        }
        $consoleNote = ($console !== null && $console['output'] !== '') ? " Консоль: «{$console['output']}»." : '';

        // Dispatched. Only life/death can be checked from the server side.
        $expectDead = null;
        if (preg_match('/^resurrect\b/i', $commandText)) {
            $expectDead = false;
        } elseif (preg_match('/^kill\b/i', $commandText)) {
            $expectDead = true;
        }
        if ($expectDead === null || $refId === '') {
            return $console !== null
                ? "{$label} — выполнено игрой, консоль без ошибок.{$consoleNote}"
                : "{$label} — отправлено в мир, проверить результат нечем.";
        }
        // activity_status.timestamp is not epoch time (seen: 3.6e13), so freshness is
        // judged in game time: the status must be newer than the game time at which
        // the command was queued (last eventlog gamets before created_at).
        $activity = $npc['status'];
        $seenGamets = is_array($activity) ? intval($activity['gamets'] ?? 0) : 0;
        $sentGamets = intval($row['sent_gamets'] ?? 0);
        if ($seenGamets <= 0 || $sentGamets <= 0 || $seenGamets <= $sentGamets) {
            return $console !== null
                ? "{$label} — выполнено игрой без ошибок консоли, но свежих сведений о {$npc['name']} нет.{$consoleNote}"
                : "{$label} — отправлено, свежих сведений о {$npc['name']} нет, не проверено.";
        }
        $isDead = !empty($activity['is_dead']);
        if ($isDead === $expectDead) {
            return "{$label} — сделано, проверено: " . ($isDead ? 'мёртв.' : 'жив.');
        }
        return "{$label} — НЕ вышло: " . ($isDead ? 'всё ещё мёртв.' : 'всё ещё жив.');
    }

    function tesGodJournalBuild(): string
    {
        $minutes = max(1, intval($GLOBALS["TES_GOD_JOURNAL_MINUTES"] ?? 30));
        $rows = $GLOBALS["db"]->fetchAll("
            SELECT o.payload_json::text AS payload_json, o.status,
                   COALESCE(o.result_json::text, '') AS result_json,
                   (SELECT e.gamets FROM public.eventlog e
                     WHERE e.localts <= extract(epoch FROM o.created_at)
                     ORDER BY e.localts DESC LIMIT 1) AS sent_gamets,
                   extract(epoch FROM (now() - o.created_at))::int AS age_sec,
                   extract(epoch FROM o.created_at) AS created_epoch
            FROM public.skyrim_quest_action_outbox o
            WHERE o.beat_id = 'chim_god_command'
              AND o.created_at > now() - interval '{$minutes} minutes'
            ORDER BY o.id DESC
            LIMIT 6
        ");
        $rows = is_array($rows) ? $rows : [];
        $lines = [];
        // Refusals by ext/tes_god_guard (table exists once the guard has seen a command).
        $guardTable = $GLOBALS["db"]->fetchOne("SELECT to_regclass('public.tes_god_guard_log') IS NOT NULL AS ok");
        if (is_array($guardTable) && in_array($guardTable['ok'] ?? '', [true, 't', 'true', 1, '1'], true)) {
            $refusals = $GLOBALS["db"]->fetchAll("
                SELECT verdict, reasons FROM public.tes_god_guard_log
                WHERE verdict IN ('blocked', 'partial', 'repeat', 'server')
                  AND created_at > now() - interval '{$minutes} minutes'
                ORDER BY id DESC LIMIT 4
            ");
            foreach (array_reverse(is_array($refusals) ? $refusals : []) as $refusal) {
                $label = ['repeat' => 'повтор не отправлен', 'server' => 'СДЕЛАНО (память CHIM)'][$refusal['verdict']] ?? 'ЗАБЛОКИРОВАНО';
                foreach (array_filter(explode("\n", strval($refusal['reasons'] ?? ''))) as $reason) {
                    $lines[] = "- " . (mb_strpos($reason, 'урезано') !== false ? 'ИЗМЕНЕНО' : $label) . ": {$reason}.";
                }
            }
        }
        // A recent autosave (ext/tes_god_guard's tesGodAutosaveIfNeeded, queued before a
        // hard-to-undo change) is worth one mention, so the Narrator can say honestly that
        // there is a rollback point if asked, without claiming it for every minor command.
        $autosave = $GLOBALS["db"]->fetchOne("
            SELECT status, applied_at IS NOT NULL AS done FROM public.skyrim_quest_action_outbox
            WHERE beat_id = 'tes_autosave' AND created_at > now() - interval '{$minutes} minutes'
            ORDER BY id DESC LIMIT 1
        ");
        if (is_array($autosave)) {
            // Postgres hands booleans back as the strings 't'/'f': !empty('f') is true in
            // PHP (a non-empty string), so that naive check always read as "done".
            $done = in_array($autosave['done'] ?? '', [true, 't', 'true', 1, '1'], true);
            $lines[] = $done
                ? '- Автосейв сделан перед этим крупным изменением мира (можно откатить обычной загрузкой автосохранения).'
                : '- Автосейв перед этим изменением запрошен, но игра ещё не подтвердила (пауза или ожидание).';
        }
        // People created during play (FFxxxxxx) that the game reported in the last 3 hours:
        // clones, summons, Create_New_NPC. Leftovers pile up unless the Narrator removes them.
        $created = [];
        $events = $GLOBALS["db"]->fetchAll("
            SELECT data FROM public.eventlog
            WHERE type = 'addnpc' AND localts > extract(epoch FROM now()) - 10800
            ORDER BY localts DESC LIMIT 50
        ");
        foreach (is_array($events) ? $events : [] as $event) {
            $parts = explode('@', strval($event['data'] ?? ''));
            if (str_starts_with(strtoupper(trim(strval($parts[4] ?? ''))), 'FF') && trim(strval($parts[0])) !== '') {
                $created[trim($parts[0])] = true;
            }
        }
        if (empty($rows) && empty($lines) && empty($created)) {
            return '';
        }
        foreach (array_reverse($rows) as $row) {
            $result = json_decode(strval($row['result_json'] ?? ''), true);
            $row['result_text'] = is_array($result)
                ? mb_substr(implode(' ', array_filter(array_map(
                    static function ($v) { return is_scalar($v) ? trim(strval($v)) : ''; },
                    $result
                ))), 0, 80)
                : '';
            $lines[] = '- ' . tesGodJournalLine($row);
        }
        if (!empty($created)) {
            $lines[] = '- Созданы во время игры (клоны, призванные, новые персонажи): ' . implode(', ', array_keys($created))
                . '. Лишних, кого заменил или кто больше не нужен, убери: {near:Имя}.unsummon.';
        }
        return "## Журнал твоих божественных команд (проверяет сервер, последние 30 минут)\n"
            . implode("\n", $lines) . "\n"
            . "Не говори, что команда сработала, если здесь не написано «сделано». "
            . "Если «НЕ вышло» — признай это одной фразой и попробуй иначе; если «не проверено» — не утверждай результат.";
    }
}

try {
    if (isset($GLOBALS["db"]) && function_exists('chimRegisterPromptInjection') && tesGodJournalIsNarratorTurn()) {
        tesGodJournalEnsureChannel();
        $tesGodJournal = tesGodJournalBuild();
        if ($tesGodJournal !== '') {
            chimRegisterPromptInjection('prompt_bottom', 'tes_god_journal', $tesGodJournal, 50);
        }
    }
} catch (Throwable $e) {
    error_log('[tes_god_journal] ' . $e->getMessage());
}
