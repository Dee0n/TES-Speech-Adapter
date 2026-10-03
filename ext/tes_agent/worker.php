<?php
/*
 * tes_agent worker: the goal loop of the god agent (docs/narrator-agent.md).
 *
 *   php worker.php --task <id>            run a task created by tesAgentStart()
 *   php worker.php --goal "<text>" [--dry] create + run (testing); --dry sends nothing to
 *                                         the game: writes are only validated by the guard,
 *                                         game reads answer "dry-run"
 *
 * Loop: LLM (native tool calling, OpenRouter) -> typed tool -> the SERVER builds the console
 * text, every write goes through tes_god_guard exactly like a Narrator GodCommand -> one
 * game command batch at a time, wait for its tes_god_console report -> result back to the
 * LLM. "finish" is not trusted: the worker itself checks the expectations the model lists
 * (item counts, perks, skills, quest stages) and sends it back to work if any fail.
 */

if (php_sapi_name() !== 'cli') {
    exit;
}

const TES_AGENT_MAX_STEPS = 60;
const TES_AGENT_MAX_SECONDS = 600;
const TES_AGENT_GAME_TIMEOUT = 25;
const TES_AGENT_MODELS = [
    ['model' => 'deepseek/deepseek-v4-flash', 'connector' => 8, 'extra' => ['reasoning' => ['enabled' => false]]],
    ['model' => 'google/gemini-3.8-flash', 'connector' => 12, 'extra' => ['reasoning' => ['effort' => 'minimal']]],
];

$enginePath = '/var/www/html/HerikaServer/';
$GLOBALS['ENGINE_PATH'] = $enginePath;
chdir($enginePath);
require_once $enginePath . 'lib/runtime_bootstrap.php';
chimRuntimeBootstrap($enginePath, ['load_general_settings' => true, 'load_player_name' => true, 'load_narrator' => true]);
require_once $enginePath . 'lib/chat_helper_functions.php';
require_once $enginePath . 'lib/data_functions.php';
$GLOBALS['gameRequest'] = $GLOBALS['gameRequest'] ?? ['tes_agent', time(), 0, ''];
require_once $enginePath . 'functions/functions.php';  // herikaQueueGodCommands + ext functions (tes_god_guard)
require_once __DIR__ . '/lib.php';

$db = $GLOBALS['db'];
$args = getopt('', ['task:', 'goal:', 'dry']);
$dry = isset($args['dry']);
tesAgentEnsureTable();
if (!empty($args['goal'])) {
    $row = $db->fetchOne("INSERT INTO public.tes_agent_tasks (goal) VALUES ('" . $db->escape($args['goal']) . "') RETURNING id");
    $taskId = intval($row['id']);
} else {
    $taskId = intval($args['task'] ?? 0);
}
$task = $db->fetchOne("SELECT * FROM public.tes_agent_tasks WHERE id = {$taskId}");
if (!$task) {
    fwrite(STDERR, "no task {$taskId}\n");
    exit(1);
}
$db->execQuery("UPDATE public.tes_agent_tasks SET status = 'running', pid = " . getmypid() . ", updated_at = now() WHERE id = {$taskId}");
echo "task #{$taskId}" . ($dry ? ' (dry)' : '') . ": {$task['goal']}\n";

/* ------------------------------------------------------------------ LLM */

function tesAgentApiKey(int $connectorId): string
{
    $row = $GLOBALS['db']->fetchOne("SELECT b.* FROM core_api_badge b JOIN core_llm_connector c ON c.api_badge_id = b.id WHERE c.id = {$connectorId}");
    foreach (is_array($row) ? $row : [] as $v) {
        if (is_string($v) && str_starts_with($v, 'sk-or-')) {
            return $v;
        }
    }
    return '';
}

function tesAgentLlm(array $messages, array $tools, float &$cost): ?array
{
    foreach (TES_AGENT_MODELS as $cfg) {
        $key = tesAgentApiKey($cfg['connector']);
        if ($key === '') {
            continue;
        }
        $body = array_merge(['model' => $cfg['model'], 'messages' => $messages, 'tools' => $tools,
            'tool_choice' => 'auto', 'max_tokens' => 1500, 'temperature' => 0.3, 'usage' => ['include' => true]], $cfg['extra']);
        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$key}"],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
        $resp = json_decode(strval(curl_exec($ch)), true);
        $msg = $resp['choices'][0]['message'] ?? null;
        if (is_array($msg)) {
            $cost += floatval($resp['usage']['cost'] ?? 0);
            return $msg;
        }
        echo "  ! {$cfg['model']}: " . mb_substr(json_encode($resp['error'] ?? $resp, JSON_UNESCAPED_UNICODE), 0, 300) . "\n";
    }
    return null;
}

/* ------------------------------------------------------------------ game I/O */

function tesAgentConsoleMaxId(): int
{
    $db = $GLOBALS['db'];
    $db->execQuery("CREATE TABLE IF NOT EXISTS public.tes_god_console_log (id bigserial PRIMARY KEY, created_at timestamptz NOT NULL DEFAULT now(), gamets bigint, command text NOT NULL, output text NOT NULL DEFAULT '')");
    return intval($db->fetchOne("SELECT coalesce(max(id), 0) AS m FROM public.tes_god_console_log")['m'] ?? 0);
}

/** Wait for console reports newer than $sinceId: until $expected rows came (then 2 s quiet) or timeout. */
function tesAgentWaitReports(int $sinceId, int $expected): array
{
    $started = microtime(true);
    $rows = [];
    $lastNew = $started;
    while (microtime(true) - $started < TES_AGENT_GAME_TIMEOUT) {
        $got = $GLOBALS['db']->fetchAll("SELECT id, command, output FROM public.tes_god_console_log WHERE id > {$sinceId} ORDER BY id");
        if (count($got) > count($rows)) {
            $rows = $got;
            $lastNew = microtime(true);
        }
        if (count($rows) >= $expected && microtime(true) - $lastNew > 2) {
            break;
        }
        usleep(400000);
    }
    return array_map(fn($r) => ['command' => $r['command'], 'output' => $r['output']], $rows);
}

/** Read-only bridge/console reads: straight to the queue (nothing to guard). */
function tesAgentRead(array $commands, bool $dry): array
{
    if ($dry) {
        return ['dry_run' => true, 'note' => 'игра не опрошена (сухой режим), считай значения неизвестными', 'commands' => $commands];
    }
    $since = tesAgentConsoleMaxId();
    $queued = herikaQueueGodCommands(implode('; ', $commands));
    if ($queued === 0) {
        return ['error' => 'не удалось поставить в очередь'];
    }
    $reports = tesAgentWaitReports($since, count($commands));
    if (!$reports) {
        return ['error' => 'игра не ответила за ' . TES_AGENT_GAME_TIMEOUT . ' с (пауза, меню или загрузка?)'];
    }
    return ['reports' => $reports];
}

/** Writes: the same path as a Narrator GodCommand (tes_god_guard validate/resolve/autosave -> queue). */
function tesAgentWrite(string $text, bool $dry): array
{
    if ($dry) {
        $check = tesGodGuardValidate($text);
        return ['dry_run' => true, 'would_send' => $check['kept'], 'server' => $check['server'],
            'refused' => $check['reasons']];
    }
    $db = $GLOBALS['db'];
    $since = tesAgentConsoleMaxId();
    $guardSince = intval($db->fetchOne("SELECT coalesce(max(id), 0) AS m FROM public.tes_god_guard_log")['m'] ?? 0);
    $filtered = tesGodGuardFilterAction('The Narrator|command|GodCommand@' . json_encode(['target' => $text], JSON_UNESCAPED_UNICODE));
    $queued = 0;
    if ($filtered !== null) {
        $call = explode('@', explode('|', $filtered)[2] ?? '', 2);
        $kept = trim(strval(json_decode($call[1] ?? '', true)['target'] ?? ''));
        $queued = $kept === '' ? 0 : herikaQueueGodCommands($kept);
    }
    $guard = $db->fetchAll("SELECT * FROM public.tes_god_guard_log WHERE id > {$guardSince} ORDER BY id");
    $verdicts = array_map(function ($g) {
        unset($g['id'], $g['created_at'], $g['raw_text']);
        return array_filter($g, fn($v) => $v !== null && $v !== '' && $v !== '[]' && $v !== '{}');
    }, $guard);
    $result = ['guard' => $verdicts];
    if ($queued > 0) {
        $result['reports'] = tesAgentWaitReports($since, $queued);
        if (!$result['reports']) {
            $result['warning'] = 'игра не прислала отчёт за ' . TES_AGENT_GAME_TIMEOUT . ' с — проверь результат чтением состояния';
        }
    }
    return $result;
}

/** Many writes: herikaQueueGodCommands takes at most 8 commands per text, so send in chunks of 8. */
function tesAgentWriteBatch(array $commands, bool $dry): array
{
    if (!$commands) {
        return ['error' => 'пустой список'];
    }
    $out = [];
    foreach (array_chunk($commands, 8) as $chunk) {
        $out[] = tesAgentWrite(implode('; ', $chunk), $dry);
    }
    return count($out) === 1 ? $out[0] : ['batches' => $out];
}

function tesAgentNumber(array $res): ?float
{
    $out = strval(end($res['reports'])['output'] ?? '');
    return preg_match('/(-?\d+(?:\.\d+)?)\s*$/', trim($out), $m) ? floatval($m[1]) : null;
}

/* ------------------------------------------------------------------ tools */

function tesAgentWho(string $who): string
{
    $who = trim($who);
    return ($who === '' || preg_match('/^(player|игрок|me|я)$/iu', $who)) ? 'player' : '{npc:' . $who . '}';
}

function tesAgentFind(array $a): array
{
    $db = $GLOBALS['db'];
    $kinds = ['item' => "'item'", 'npc' => "'npc'", 'place' => "'cell','location'", 'perk' => "'perk'", 'spell' => "'spell'",
        'quest' => "'quest'", 'faction' => "'faction'", 'enchantment' => "'enchantment'", 'outfit' => "'outfit'"];
    $kind = strval($a['kind'] ?? 'item');
    $where = ['kind IN (' . ($kinds[$kind] ?? "'item'") . ')', "name <> ''"];
    foreach (preg_split('/\s+/u', mb_strtolower(trim(strval($a['query'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY) as $w) {
        $w = $db->escape($w);
        $where[] = "(name_lc LIKE '%{$w}%' OR editor_id_lc LIKE '%{$w}%')";
    }
    // Models often put the filters next to kind/query instead of inside "filters" (dry run
    // 2026-10-03: every filter silently ignored -> heavy daedric armour for "light thief armour").
    $f = array_merge(array_diff_key($a, array_flip(['kind', 'query', 'sort_by', 'limit', 'filters'])),
        is_array($a['filters'] ?? null) ? $a['filters'] : []);
    // Service and test records (REQ_NULL_*, test*, nonPlayable, "-", "0") are never what anyone wants.
    $where[] = "editor_id_lc NOT LIKE '%null%' AND editor_id_lc NOT LIKE '%test%' AND editor_id_lc NOT LIKE '%nonplayable%'"
        . " AND editor_id_lc NOT LIKE '%dummy%' AND name !~ '^[-0-9 .]*$' AND name NOT LIKE '%Test%'";  // C locale: no [[:alpha:]] for Cyrillic
    if ($kind === 'perk' && !empty($f['skill'])) {
        // Requiem names its perks REQ_<Skill>_<Perk> (REQ_Sneak_Stealth1, REQ_Pickpocket_NightlyThief)
        // Requiem's own tree names (counted in tes_game_index 2026-10-03): light armour = Evasion.
        $skill = strtolower(preg_replace('/[^A-Za-z]/', '', strval($f['skill'])));
        $skill = ['lightarmor' => 'evasion', 'marksman' => 'marksmanship', 'archery' => 'marksmanship',
            'speechcraft' => 'speech'][$skill] ?? $skill;
        $where[] = "editor_id_lc LIKE 'req\\_" . $db->escape($skill) . "\\_%'";
    }
    // Summoned / bound gear vanishes, Non-Playable armour cannot be worn by the player.
    $where[] = "editor_id_lc NOT LIKE '%conjure%' AND editor_id_lc NOT LIKE '%bound%' AND extra->>'np' IS NULL";
    $recs = ['armor' => 'ARMO', 'weapon' => 'WEAP', 'potion' => 'ALCH', 'ammo' => 'AMMO', 'scroll' => 'SCRL', 'book' => 'BOOK', 'ingredient' => 'INGR'];
    if (!empty($f['type']) && isset($recs[$f['type']])) {
        $where[] = "extra->>'rec' = '{$recs[$f['type']]}'";
    }
    if (!empty($f['armor_class'])) {
        $where[] = "extra->>'armor' = '" . $db->escape(strval($f['armor_class'])) . "'";
    }
    $slots = ['head' => 30, 'body' => 32, 'hands' => 33, 'feet' => 37, 'amulet' => 35, 'ring' => 36, 'shield' => 39, 'circlet' => 42];
    if (!empty($f['slot']) && isset($slots[$f['slot']])) {
        $where[] = "extra->'slots' @> '[{$slots[$f['slot']]}]'";
    }
    if (!empty($f['weapon_type'])) {
        $where[] = "extra->>'wtype' = '" . $db->escape(strval($f['weapon_type'])) . "'";
    }
    if (isset($f['poison'])) {
        $where[] = $f['poison'] ? "extra->>'poison' = 'true'" : "extra->>'poison' IS NULL";
    }
    if (!empty($f['effect'])) {
        // LIKE is case-sensitive and the C locale cannot fold Cyrillic: try both first-letter cases.
        $eff = mb_strtolower(trim(strval($f['effect'])));
        $effUp = mb_strtoupper(mb_substr($eff, 0, 1)) . mb_substr($eff, 1);
        $where[] = "(extra->>'fx' LIKE '%" . $db->escape($eff) . "%' OR extra->>'fx' LIKE '%" . $db->escape($effUp) . "%')";
    }
    if (!empty($f['keyword'])) {
        $where[] = "extra->'kw' ? '" . $db->escape(strval($f['keyword'])) . "'";
    }
    if (!empty($f['enchanted'])) {
        $where[] = "extra ? 'ench'";
    }
    $sorts = ['armor_rating' => "(extra->>'ar')::float", 'damage' => "(extra->>'dmg')::float", 'value' => "(extra->>'value')::float"];
    $order = $sorts[strval($a['sort_by'] ?? '')] ?? 'length(name)';
    $dir = isset($sorts[strval($a['sort_by'] ?? '')]) ? 'DESC NULLS LAST' : 'ASC';
    $limit = max(1, min(25, intval($a['limit'] ?? 12)));
    $rows = $db->fetchAll("SELECT formid, editor_id, name, plugin, extra FROM public.tes_game_index WHERE " . implode(' AND ', $where) . " ORDER BY {$order} {$dir} LIMIT {$limit}");
    $out = [];
    foreach (is_array($rows) ? $rows : [] as $r) {
        $x = json_decode(strval($r['extra']), true) ?: [];
        unset($x['kw']);  // long; filter by keyword instead
        if (!empty($x['fx'])) {
            $x['fx'] = array_map(fn($e) => trim(($e['n'] ?? '') . ' ' . ($e['m'] ?? '') . ($e['d'] ? " {$e['d']}с" : '')), $x['fx']);
        }
        if (!empty($x['ench'])) {
            $en = $db->fetchOne("SELECT name, extra FROM public.tes_game_index WHERE formid = '" . $db->escape($x['ench']) . "'");
            $enx = json_decode(strval($en['extra'] ?? ''), true) ?: [];
            $x['ench'] = trim(($en['name'] ?? '') . ': ' . implode(', ', array_map(fn($e) => ($e['n'] ?? '') . ' ' . ($e['m'] ?? ''), $enx['fx'] ?? [])));
        }
        $out[] = ['formid' => $r['formid'], 'name' => $r['name'], 'editor_id' => $r['editor_id'], 'plugin' => $r['plugin']] + $x;
    }
    return ['count' => count($out), 'results' => $out];
}

function tesAgentTools(): array
{
    $t = fn($name, $desc, $props, $req = []) => ['type' => 'function', 'function' => ['name' => $name, 'description' => $desc,
        'parameters' => ['type' => 'object', 'properties' => (object)$props, 'required' => $req]]];
    $who = ['type' => 'string', 'description' => 'player (игрок) или имя NPC'];
    $fid = ['type' => 'string', 'description' => 'FormID из find (8 hex)'];
    return [
        $t('find', 'Поиск в данных игры (все моды, характеристики после Requiem). Предметы с характеристиками: ar (броня), dmg, speed, weight, value, armor (light/heavy/clothing), slots, ench, fx (эффекты зелий).', [
            'kind' => ['type' => 'string', 'enum' => ['item', 'npc', 'place', 'perk', 'spell', 'quest', 'faction', 'enchantment', 'outfit']],
            'query' => ['type' => 'string', 'description' => 'слова из названия (рус.) или EditorID; можно пусто, если есть фильтры'],
            'filters' => ['type' => 'object', 'properties' => [
                'type' => ['type' => 'string', 'enum' => ['armor', 'weapon', 'potion', 'ammo', 'scroll', 'book', 'ingredient']],
                'armor_class' => ['type' => 'string', 'enum' => ['light', 'heavy', 'clothing']],
                'slot' => ['type' => 'string', 'enum' => ['head', 'body', 'hands', 'feet', 'amulet', 'ring', 'shield', 'circlet']],
                'weapon_type' => ['type' => 'string', 'enum' => ['dagger', 'sword', 'waraxe', 'mace', 'greatsword', 'battleaxe', 'bow', 'crossbow', 'staff']],
                'poison' => ['type' => 'boolean'], 'enchanted' => ['type' => 'boolean'],
                'effect' => ['type' => 'string', 'description' => 'часть названия эффекта, напр. невидимость'],
                'keyword' => ['type' => 'string', 'description' => 'EditorID ключевого слова, напр. ArmorLight'],
                'skill' => ['type' => 'string', 'description' => 'для kind=perk: навык ветки (Sneak, Pickpocket, Lockpicking, LightArmor, OneHanded, Marksman, Alchemy, Speech...)'],
            ]],
            'sort_by' => ['type' => 'string', 'enum' => ['armor_rating', 'damage', 'value']],
            'limit' => ['type' => 'integer'],
        ], ['kind']),
        $t('get_state', 'Состояние персонажа из игры: уровень, здоровье, все навыки, золото, очки перков, надетое по слотам и оружие в руках.', ['who' => $who]),
        $t('inspect_here', 'Что вокруг игрока в текущей ячейке: название, владелец, двери, контейнеры, NPC, замки.', []),
        $t('check', 'Проверка фактов в игре. kind: item (количество предмета у who), perk (есть ли перк: 1/0), spell (есть ли заклинание), skill (значение навыка, id = имя навыка, напр. Sneak), stage (пройдена ли стадия квеста: id = EditorID квеста, stage).', [
            'kind' => ['type' => 'string', 'enum' => ['item', 'perk', 'spell', 'skill', 'stage']],
            'who' => $who, 'id' => ['type' => 'string'], 'stage' => ['type' => 'integer'],
        ], ['kind', 'id']),
        $t('give_items', 'Выдать предметы списком (equip — сразу надеть/взять в руки).', ['who' => $who, 'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'formid' => $fid, 'count' => ['type' => 'integer'], 'equip' => ['type' => 'boolean']], 'required' => ['formid']]]], ['items']),
        $t('remove_item', 'Забрать предмет.', ['who' => $who, 'formid' => $fid, 'count' => ['type' => 'integer']], ['formid']),
        $t('set_skills', 'Установить базовые значения навыков/характеристик списком: {"Sneak":100,"Lockpicking":100}. Имена: OneHanded TwoHanded Marksman Block Smithing HeavyArmor LightArmor Pickpocket Lockpicking Sneak Alchemy Speechcraft Alteration Conjuration Destruction Illusion Restoration Enchanting Health Magicka Stamina.', ['who' => $who, 'values' => ['type' => 'object']], ['values']),
        $t('add_perks', 'Дать перки списком FormID (из find kind=perk). Предварительные перки ветки давай тоже.', ['who' => $who, 'formids' => ['type' => 'array', 'items' => $fid]], ['formids']),
        $t('add_spell', 'Дать заклинание или способность.', ['who' => $who, 'formid' => $fid], ['formid']),
        $t('set_level', 'Установить уровень игрока.', ['level' => ['type' => 'integer']], ['level']),
        $t('set_relationship', 'Отношение NPC к игроку (-100..100), тип neutral/friend/romantic/lover/rival/enemy и причина.', ['npc' => ['type' => 'string'], 'value' => ['type' => 'integer'], 'type' => ['type' => 'string'], 'reason' => ['type' => 'string']], ['npc', 'value', 'type']),
        $t('teleport_player', 'Перенести игрока в место (название из find kind=place или как его называют в игре).', ['place' => ['type' => 'string']], ['place']),
        $t('move_npc', 'Перенести NPC к игроку.', ['npc' => ['type' => 'string']], ['npc']),
        $t('set_quest_stage', 'Поставить стадию квеста (EditorID квеста из find kind=quest и номер стадии из его stages). Ванильная покупка дома: HousePurchase 10 (Вайтран).', ['quest' => ['type' => 'string'], 'stage' => ['type' => 'integer']], ['quest', 'stage']),
        $t('claim_here', 'Текущий дом/интерьер и всё в нём становится собственностью игрока, замки открываются.', []),
        $t('console', 'Запасной путь: сырая консольная команда Skyrim (проверяется стражем). Только если нет подходящего инструмента.', ['command' => ['type' => 'string']], ['command']),
        $t('finish', 'Цель выполнена. Перечисли ожидания, которые сервер проверит в игре сам; если что-то не сходится — работа продолжится.', [
            'summary' => ['type' => 'string', 'description' => 'что сделано, по-русски, коротко'],
            'expect' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['item', 'perk', 'spell', 'skill', 'stage']],
                'who' => ['type' => 'string'], 'id' => ['type' => 'string'], 'stage' => ['type' => 'integer'],
                'min' => ['type' => 'number', 'description' => 'минимум (для skill/item); для perk/spell/stage — 1'],
            ], 'required' => ['kind', 'id']]],
        ], ['summary', 'expect']),
        $t('give_up', 'Цель невыполнима средствами игры — объясни почему.', ['reason' => ['type' => 'string']], ['reason']),
    ];
}

function tesAgentCheck(array $c, bool $dry): array
{
    $who = tesAgentWho(strval($c['who'] ?? 'player'));
    $id = trim(strval($c['id'] ?? ''));
    switch ($c['kind'] ?? '') {
        case 'item':  $cmd = "{$who}.getitemcount {$id}"; break;
        case 'perk':  $cmd = "{$who}.hasperk {$id}"; break;
        case 'spell': $cmd = "{$who}.hasspell {$id}"; break;
        case 'skill': $cmd = "{$who}.getbaseav {$id}"; break;
        case 'stage': $cmd = "getstagedone {$id} " . intval($c['stage'] ?? 0); break;
        default: return ['error' => 'неизвестный kind'];
    }
    $res = tesAgentRead([$cmd], $dry);
    $res['value'] = isset($res['reports']) ? tesAgentNumber($res) : null;
    return $res;
}

function tesAgentRun(string $name, array $a, bool $dry, array &$finishState)
{
    $who = tesAgentWho(strval($a['who'] ?? 'player'));
    $hex = fn($v) => strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', strval($v)));
    $fid = $hex($a['formid'] ?? '');
    if (in_array($name, ['remove_item', 'add_spell'], true) && !preg_match('/^[0-9A-F]{8}$/', $fid)) {
        return ['error' => 'formid должен быть 8 hex-цифр из find'];
    }
    switch ($name) {
        case 'find':
            return tesAgentFind($a);
        case 'get_state':
            $res = tesAgentRead([$who === 'player' ? 'tesstate' : "{$who}.tesstate"], $dry);
            foreach ($res['reports'] ?? [] as $r) {
                if ($r['command'] === 'tesstate') {
                    return ['state' => $r['output']];
                }
            }
            return $res;
        case 'inspect_here':
            return tesAgentRead(['tesinspect'], $dry);
        case 'check':
            return tesAgentCheck($a, $dry);
        case 'give_items':
            $cmds = [];
            $bad = [];
            foreach (is_array($a['items'] ?? null) ? $a['items'] : [] as $it) {
                $f = $hex(is_array($it) ? ($it['formid'] ?? '') : $it);
                if (!preg_match('/^[0-9A-F]{8}$/', $f)) {
                    $bad[] = $it;
                    continue;
                }
                $cmds[] = "{$who}.additem {$f} " . max(1, min(1000, intval($it['count'] ?? 1)));
                if (!empty($it['equip'])) {
                    $cmds[] = "{$who}.equipitem {$f}";
                }
            }
            return tesAgentWriteBatch($cmds, $dry) + ($bad ? ['bad_formids' => $bad] : []);
        case 'remove_item':
            return tesAgentWrite("{$who}.removeitem {$fid} " . max(1, intval($a['count'] ?? 1)), $dry);
        case 'set_skills':
            // actor value names (Speech/Archery/Evasion are menu or Requiem tree names, not AVs)
            $alias = ['speech' => 'Speechcraft', 'archery' => 'Marksman', 'marksmanship' => 'Marksman', 'evasion' => 'LightArmor'];
            $cmds = [];
            foreach (is_array($a['values'] ?? null) ? $a['values'] : [] as $skill => $value) {
                $skill = preg_replace('/[^A-Za-z]/', '', strval($skill));
                $skill = $alias[strtolower($skill)] ?? $skill;
                $cmds[] = "{$who}.setav {$skill} " . floatval($value);
            }
            return tesAgentWriteBatch($cmds, $dry);
        case 'add_perks':
            $cmds = [];
            foreach (is_array($a['formids'] ?? null) ? $a['formids'] : [$a['formids'] ?? ''] as $f) {
                $f = $hex($f);
                if (preg_match('/^[0-9A-F]{8}$/', $f)) {
                    $cmds[] = "{$who}.addperk {$f}";
                }
            }
            return tesAgentWriteBatch($cmds, $dry);
        case 'add_spell':
            return tesAgentWrite("{$who}.addspell {$fid}", $dry);
        case 'set_level':
            return tesAgentWrite('player.setlevel ' . max(1, min(500, intval($a['level'] ?? 1))), $dry);
        case 'set_relationship':
            $reason = str_replace([';', "\n"], [',', ' '], strval($a['reason'] ?? ''));
            return tesAgentWrite('{npc:' . trim(strval($a['npc'] ?? '')) . '}.relation ' . intval($a['value'] ?? 0) . ' '
                . preg_replace('/[^a-z]/', '', strtolower(strval($a['type'] ?? 'neutral'))) . ' ' . $reason, $dry);
        case 'teleport_player':
            return tesAgentWrite('coc {cell:' . str_replace(['{', '}', ';'], '', strval($a['place'] ?? '')) . '}', $dry);
        case 'move_npc':
            return tesAgentWrite('{npc:' . trim(strval($a['npc'] ?? '')) . '}.moveto player', $dry);
        case 'set_quest_stage':
            return tesAgentWrite('setstage ' . preg_replace('/[^A-Za-z0-9_]/', '', strval($a['quest'] ?? '')) . ' ' . intval($a['stage'] ?? 0), $dry);
        case 'claim_here':
            if (!$dry && function_exists('tesGodAutosaveIfNeeded')) {
                tesGodAutosaveIfNeeded('tes_agent claim_here');
            }
            return tesAgentRead(['tesclaim'], $dry);
        case 'console':
            return tesAgentWrite(str_replace(';', ' ', strval($a['command'] ?? '')), $dry);
        case 'finish':
            $failed = [];
            foreach (is_array($a['expect'] ?? null) ? $a['expect'] : [] as $e) {
                $res = tesAgentCheck($e, $dry);
                $min = floatval($e['min'] ?? 1);
                if ($dry) {
                    continue;
                }
                if (!isset($res['value']) || $res['value'] < $min) {
                    $failed[] = ['expect' => $e, 'actual' => $res['value'] ?? ($res['error'] ?? 'нет ответа')];
                }
            }
            if ($failed && $finishState['rejects'] < 2) {
                $finishState['rejects']++;
                return ['finished' => false, 'not_met' => $failed, 'note' => 'Проверка в игре не сошлась — исправь и снова finish.'];
            }
            $finishState['done'] = true;
            $finishState['summary'] = strval($a['summary'] ?? '');
            $finishState['failed'] = $failed;
            return ['finished' => true, 'not_met' => $failed];
        case 'give_up':
            $finishState['done'] = true;
            $finishState['gave_up'] = true;
            $finishState['summary'] = strval($a['reason'] ?? '');
            return ['ok' => true];
    }
    return ['error' => "нет инструмента {$name}"];
}

/* ------------------------------------------------------------------ loop */

$player = strval($GLOBALS['PLAYER_NAME'] ?? 'игрок');
$system = "Ты — исполнитель воли бога-Нарратора в Skyrim SE (сборка Requiem/RFAD, русская локализация). Игрок: {$player}. "
    . "Тебе дают цель словами игрока. Сам разберись, что она значит в механиках игры, и добейся её инструментами. Правил вида «если X, то Y» нет — думай.\n"
    . "Порядок: сначала наблюдай (get_state, inspect_here, find), потом действуй, после важных действий проверяй (check/get_state). "
    . "ID никогда не выдумывай — только из find. «Лучшее» выбирай сравнением характеристик из find (ar, dmg, ench, fx), учитывай класс и слот. "
    . "Читай ench у кандидатов: проклятые вещи, которые вредят носителю (огромный урон здоровью, «проклятая»), игроку не давай. "
    . "Названия в RFAD часто с префиксом-категорией, напр. «[Алкоголь] Эль». Навыки максимум 100. Ты — бог: ролевых ограничений нет, предел — только движок. "
    . "Не трать шаги зря: один find возвращает до 25 кандидатов — не повторяй тот же запрос; одна выдача может быть с equip; можно вызывать несколько инструментов сразу. "
    . "Перки ищи по ветке: find kind=perk filters.skill=Sneak (без query) — получишь всю ветку. "
    . "Ошибку инструмента читай и исправляй причину, не повторяй то же самое. "
    . "Закончи finish с проверяемыми ожиданиями (предметы, перки, навыки, стадии) — сервер их сверит в игре. Если невозможно — give_up с причиной. "
    . "Лимит: " . TES_AGENT_MAX_STEPS . " вызовов инструментов.";
$messages = [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => 'Цель: ' . $task['goal']]];
$tools = tesAgentTools();
$cost = 0.0;
$steps = 0;
$nudges = 0;
$finish = ['done' => false, 'rejects' => 0, 'summary' => '', 'failed' => [], 'gave_up' => false];
$transcript = [];
$started = time();

if (!$dry) {
    tesAgentNotify('Нарратор: ' . mb_substr($task['goal'], 0, 120));
}

while (!$finish['done'] && $steps < TES_AGENT_MAX_STEPS && time() - $started < TES_AGENT_MAX_SECONDS) {
    $msg = tesAgentLlm($messages, $tools, $cost);
    if ($msg === null) {
        $finish['summary'] = 'модель не ответила';
        break;
    }
    $calls = $msg['tool_calls'] ?? [];
    $messages[] = array_filter(['role' => 'assistant', 'content' => $msg['content'] ?? '', 'tool_calls' => $calls ?: null], fn($v) => $v !== null);
    if (!$calls) {
        if (++$nudges > 2) {
            $finish['summary'] = 'модель перестала вызывать инструменты: ' . mb_substr(strval($msg['content'] ?? ''), 0, 200);
            break;
        }
        $messages[] = ['role' => 'user', 'content' => 'Продолжай через инструменты. Когда цель достигнута — finish, если невозможно — give_up.'];
        continue;
    }
    foreach ($calls as $call) {
        $steps++;
        $name = strval($call['function']['name'] ?? '');
        $argsIn = json_decode(strval($call['function']['arguments'] ?? '{}'), true);
        $argsIn = is_array($argsIn) ? $argsIn : [];
        // MiMo-style schema slips: a list passed as a comma string, etc. are tolerated by the
        // tools themselves (they read scalars); anything unknown is an error result, never a write.
        try {
            $result = tesAgentRun($name, $argsIn, $dry, $finish);
        } catch (Throwable $e) {
            $result = ['error' => $e->getMessage()];
        }
        $json = json_encode($result, JSON_UNESCAPED_UNICODE);
        if (mb_strlen($json) > 3500) {
            $json = mb_substr($json, 0, 3500) . '…(обрезано)';
        }
        echo sprintf("  [%02d] %s %s\n       -> %s\n", $steps, $name, json_encode($argsIn, JSON_UNESCAPED_UNICODE), mb_substr($json, 0, 600));
        $transcript[] = ['tool' => $name, 'args' => $argsIn, 'result' => mb_substr($json, 0, 1500)];
        $messages[] = ['role' => 'tool', 'tool_call_id' => strval($call['id'] ?? ''), 'content' => $json];
        $db->execQuery("UPDATE public.tes_agent_tasks SET steps = {$steps}, cost = {$cost}, updated_at = now(), transcript = '"
            . $db->escape(json_encode($transcript, JSON_UNESCAPED_UNICODE)) . "' WHERE id = {$taskId}");
        if ($finish['done']) {
            break;
        }
    }
}

$status = $finish['done'] ? ($finish['gave_up'] ? 'gave_up' : ($finish['failed'] ? 'failed' : 'done')) : 'failed';
if (!$finish['done'] && $finish['summary'] === '') {
    $finish['summary'] = "не успел: лимит шагов или времени (шагов {$steps})";
}
$db->execQuery("UPDATE public.tes_agent_tasks SET status = '{$status}', steps = {$steps}, cost = {$cost}, updated_at = now(), result = '"
    . $db->escape($finish['summary']) . "' WHERE id = {$taskId}");
echo "== {$status}: {$finish['summary']} | steps {$steps} | \$" . round($cost, 5) . "\n";

if (!$dry) {
    $notMet = $finish['failed'] ? ' Не сошлось при проверке: ' . mb_substr(json_encode($finish['failed'], JSON_UNESCAPED_UNICODE), 0, 300) : '';
    $what = $status === 'done' ? 'Ты выполнил волю игрока' : ($status === 'gave_up' ? 'Это оказалось невозможно' : 'Выполнено не полностью');
    tesAgentNarratorSay("(Сообщи игроку итог в своём стиле, 1-2 фразы, по-русски, без технических ID. {$what}. Цель: {$task['goal']}. Итог: {$finish['summary']}.{$notMet})", $taskId);
}
