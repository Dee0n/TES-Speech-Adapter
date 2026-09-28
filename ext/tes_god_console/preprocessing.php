<?php
/*
 * tes_god_console: receives the real console output of commands executed by the
 * AIAgent quest bridge. The override bridge (papyrus/TESGodConsoleReport, an MO2 mod)
 * calls AIAgentFunctions.logMessage("<command>@@<last console line>", "tes_god_console")
 * after every ConsoleUtil.ExecuteCommand.
 *
 * Runs in main.php's preprocessing hook, before the MAIN semaphore and the LLM
 * pipeline: store the line and end the request.
 */

if (strtolower(strval($GLOBALS["gameRequest"][0] ?? '')) === 'tes_god_console') {
    try {
        $db = $GLOBALS["db"];
        $db->execQuery("
            CREATE TABLE IF NOT EXISTS public.tes_god_console_log (
                id bigserial PRIMARY KEY,
                created_at timestamptz NOT NULL DEFAULT now(),
                gamets bigint,
                command text NOT NULL,
                output text NOT NULL DEFAULT ''
            )
        ");
        // The message itself may contain '|', which the request format splits on.
        $message = implode('|', array_slice($GLOBALS["gameRequest"], 3));
        $parts = explode('@@', $message, 2);
        $db->insert('tes_god_console_log', [
            'gamets' => intval($GLOBALS["gameRequest"][2] ?? 0),
            'command' => mb_substr(trim(strval($parts[0] ?? '')), 0, 500),
            'output' => mb_substr(trim(strval($parts[1] ?? '')), 0, 500),
        ]);
    } catch (Throwable $e) {
        error_log('[tes_god_console] ' . $e->getMessage());
    }
    if (function_exists('terminate')) {
        terminate();
    }
    exit;
}
