<?php
/*
 * tes_gifts: NPC gifts that really change ownership in game (no "steal" label).
 *
 * Give_To_Player (core_action GiveToPlayer, settings/chim_settings.sql), target:
 *   horse  -> ["tesnear Лошадь", "setownership"]   nearest such animal to the player
 *   around -> ["tesnear <giver>", "tesgive around"] giver's things within 1500 units
 *   house  -> ["tesnear <giver>", "tesgive house"]  the interior the player stands in
 *   all    -> ["tesnear <giver>", "tesgive all"]    everything the giver carries
 *   spell:<name> -> ["player.addspell <FormID>"]    teach a spell (game index)
 * Sent through the quest action outbox; the TESGodConsoleReport bridge override does the
 * in-game part and reports to tes_god_console_log ("... gave N references to the player").
 */

if (!function_exists('tesGiftsQueue')) {
    function tesGiftsQueue(array $commands, string $who): void
    {
        if (function_exists('tesGodJournalEnsureChannel')) {
            tesGodJournalEnsureChannel();
        }
        $payload = ['type' => 'console_command_sequence', 'commands' => $commands];
        $GLOBALS['db']->insert('skyrim_quest_action_outbox', [
            'quest_key' => '000_tes_god_channel',
            'beat_id' => 'tes_gift',
            'action_type' => 'console_command_sequence',
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        error_log('[tes_gifts] ' . $who . ' queued ' . json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    // Returns the command sequence for one Give_To_Player target, or [] if not understood.
    function tesGiftsCommands(string $giver, string $target): array
    {
        $t = mb_strtolower(trim($target));
        if (preg_match('/^(spell|заклин)[a-zа-я]*\s*:\s*(.+)$/u', $t, $m)) {
            // teach a spell: resolved in the game index (ext/tes_god_guard)
            $spell = function_exists('tesGodGuardResolveItem') ? tesGodGuardResolveItem(trim($m[2]), ['spell']) : '';
            return $spell !== '' ? ['player.addspell ' . $spell] : [];
        }
        if (preg_match('/^(all|everything|всё|все\s|всё\s)/u', $t) || $t === 'все') {
            return ['tesnear ' . $giver, 'tesgive all'];
        }
        if (preg_match('/^(house|home|дом|хат)/u', $t)) {
            return ['tesnear ' . $giver, 'tesgive house'];
        }
        if (preg_match('/^(around|things|items|chest|вещ|сундук)/u', $t)) {
            return ['tesnear ' . $giver, 'tesgive around'];
        }
        if (preg_match('/^(horse|mount|лошад|конь|коня)/u', $t)) {
            return ['tesnear Лошадь', 'setownership'];
        }
        // "horse:Name" or a plain animal name shown in the scene
        $name = trim(preg_replace('/^[a-z]+:/i', '', $target));
        return preg_match('/^[\p{L}\p{N} \'\-]{2,40}$/u', $name) ? ['tesnear ' . $name, 'setownership'] : [];
    }
}

$GLOBALS['action_post_process_fnct_ex'][] = function ($actions) {
    if (!is_array($actions) || !isset($GLOBALS['db'])) {
        return $actions;
    }
    foreach ($actions as $n => $action) {
        try {
            $parts = explode('|', strval($action));
            $call = explode('@', strval($parts[2] ?? ''));
            $code = function_exists('getFunctionCodeName') ? getFunctionCodeName($call[0]) : false;
            if (($code ?: $call[0]) !== 'GiveToPlayer') {
                continue;
            }
            $raw = implode('@', array_slice($call, 1));
            $payload = function_exists('decodeFunctionExecutionParameterPayload')
                ? decodeFunctionExecutionParameterPayload($raw)
                : json_decode($raw, true);
            $target = is_array($payload) ? trim(strval($payload['target'] ?? '')) : trim($raw);
            $giver = trim(strval($parts[0] ?? ''));
            $commands = tesGiftsCommands($giver, $target);
            if (empty($commands)) {
                error_log("[tes_gifts] {$giver}: target not understood: {$target}");
            } else {
                tesGiftsQueue($commands, $giver);
            }
            unset($actions[$n]);
        } catch (Throwable $e) {
            error_log('[tes_gifts] ' . $e->getMessage());
        }
    }
    return $actions;
};
