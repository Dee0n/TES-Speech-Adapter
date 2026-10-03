<?php
/*
 * tes_agent: hand-off from the Narrator to the background goal agent.
 *
 * The Narrator answers a big goal with GodCommand target "goal: <the goal in the player's
 * words>". This hook (registered before tes_god_guard's: ext/ is scanned alphabetically)
 * takes such an action out of the list and starts worker.php. Everything else passes on.
 */

require_once __DIR__ . '/lib.php';

$GLOBALS['action_post_process_fnct_ex'][] = function ($actions) {
    if (!is_array($actions) || !isset($GLOBALS['db'])) {
        return $actions;
    }
    foreach ($actions as $n => $action) {
        try {
            $parts = explode('|', strval($action));
            $call = explode('@', strval($parts[2] ?? ''));
            $code = function_exists('getFunctionCodeName') ? getFunctionCodeName($call[0]) : false;
            if (($code ?: $call[0]) !== 'GodCommand') {
                continue;
            }
            $raw = implode('@', array_slice($call, 1));
            $payload = function_exists('decodeFunctionExecutionParameterPayload')
                ? decodeFunctionExecutionParameterPayload($raw)
                : json_decode($raw, true);
            $text = is_array($payload) ? trim(strval($payload['target'] ?? '')) : trim($raw);
            if (!preg_match('/^\s*(goal|цель)\s*:\s*(.+)$/isu', $text, $m)) {
                continue;
            }
            [$ok, $message] = tesAgentStart($m[2]);
            error_log('[tes_agent] ' . ($ok ? 'started' : 'refused') . ": {$message} | goal: {$m[2]}");
            tesAgentNotify($ok ? 'Нарратор взялся за дело: ' . $m[2] : 'Нарратор: ' . $message);
            unset($actions[$n]);
        } catch (Throwable $e) {
            error_log('[tes_agent] ' . $e->getMessage());
        }
    }
    return $actions;
};
