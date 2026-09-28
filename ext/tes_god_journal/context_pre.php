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

    function tesGodJournalLine(array $row): string
    {
        $payload = json_decode(strval($row['payload_json'] ?? ''), true);
        $payload = is_array($payload) ? $payload : [];
        $commands = isset($payload['commands']) && is_array($payload['commands'])
            ? $payload['commands']
            : [strval($payload['command'] ?? '')];

        $refId = '';
        if (preg_match('/^prid\s+([0-9A-Fa-f]{8})$/', trim(strval($commands[0] ?? '')), $m)) {
            $refId = strtoupper($m[1]);
            array_shift($commands);
        }
        $commandText = trim(implode('; ', array_map('strval', $commands)));
        $npc = $refId !== '' ? tesGodJournalNpc($refId) : ['name' => '', 'status' => null];
        $label = ($npc['name'] !== '' ? $npc['name'] . ': ' : '') . $commandText;

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

        // Dispatched. Only life/death can be checked from the server side.
        $expectDead = null;
        if (preg_match('/^resurrect\b/i', $commandText)) {
            $expectDead = false;
        } elseif (preg_match('/^kill\b/i', $commandText)) {
            $expectDead = true;
        }
        if ($expectDead === null || $refId === '') {
            return "{$label} — отправлено в мир, проверить результат нечем.";
        }
        // activity_status.timestamp is not epoch time (seen: 3.6e13), so freshness is
        // judged in game time: the status must be newer than the game time at which
        // the command was queued (last eventlog gamets before created_at).
        $activity = $npc['status'];
        $seenGamets = is_array($activity) ? intval($activity['gamets'] ?? 0) : 0;
        $sentGamets = intval($row['sent_gamets'] ?? 0);
        if ($seenGamets <= 0 || $sentGamets <= 0 || $seenGamets <= $sentGamets) {
            return "{$label} — отправлено, свежих сведений о {$npc['name']} нет, не проверено.";
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
                   extract(epoch FROM (now() - o.created_at))::int AS age_sec
            FROM public.skyrim_quest_action_outbox o
            WHERE o.beat_id = 'chim_god_command'
              AND o.created_at > now() - interval '{$minutes} minutes'
            ORDER BY o.id DESC
            LIMIT 6
        ");
        if (!is_array($rows) || empty($rows)) {
            return '';
        }
        $lines = [];
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
