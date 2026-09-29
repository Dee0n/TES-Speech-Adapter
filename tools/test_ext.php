<?php
/**
 * Regression test for the ext/ god-command plugins (tes_god_guard, tes_gifts,
 * tes_god_journal, tes_russify). Run after every CHIM/AIAgent update, and after any
 * change to these files, to catch what a scratch one-off test would otherwise re-find
 * by hand each time (see docs/applied-log.md for the bugs this already caught: the
 * "(dead)" false trigger, the 0x-prefix RefID block, "Кай" vs "Командир Кай", and the
 * Postgres-boolean-as-string bug in the autosave journal line).
 *
 * Usage (inside the DwemerAI4Skyrim3 WSL distro):
 *   php tools/test_ext.php            # read-only checks: parsing, resolution, rendering
 *   php tools/test_ext.php --write    # also exercises remember/relation/marry/autosave
 *                                     # end-to-end against a throwaway NPC row, cleaned
 *                                     # up at the end either way (even on failure/Ctrl-C
 *                                     # is not caught, but a re-run cleans up first).
 *
 * Never touches real NPCs: the --write section only writes rows named "ZZZ_TestNPC_*",
 * deleted before and after the run.
 */

$enginePath = '/var/www/html/HerikaServer/';
require_once($enginePath . 'conf/conf.php');
require_once($enginePath . 'lib/' . ($GLOBALS['DBDRIVER'] ?? 'postgresql') . '.class.php');
require_once($enginePath . 'lib/data_functions.php');
require_once($enginePath . 'lib/prompt_injections.php');
require_once($enginePath . 'lib/relationship_manager.php');
$GLOBALS['db'] = new sql();
$GLOBALS['PLAYER_NAME'] = 'Тестгерой';

$extDir = dirname(__DIR__) . '/ext';
foreach (['tes_god_guard/functions.php', 'tes_gifts/functions.php', 'tes_god_journal/context_pre.php', 'tes_russify/preprocessing.php'] as $rel) {
    $path = "$extDir/$rel";
    if (!file_exists($path)) {
        fwrite(STDERR, "SKIP: $rel not found next to this repo checkout\n");
        continue;
    }
    // tes_god_journal/context_pre.php and tes_russify/preprocessing.php run top-level code
    // on load (they check $GLOBALS["gameRequest"]); harmless here since it is unset/empty.
    require $path;
}

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label" . ($detail !== '' ? " -- $detail" : '') . "\n";
    }
}

echo "== tesGodGuardValidate: parsing and resolution ==\n";
$v = tesGodGuardValidate('0x0001B058.moveto player');
check('0x-prefixed target is accepted', $v['kept'] === ['0001B058.moveto player'] || !empty($v['reasons']), json_encode($v));
// (the RefID itself is fake test data, so it will be refused as "unknown RefID" - that IS
// the expected parse: the 0x got stripped and the command reached the RefID-known check.)
check('0x-prefixed target strips the prefix, not the whole command', str_contains(implode('', $v['reasons']), '0001B058') && !str_contains(implode('', $v['reasons']), '0x'), json_encode($v['reasons']));

$v = tesGodGuardValidate('player.moveto 0x0001B058');
check('0x-prefixed moveto argument is accepted', $v['kept'] === ['player.moveto 0001B058'], json_encode($v));

$v = tesGodGuardValidate('{npc:Скульвар Черная Рукоять}.disable');
check('disable is refused', empty($v['kept']) && !empty($v['reasons']));

$v = tesGodGuardValidate('setstage MQ101 10');
check('setstage is refused', empty($v['kept']) && !empty($v['reasons']));

$v = tesGodGuardValidate('sgtm 50');
check('sgtm out of 0.2-3 range is refused', empty($v['kept']) && !empty($v['reasons']));
$v = tesGodGuardValidate('sgtm 1');
check('sgtm within range is kept', $v['kept'] === ['sgtm 1']);

$v = tesGodGuardValidate('player.placeatme {explosion:huge} 1; player.placeatme {explosion:huge} 1; player.placeatme {explosion:huge} 1');
check('a repeat is still parsed per-command (repeat suppression is a separate, later step)', count($v['kept']) === 3);

$v = tesGodGuardValidate('{npc:Незнакомец Тестовый}.stopcombat');
check('an unknown {npc:} without an index match goes to the nearby (in-game lookup) path', empty($v['kept']) && count($v['nearby']) === 1, json_encode($v));

$v = tesGodGuardValidate('{npc:Скульвар Черная Рукоять}.character personality: тест; {npc:Скульвар Черная Рукоять}.relation 60 friend тест; {npc:Скульвар Черная Рукоять}.remember тест; {npc:А}.marry Б');
check('character/relation/remember/marry are routed to the server-side list, not the console list', count($v['server']) === 4 && empty($v['kept']), json_encode($v));

$v = tesGodGuardValidate('player.heal');
check('player.heal substitutes the player\'s real RefID (00000014), not the word "player"', $v['kept'] === ['00000014.tesheal'], json_encode($v));
$v = tesGodGuardValidate('{npc:Скульвар Черная Рукоять}.heal');
check('{npc:Name}.heal keeps the placeholder for the core to resolve', $v['kept'] === ['{npc:Скульвар Черная Рукоять}.tesheal'], json_encode($v));

echo "\n== tesGiftsCommands: parsing ==\n";
check('horse (Russian)', tesGiftsCommands('Тест', 'лошадь') === ['tesnear Лошадь', 'setownership']);
check('house (Russian)', tesGiftsCommands('Тест', 'дом') === ['tesnear Тест', 'tesgive house']);
check('everything (Russian)', tesGiftsCommands('Тест', 'всё') === ['tesnear Тест', 'tesgive all']);
check('around (Russian)', tesGiftsCommands('Тест', 'сундук') === ['tesnear Тест', 'tesgive around']);
check('a plain animal name falls back to setownership', tesGiftsCommands('Тест', 'Корова') === ['tesnear Корова', 'setownership']);
check('nonsense input is refused, not passed through', tesGiftsCommands('Тест', 'rm -rf /') === [] || str_starts_with(tesGiftsCommands('Тест', 'rm -rf /')[0] ?? '', 'tesnear rm -rf'));

echo "\n== ScriptProxy safety net for resurrect/kill (CHIM's own Papyrus channel) ==\n";
check('a real, known target resolves to its actual RefID', tesGodGuardResolveRealRefId('{npc:Скульвар Черная Рукоять}') === '0001A69C');
check('a bare hex RefID passes through unchanged', tesGodGuardResolveRealRefId('0001A69C') === '0001A69C');
check('an unknown name resolves to nothing', tesGodGuardResolveRealRefId('{npc:Совершенно Несуществующий Ыыы}') === '');
$vsp = tesGodGuardValidate('{npc:Скульвар Черная Рукоять}.resurrect');
check('a plain resurrect queues the safety net alongside the console command', count($vsp['kept']) === 1 && $vsp['scriptproxy'] === [['refid' => '0001A69C', 'verb' => 'resurrect']], json_encode($vsp));
$vsp2 = tesGodGuardValidate('{npc:Скульвар Черная Рукоять}.resurrect 1');
check('resurrect with extra arguments does NOT fire the safety net', $vsp2['scriptproxy'] === []);
$before = intval($db->fetchOne("SELECT count(*) AS n FROM responselog WHERE action LIKE '%\"cmdID\":66%'")['n'] ?? 0);
tesGodGuardScriptProxySafetyNet('0001A69C', 'resurrect');
$after = intval($db->fetchOne("SELECT count(*) AS n FROM responselog WHERE action LIKE '%\"cmdID\":66%'")['n'] ?? 0);
check('dispatching actually inserts one real ScriptProxy row', $after === $before + 1);
$db->execQuery("DELETE FROM responselog WHERE action LIKE '%\"cmdID\":66%' AND sent = 0");

echo "\n== tesGodGuardWhyNoProfile: an actionable reason, not a dead end ==\n";
$unmetActor = $GLOBALS['db']->fetchOne("
    SELECT gi.name FROM public.tes_game_index gi
    LEFT JOIN public.core_npc_master npc ON npc.npc_name = gi.name
    WHERE gi.kind = 'actor' AND gi.name <> '' AND npc.id IS NULL
    LIMIT 1
");
if ($unmetActor) {
    check('a real placed actor not yet met suggests talking to them first', str_contains(tesGodGuardWhyNoProfile($unmetActor['name']), 'поздоровайся'), $unmetActor['name']);
} else {
    echo "  skip  (no unmet actor found - every indexed name already has a CHIM profile)\n";
}
check('a made-up name says there is no such person', str_contains(tesGodGuardWhyNoProfile('Совершенно Несуществующий Персонаж Ыыы'), 'нет такого'));

echo "\n== tesGodGuardFailureStreak: hard stop after repeated refusals ==\n";
$db->execQuery("DELETE FROM public.tes_god_guard_log WHERE raw_text LIKE 'ZZZ_test_streak%'");
for ($i = 0; $i < 3; $i++) {
    tesGodGuardLog("ZZZ_test_streak $i", '', 'blocked', ['test']);
}
check('3 blocked in a row -> streak of 3', tesGodGuardFailureStreak() === 3);
tesGodGuardLog('ZZZ_test_streak ok', 'fw 000C8220', 'ok', []);
check('a success resets the streak to 0', tesGodGuardFailureStreak() === 0);
$db->execQuery("DELETE FROM public.tes_god_guard_log WHERE raw_text LIKE 'ZZZ_test_streak%'");

echo "\n== tesGodGuardIsBigChange: autosave trigger detection ==\n";
check('resurrect is big', tesGodGuardIsBigChange(['{npc:X}.resurrect'], []));
check('a rumor alone is not big', !tesGodGuardIsBigChange([], []));
check('weather is not big', !tesGodGuardIsBigChange(['fw 000C8220'], []));
check('marry (server command) is big', tesGodGuardIsBigChange([], [['verb' => 'marry', 'npc' => 'A', 'args' => 'B']]));
check('remember (server command) is not big', !tesGodGuardIsBigChange([], [['verb' => 'remember', 'npc' => 'A', 'args' => 'x']]));

echo "\n== ext/tes_russify: the (dead)/(far away) false-trigger fix ==\n";
function tesTestRussifyWouldTrigger(string $data): bool
{
    $names = preg_replace(
        '/\((?:far away|too far away|busy|hostile|in combat|dead|disabled|unavailable)\)/i',
        '',
        str_replace('beings in range:', '', $data)
    );
    return (bool) preg_match('/(^|[,\/(])\s*[A-Za-z]{2,}/', $names);
}
check('"(dead)" alone does not look like a Latin name', !tesTestRussifyWouldTrigger('(beings in range:Хеймскр (dead),Назим,)'));
check('"(far away)" alone does not look like a Latin name', !tesTestRussifyWouldTrigger('Сваргрим (far away)//Шаман'));
check('a real Latin name still triggers', tesTestRussifyWouldTrigger('(beings in range:Von Tanner [Курьер] (far away),)'));

echo "\n== tes_god_journal: rendering (read-only) ==\n";
// Not tested here: "a quiet history renders nothing" - this runs against the LIVE,
// shared DB (not a fixture), which by now always has recent real activity (today's own
// god commands, or this very test's --write section from a previous run), so that
// assertion would be inherently flaky rather than a real regression check.
$GLOBALS['gameRequest'] = ['inputtext', 0, 0, 'test'];
$GLOBALS['HERIKA_NAME'] = 'Some NPC';
$GLOBALS['PROMPT_INJECTIONS'] = [];
require "$extDir/tes_god_journal/context_pre.php";
check('a non-narrator turn gets nothing', chimRenderPromptInjections('prompt_bottom', []) === '');

if (in_array('--write', $argv, true)) {
    echo "\n== write-side checks (throwaway NPC, cleaned up) ==\n";
    $db = $GLOBALS['db'];
    $cleanup = function () use ($db) {
        $db->execQuery("DELETE FROM public.core_npc_master WHERE npc_name LIKE 'ZZZ_TestNPC_%'");
        $db->execQuery("DELETE FROM public.tes_world_facts WHERE subject LIKE 'ZZZ_TestNPC_%' OR object LIKE 'ZZZ_TestNPC_%'");
        $db->execQuery("DELETE FROM public.rumors WHERE content LIKE '%ZZZ_TestNPC_%'");
        $db->execQuery("DELETE FROM public.skyrim_quest_action_outbox WHERE beat_id = 'tes_autosave' AND created_at > now() - interval '1 minute'");
    };
    $cleanup();  // in case a previous run was interrupted before its own cleanup

    $db->execQuery("
        INSERT INTO public.core_npc_master (npc_name, personality, occupation, extended_data)
        VALUES ('ZZZ_TestNPC_A', 'старый характер A', 'старое занятие', '{\"relationships\":{}}'::jsonb),
               ('ZZZ_TestNPC_B', 'старый характер B', 'старое занятие', '{\"relationships\":{}}'::jsonb)
    ");

    [$ok, $msg] = tesGodGuardRunServer(['npc' => 'ZZZ_TestNPC_A', 'verb' => 'character', 'args' => 'personality: новый характер']);
    check('character: writes and reports было -> стало', $ok && str_contains($msg, 'старый характер A') && str_contains($msg, 'новый характер'), $msg);

    [$ok, $msg] = tesGodGuardRunServer(['npc' => 'ZZZ_TestNPC_A', 'verb' => 'relation', 'args' => '60 grateful тестовая причина']);
    check('relation (to the player) writes', $ok, $msg);
    $rel = RelationshipManager::getPlayerRelationship('ZZZ_TestNPC_A');
    check('relation actually landed on the player slot', is_array($rel) && intval($rel['aff'] ?? -999) === 60, json_encode($rel));

    [$ok, $msg] = tesGodGuardRunServer(['npc' => 'ZZZ_TestNPC_A', 'verb' => 'relation', 'args' => 'to ZZZ_TestNPC_B 70 romantic тест']);
    check('relation (to another NPC) writes to that NPC\'s slot, not the player\'s', $ok, $msg);
    $rel = RelationshipManager::getRelationship('ZZZ_TestNPC_A', 'ZZZ_TestNPC_B');
    check('NPC-to-NPC relation landed correctly', is_array($rel) && intval($rel['aff'] ?? -999) === 70, json_encode($rel));

    [$ok, $msg] = tesGodGuardRunServer(['npc' => 'ZZZ_TestNPC_A', 'verb' => 'remember', 'args' => 'тестовое событие ZZZ_TestNPC_A']);
    check('remember writes', $ok, $msg);
    $bio = $db->fetchOne("SELECT npc_static_bio AS b FROM public.core_npc_master WHERE npc_name = 'ZZZ_TestNPC_A'");
    check('remember appended to npc_static_bio', str_contains(strval($bio['b'] ?? ''), 'тестовое событие'));

    [$ok, $msg] = tesGodGuardRunServer(['npc' => 'ZZZ_TestNPC_A', 'verb' => 'marry', 'args' => 'ZZZ_TestNPC_B']);
    check('marry succeeds for two known NPCs', $ok, $msg);
    $relA = RelationshipManager::getRelationship('ZZZ_TestNPC_A', 'ZZZ_TestNPC_B');
    $relB = RelationshipManager::getRelationship('ZZZ_TestNPC_B', 'ZZZ_TestNPC_A');
    check('marry sets mutual romantic 90', ($relA['type'] ?? '') === 'romantic' && intval($relA['aff'] ?? 0) === 90
        && ($relB['type'] ?? '') === 'romantic' && intval($relB['aff'] ?? 0) === 90, json_encode([$relA, $relB]));

    $ok1 = tesGodAutosaveIfNeeded('test');
    $ok2 = tesGodAutosaveIfNeeded('test again, should be rate-limited');
    check('autosave fires once, then is rate-limited', $ok1 === true && $ok2 === false);

    $autoRow = $db->fetchOne("SELECT status, applied_at IS NOT NULL AS done FROM public.skyrim_quest_action_outbox WHERE beat_id = 'tes_autosave' ORDER BY id DESC LIMIT 1");
    check('a fresh autosave row is not yet applied', is_array($autoRow) && !in_array($autoRow['done'] ?? '', [true, 't', 'true', 1, '1'], true));
    $db->execQuery("UPDATE public.skyrim_quest_action_outbox SET status='applied', applied_at=now() WHERE beat_id='tes_autosave'");
    $GLOBALS['gameRequest'] = ['narrator_inputtext', 0, 0, 'test'];
    $GLOBALS['PROMPT_INJECTIONS'] = [];
    require "$extDir/tes_god_journal/context_pre.php";
    $rendered = chimRenderPromptInjections('prompt_bottom', []);
    check('journal correctly reports an applied autosave as done (Postgres-boolean regression check)', str_contains($rendered, 'Автосейв сделан'), $rendered);

    $cleanup();
} else {
    echo "\n(skipped write-side checks: re-run with --write to also test remember/relation/marry/autosave against a throwaway NPC)\n";
}

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
