<?php
/*
 * tes_book_value: diaries, notes and documents were worth 10000 gold each.
 * CHIM's Papyrus SpawnItem sets that value on the shared base form at every spawn
 * (AIAgentAIMind.psc "itemToSpawnBase.SetGoldValue(10000)"). After the game has fetched a
 * spawnBook command, queue the bridge's "tesbookvalue" (base value back to 5 gold).
 * Own beat_id, so the god journal does not show it as a Narrator command.
 */

try {
    if (isset($GLOBALS['db'])) {
        $db = $GLOBALS['db'];
        $last = $db->fetchOne("SELECT max(localts) AS t FROM responselog WHERE sent = 1 AND action LIKE 'rolecommand|spawnBook@%' AND localts > " . (time() - 900));
        $lastSpawn = intval($last['t'] ?? 0);
        if ($lastSpawn > 0) {
            $done = $db->fetchOne("SELECT extract(epoch FROM max(created_at))::bigint AS t FROM public.skyrim_quest_action_outbox WHERE beat_id = 'tes_book_value'");
            if (intval($done['t'] ?? 0) < $lastSpawn) {
                $quest = $db->fetchOne("SELECT quest_key FROM public.skyrim_quest_instances ORDER BY quest_key LIMIT 1");
                if (!empty($quest['quest_key'])) {
                    $db->insert('skyrim_quest_action_outbox', [
                        'quest_key' => $quest['quest_key'],
                        'beat_id' => 'tes_book_value',
                        'action_type' => 'console_command',
                        'payload_json' => json_encode(['type' => 'console_command', 'command' => 'tesbookvalue']),
                    ]);
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('[tes_book_value] ' . $e->getMessage());
}
