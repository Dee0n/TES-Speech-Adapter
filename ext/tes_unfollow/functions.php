<?php
/*
 * tes_unfollow: an NPC that goes somewhere stops following the player.
 *
 * CHIM's FollowPlayer leaves StorageUtil "CHIM_FollowPlayerActive" = 1 and a priority-100
 * package; AIAgentAIMind restores the follow after every other action. Live 2026-10-03:
 * Proventus chose FollowPlayer four times in the morning, then for an hour answered
 * "I am going to Dragonsreach" (TravelTo) while walking behind the player.
 * When an NPC who followed the player issues TravelTo / ReturnBackHome, the bridge's
 * tesunfollow clears the flag (the travel package itself stays).
 */

$GLOBALS['action_post_process_fnct_ex'][] = function ($actions) {
    if (!is_array($actions) || !isset($GLOBALS['db'])) {
        return $actions;
    }
    foreach ($actions as $action) {
        try {
            $parts = explode('|', strval($action));
            $name = explode('@', strval($parts[2] ?? ''))[0];
            $code = function_exists('getFunctionCodeName') ? getFunctionCodeName($name) : false;
            $code = $code ?: $name;
            if (!in_array($code, ['TravelTo', 'TravelToRaw', 'ReturnBackHome'], true)) {
                continue;
            }
            $actor = trim(strval($parts[0] ?? ''));
            $db = $GLOBALS['db'];
            $followed = $db->fetchOne("SELECT 1 AS x FROM actions_issued WHERE actorname = '" . $db->escape($actor)
                . "' AND fullcall LIKE '%|command|FollowPlayer@%' AND localts > " . (time() - 86400) . " LIMIT 1");
            if (empty($followed)) {
                continue;
            }
            $recent = $db->fetchOne("SELECT 1 AS x FROM public.skyrim_quest_action_outbox WHERE beat_id = 'tes_unfollow' AND created_at > now() - interval '20 seconds' LIMIT 1");
            if (!empty($recent) || !function_exists('tesGodGuardResolveNpcLoose')) {
                continue;
            }
            $row = tesGodGuardResolveNpcLoose($actor);
            $ref = strtoupper(trim(strval($row['refid'] ?? '')));
            $quest = $db->fetchOne("SELECT quest_key FROM public.skyrim_quest_instances ORDER BY quest_key LIMIT 1");
            if (preg_match('/^[0-9A-F]{8}$/', $ref) && !empty($quest['quest_key'])) {
                $db->insert('skyrim_quest_action_outbox', [
                    'quest_key' => $quest['quest_key'], 'beat_id' => 'tes_unfollow', 'action_type' => 'console_command_sequence',
                    'payload_json' => json_encode(['type' => 'console_command_sequence', 'commands' => ['prid ' . $ref, 'tesunfollow']]),
                ]);
                error_log("[tes_unfollow] {$actor} travels - follow flag cleared");
            }
        } catch (Throwable $e) {
            error_log('[tes_unfollow] ' . $e->getMessage());
        }
    }
    return $actions;
};
