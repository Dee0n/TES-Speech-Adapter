<?php
/*
 * tes_russify: NPC names only in Cyrillic.
 *
 * Real Names Extended named many NPCs before its Russian lists were installed; those
 * Latin names live in the save. When the game reports nearby beings (infonpc /
 * infonpc_close) with a Latin name, queue "tesrussify" (at most every 5 minutes): the
 * TESGodConsoleReport bridge re-rolls them with the mod's own Rechange spell and reports
 * the rest (Latin names from other mods) to tes_god_console_log.
 */

if (in_array(strval($GLOBALS["gameRequest"][0] ?? ''), ['infonpc', 'infonpc_close'], true)) {
    try {
        // Status tags are English words themselves ("(dead)", "(far away)") and must not be
        // mistaken for a Latin display name - Хеймскр's "(dead)" tag alone kept re-queueing
        // tesrussify every 5 min for nothing (renamed 0 each time).
        $names = preg_replace(
            '/\((?:far away|too far away|busy|hostile|in combat|dead|disabled|unavailable)\)/i',
            '',
            str_replace('beings in range:', '', strval($GLOBALS["gameRequest"][3] ?? ''))
        );
        if (preg_match('/(^|[,\/(])\s*[A-Za-z]{2,}/', $names) && isset($GLOBALS["db"])) {
            $db = $GLOBALS["db"];
            $recent = $db->fetchOne("
                SELECT 1 AS ok FROM public.skyrim_quest_action_outbox
                WHERE beat_id = 'tes_russify' AND created_at > now() - interval '5 minutes'
                LIMIT 1
            ");
            if (empty($recent['ok'])) {
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
                $db->insert('skyrim_quest_action_outbox', [
                    'quest_key' => '000_tes_god_channel',
                    'beat_id' => 'tes_russify',
                    'action_type' => 'console_command',
                    'payload_json' => json_encode(['type' => 'console_command', 'command' => 'tesrussify']),
                ]);
                error_log('[tes_russify] Latin NPC names nearby, queued tesrussify');
            }
        }
    } catch (Throwable $e) {
        error_log('[tes_russify] ' . $e->getMessage());
    }
}
