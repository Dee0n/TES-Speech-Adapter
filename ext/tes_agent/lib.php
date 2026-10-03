<?php
/*
 * tes_agent shared helpers: the task table and the hand-off (spawn the worker).
 * Used by functions.php (inside CHIM requests) and worker.php (CLI).
 */

if (!function_exists('tesAgentEnsureTable')) {
    function tesAgentEnsureTable(): void
    {
        $GLOBALS['db']->execQuery("
            CREATE TABLE IF NOT EXISTS public.tes_agent_tasks (
                id bigserial PRIMARY KEY,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now(),
                goal text NOT NULL,
                status text NOT NULL DEFAULT 'queued',  -- queued, running, done, failed, gave_up
                steps int NOT NULL DEFAULT 0,
                cost numeric NOT NULL DEFAULT 0,
                pid int,
                result text NOT NULL DEFAULT '',
                transcript jsonb NOT NULL DEFAULT '[]'
            )
        ");
    }

    /** The running task, if any (a dead worker's row older than 15 min does not count). */
    function tesAgentRunningTask(): ?array
    {
        tesAgentEnsureTable();
        $row = $GLOBALS['db']->fetchOne("
            SELECT id, goal, steps, status FROM public.tes_agent_tasks
            WHERE status IN ('queued', 'running') AND updated_at > now() - interval '15 minutes'
            ORDER BY id DESC LIMIT 1
        ");
        return is_array($row) && !empty($row['id']) ? $row : null;
    }

    /** Create a task row and start the detached worker. Returns [ok, message]. */
    function tesAgentStart(string $goal): array
    {
        $goal = trim(preg_replace('/\s+/u', ' ', $goal) ?? $goal);
        if (mb_strlen($goal) < 3) {
            return [false, 'пустая цель'];
        }
        $running = tesAgentRunningTask();
        if ($running) {
            return [false, "уже идёт задача #{$running['id']}: {$running['goal']}"];
        }
        $db = $GLOBALS['db'];
        $row = $db->fetchOne("INSERT INTO public.tes_agent_tasks (goal) VALUES ('" . $db->escape(mb_substr($goal, 0, 1000)) . "') RETURNING id");
        $id = intval($row['id'] ?? 0);
        if ($id <= 0) {
            return [false, 'не удалось создать задачу'];
        }
        $worker = __DIR__ . '/worker.php';
        $log = '/var/www/html/HerikaServer/log/tes_agent_' . $id . '.log';
        // setsid + nohup: the worker must outlive this HTTP request (SNQE pattern).
        exec('setsid nohup php ' . escapeshellarg($worker) . ' --task ' . $id . ' > ' . escapeshellarg($log) . ' 2>&1 &');
        return [true, "задача #{$id} запущена"];
    }

    /** Short DebugNotification in the top-left corner of the game. */
    function tesAgentNotify(string $text): void
    {
        $text = trim(str_replace(['@', '|', "\n", "\r"], [' at ', '/', ' ', ' '], $text));
        $GLOBALS['db']->insert('responselog', [
            'localts' => time(), 'sent' => 0, 'actor' => 'rolemaster', 'text' => '',
            'action' => 'rolecommand|DebugNotification@' . mb_substr($text, 0, 200), 'tag' => '',
        ]);
    }

    /** Make the Narrator speak (the game sends an "instruction" request back, normal pipeline). */
    function tesAgentNarratorSay(string $instruction, int $taskId): void
    {
        $instruction = trim(str_replace(['@', '|', "\n", "\r"], [' at ', '/', ' ', ' '], $instruction));
        $GLOBALS['db']->insert('responselog', [
            'localts' => time(), 'sent' => 0, 'actor' => 'rolemaster', 'text' => '',
            'action' => 'rolecommand|Instruction@The Narrator@' . mb_substr($instruction, 0, 900) . '@0',  // task id 0, as processor/comm.php does
            'tag' => '',
        ]);
    }
}
