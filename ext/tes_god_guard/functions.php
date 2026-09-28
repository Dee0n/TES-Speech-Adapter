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

    function tesGodGuardKnownRefId(string $refId): bool
    {
        $db = $GLOBALS['db'];
        $r = $db->escape(strtoupper($refId));
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
            'setessential', 'pushactoraway', 'setlevel',
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
        $reasons = [];
        foreach (preg_split('/[;\n]+/u', $text) as $command) {
            $command = trim($command);
            if ($command === '') {
                continue;
            }
            $target = '';
            $body = $command;
            if (preg_match('/^(\{npc:[^}]+\}|[0-9A-Fa-f]{8}|player)\s*\.\s*(.+)$/iu', $command, $m)) {
                $target = $m[1];
                $body = trim($m[2]);
            }
            $verb = strtolower(strval(preg_split('/\s+/', $body)[0] ?? ''));

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
            if (preg_match('/^\{npc:([^}]+)\}$/iu', $target, $m) && !tesGodGuardKnownNpc($m[1])) {
                // Unknown to the server (never talked to the player), but maybe standing
                // nearby: the TESGodConsoleReport bridge finds actors by display name
                // in game ("tesnear <Name>"). Placeholders can't be resolved on that path.
                if (strpos($body, '{') !== false) {
                    $reasons[] = "«{$command}»: для NPC, которого сервер не знает, можно только команды без {…}";
                    continue;
                }
                $nearby[] = ['name' => trim($m[1]), 'body' => $body];
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
            $kept[] = $command;
            if (count($kept) >= 8) {
                break;
            }
        }
        return ['kept' => $kept, 'nearby' => $nearby, 'reasons' => $reasons];
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
        if ($kept === '') {
            return null;  // everything went through the nearby path
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
