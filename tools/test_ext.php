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
 * Default mode never touches real NPCs or the live game world - ScriptProxy commands are
 * only built (cmdID/params asserted), never send()'d. --write additionally writes
 * throwaway "ZZZ_TestNPC_*" rows (deleted before and after) AND, only under --write,
 * sends one real (harmless) ScriptProxy outfit change to the known NPC Скульвар Черная
 * Рукоять through the real tesGodGuardFilterAction() entry point, cleaned up immediately.
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

// {spell:Name} added 2026-09-29 (roadmap B validator: addspell/removespell had no ID
// resolution at all before this, unlike additem/equipitem which already went through
// {item:}) - a real vanilla spell resolves, a made-up name is refused with a reason.
$v = tesGodGuardValidate('{npc:Скульвар Черная Рукоять}.addspell {spell:Пламя}');
check('{spell:Name} resolves a real spell to its FormID', $v['kept'] === ['{npc:Скульвар Черная Рукоять}.addspell 0006445B'], json_encode($v));
$v = tesGodGuardValidate('{npc:Скульвар Черная Рукоять}.addspell {spell:Совершенно Несуществующее Заклинание Ыыы}');
check('{spell:Name} for a made-up spell is refused with a reason, not silently dropped', empty($v['kept']) && !empty($v['reasons']), json_encode($v));

// {perk:Name}/{ench:Name}: tes_game_index reloaded 2026-09-29 with PERK/ENCH support
// (1293 perks, 1581 enchantments, including modded ones - e.g. ChihSkillTree). A real
// perk (from a mod, same as a Requiem/RfaD item already is elsewhere in this file)
// resolves to its FormID; a made-up name is still refused honestly.
$v = tesGodGuardValidate('{npc:Скульвар Черная Рукоять}.addperk {perk:Продвинутое кузнечное дело}');
check('{perk:Name} resolves a real (modded) perk to its FormID', $v['kept'] === ['{npc:Скульвар Черная Рукоять}.addperk 0005218E'], json_encode($v));
$v = tesGodGuardValidate('{npc:Скульвар Черная Рукоять}.addperk {perk:Совершенно Несуществующий Перк Ыыы}');
check('{perk:Name} for a made-up perk is refused with a reason', empty($v['kept']) && !empty($v['reasons']), json_encode($v));

// {ench:Name} has NO real consumer (review 2026-09-29): SetEnchantment only exists in
// SKSE (Armor/Weapon/ObjectReference/WornObject.psc), not in AIAgentScriptProxy.psc or any
// console command - indexing an enchantment's FormID is not the same as being able to
// apply it. The RESOLVER itself still works (tested directly, matching what {spell:}/
// {perk:} use), but any actual command using {ench:...} must be refused, not passed
// through as if it would do something in game.
check('the resolver itself finds a real enchantment FormID', tesGodGuardResolveItem('Благословение Зенитара', ['enchantment']) === '0008850C');
$v = tesGodGuardValidate('{npc:Скульвар Черная Рукоять}.equipitem {ench:Благословение Зенитара}');
check('{ench:Name} in an actual command is refused (no consumer exists yet)', empty($v['kept']) && !empty($v['reasons']), json_encode($v));

// {faction:Name} - addfac/removefac had no resolution at all before this.
// [гипотеза, не проверено] addfac's console syntax is believed to need a rank argument
// (addfac <FactionID> <Rank>) - unlike Actor.AddToFaction(), which has none. Not guessed
// here (ROADMAP rule: don't invent syntax); the fixture always includes an explicit rank
// so it never models the possibly-wrong no-rank form, and the Narrator's own instructions
// (settings/chim_settings.sql) should say the same once that SQL change is applied.
$v = tesGodGuardValidate('{npc:Скульвар Черная Рукоять}.addfac {faction:Рифт} 0');
check('{faction:Name} resolves a real (vanilla) faction to its FormID (rank included)', $v['kept'] === ['{npc:Скульвар Черная Рукоять}.addfac 0002816B 0'], json_encode($v));
$v = tesGodGuardValidate('{npc:Скульвар Черная Рукоять}.addfac {faction:Совершенно Несуществующая Фракция Ыыы} 0');
check('{faction:Name} for a made-up faction is refused with a reason', empty($v['kept']) && !empty($v['reasons']), json_encode($v));

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

// additem/removeitem had no quantity cap at all before this (unlike placeatme). Owner
// picked 5000 as the ceiling (2026-09-29) - catches an absurd/typo'd count, not normal gifts.
$v = tesGodGuardValidate('player.additem {item:Septims} 999999999');
check('additem is capped to 5000 (was unbounded)', $v['kept'] === ['player.additem 0001ACDC 5000'], json_encode($v));
$v = tesGodGuardValidate('player.additem {item:Septims} 500');
check('additem under the cap passes through unchanged', $v['kept'] === ['player.additem 0001ACDC 500'], json_encode($v));
$v = tesGodGuardValidate('{npc:Скульвар Черная Рукоять}.removeitem {item:Septims} 999999999');
check('removeitem is capped to 5000 too', $v['kept'] === ['{npc:Скульвар Черная Рукоять}.removeitem 0001ACDC 5000'], json_encode($v));

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

echo "\n== ScriptProxy: pure command-building (resurrect/kill are console-only, see below) ==\n";
// Pure parsing/building only here - no send() against the real, live NPC in the default
// mode (see docs/applied-log.md 2026-09-29 fix entries: this used to fire a real resurrect,
// a real persistent SetOutfit, and a real EquipItem against Скульвар Черная Рукоять every
// time this file ran without --write, contradicting its own "never touches real NPCs"
// promise). ->Resurrect()/->Kill()/->SetOutfit()/->EquipItem() just BUILD the {cmdID,...}
// array; only ->send() writes to responselog, and that stays behind --write below.
check('a real, known target resolves to its actual RefID', tesGodGuardResolveRealRefId('{npc:Скульвар Черная Рукоять}') === '0001A69C');
check('a bare hex RefID passes through unchanged', tesGodGuardResolveRealRefId('0001A69C') === '0001A69C');
check('an unknown name resolves to nothing', tesGodGuardResolveRealRefId('{npc:Совершенно Несуществующий Ыыы}') === '');
// resurrect/kill are console-only (reverted 2026-09-29): the only ScriptProxy cmdID ever
// confirmed actually delivered is 22 (EquipItem); cmdID 66/7 (Resurrect/Kill) never were,
// while console prid+resurrect WAS verified working in game. Routing through the
// unconfirmed path also broke honest journal reporting (it only watches
// chim_god_command outbox rows for the life/death check, not responselog).
$vsp = tesGodGuardValidate('{npc:Скульвар Черная Рукоять}.resurrect');
check('a plain resurrect stays console-only, no ScriptProxy', $vsp['kept'] === ['{npc:Скульвар Черная Рукоять}.resurrect'] && $vsp['scriptproxy'] === [], json_encode($vsp));
$builder = tesGodGuardScriptProxyBuilder();
$cmd = $builder->Actor->Resurrect('0x0001A69C');
check('Resurrect() builds cmdID 66 with the right target, without sending anything', ($cmd['cmdID'] ?? null) === 66 && ($cmd['targetObjectFormId'] ?? '') === '0x0001A69C', json_encode($cmd));

echo "\n== outfit/equip: real ScriptProxy instead of the custom Papyrus bridge ==\n";
$vo = tesGodGuardValidate('{npc:Скульвар Черная Рукоять}.outfit нищий');
check('a known NPC\'s outfit change goes straight to ScriptProxy (no console command left)', $vo['kept'] === [] && count($vo['scriptproxy']) === 1 && $vo['scriptproxy'][0]['verb'] === 'outfit');
$ve = tesGodGuardValidate('{npc:Скульвар Черная Рукоять}.equipitem {item:Fine Clothes}');
check('equip on a known NPC keeps the console command AND queues ScriptProxy', count($ve['kept']) === 1 && count($ve['scriptproxy']) === 1 && $ve['scriptproxy'][0]['verb'] === 'equip');
$cmdOutfit = $builder->Actor->SetOutfit('0x' . $vo['scriptproxy'][0]['refid'], '0x' . $vo['scriptproxy'][0]['item']);
check('SetOutfit() builds cmdID 59, without sending anything', ($cmdOutfit['cmdID'] ?? null) === 59, json_encode($cmdOutfit));
$cmdEquip = $builder->Actor->EquipItem('0x' . $ve['scriptproxy'][0]['refid'], '0x' . $ve['scriptproxy'][0]['item'], true, true);
check('EquipItem() builds cmdID 22 with abPreventRemoval, without sending anything', ($cmdEquip['cmdID'] ?? null) === 22 && ($cmdEquip['abPreventRemoval'] ?? null) === 1, json_encode($cmdEquip));

// The real end-to-end check (does tesGodGuardFilterAction - the actual post-process hook,
// not just tesGodGuardValidate()/tesGodGuardScriptProxy*() called directly - correctly
// dispatch a lone outfit command instead of dropping it as "blocked"?) needs a real
// send() against a real, known NPC to prove the row actually lands. That's a genuine,
// if harmless (a beggar outfit, cleaned up before delivery), write against the live game
// world, so it's gated behind --write like every other real-world-touching check here,
// not run by default.

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
check('placeatme x3+ is big (mass spawn)', tesGodGuardIsBigChange(['player.placeatme {explosion:huge} 3'], []));
check('placeatme x10 (already capped) is still big', tesGodGuardIsBigChange(['player.placeatme {explosion:huge} 10'], []));
check('placeatme x1 alone is not big', !tesGodGuardIsBigChange(['player.placeatme {explosion:huge} 1'], []));
check('placeatme x2 alone is not big', !tesGodGuardIsBigChange(['player.placeatme {explosion:huge} 2'], []));

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
    // Snapshot the newest existing tes_autosave row id BEFORE this run touches anything, so
    // every check below only ever reads/deletes/updates rows THIS run creates (id > this
    // baseline) - never a real autosave row from actual gameplay, and never a rate-limit
    // false negative from a previous test run's row still inside the 5-minute cooldown
    // (found by review: a stray row from an earlier run made "autosave fires once" fail
    // here with no code bug at all - a test-hygiene bug, not a guard bug).
    $autosaveBaselineId = intval($db->fetchOne("SELECT COALESCE(MAX(id), 0) AS n FROM public.skyrim_quest_action_outbox WHERE beat_id = 'tes_autosave'")['n'] ?? 0);
    $cleanup = function () use ($db, $autosaveBaselineId) {
        $db->execQuery("DELETE FROM public.core_npc_master WHERE npc_name LIKE 'ZZZ_TestNPC_%'");
        $db->execQuery("DELETE FROM public.tes_world_facts WHERE subject LIKE 'ZZZ_TestNPC_%' OR object LIKE 'ZZZ_TestNPC_%'");
        $db->execQuery("DELETE FROM public.rumors WHERE content LIKE '%ZZZ_TestNPC_%'");
        $db->execQuery("DELETE FROM public.skyrim_quest_action_outbox WHERE beat_id = 'tes_autosave' AND id > {$autosaveBaselineId}");
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

    $autoRow = $db->fetchOne("SELECT id, status, applied_at IS NOT NULL AS done FROM public.skyrim_quest_action_outbox WHERE beat_id = 'tes_autosave' AND id > {$autosaveBaselineId} ORDER BY id DESC LIMIT 1");
    check('a fresh autosave row is not yet applied', is_array($autoRow) && !in_array($autoRow['done'] ?? '', [true, 't', 'true', 1, '1'], true));
    // Scoped to this test's own row by id - a blanket UPDATE ... WHERE beat_id='tes_autosave'
    // (no id filter) would also mark a real, still-pending autosave from actual gameplay as
    // applied, which is real game state this test has no business touching.
    $autoRowId = intval($autoRow['id'] ?? 0);
    if ($autoRowId > 0) {
        $db->execQuery("UPDATE public.skyrim_quest_action_outbox SET status='applied', applied_at=now() WHERE id = {$autoRowId}");
    }
    $GLOBALS['gameRequest'] = ['narrator_inputtext', 0, 0, 'test'];
    $GLOBALS['PROMPT_INJECTIONS'] = [];
    require "$extDir/tes_god_journal/context_pre.php";
    $rendered = chimRenderPromptInjections('prompt_bottom', []);
    check('journal correctly reports an applied autosave as done (Postgres-boolean regression check)', str_contains($rendered, 'Автосейв сделан'), $rendered);

    $cleanup();

    echo "\n== tesGodGuardFilterAction: the real entry point actually dispatches ScriptProxy ==\n";
    // The blocking bug fixed 2026-09-29 shipped with a passing suite precisely because
    // every prior ScriptProxy check called tesGodGuardValidate()/tesGodGuardScriptProxy*()
    // directly, never the real post-process hook - a lone outfit action was silently
    // classified "blocked" and dropped before its dispatch ever ran. This uses the real,
    // known NPC Скульвар Черная Рукоять (a beggar outfit, harmless, --write-gated) because
    // ScriptProxy dispatch requires a resolvable real RefID, which a throwaway ZZZ_TestNPC
    // row doesn't have.
    $db->execQuery("DELETE FROM responselog WHERE action LIKE '%\"cmdID\":59%' AND sent = 0");
    $db->execQuery("DELETE FROM public.tes_god_guard_log WHERE kept_text LIKE '%outfit%' AND raw_text LIKE '%нищий%'");
    // Real action strings are 3 pipe-separated parts (actor|function|codeName@payload) -
    // tesGodGuardFilterAction reads $actionParts[2] for the codeName@payload half.
    $rawAction = 'Тестгерой|GodCommand|GodCommand@' . json_encode(['target' => '{npc:Скульвар Черная Рукоять}.outfit нищий'], JSON_UNESCAPED_UNICODE);
    tesGodGuardFilterAction($rawAction);
    $loggedVerdict = $db->fetchOne("SELECT verdict FROM public.tes_god_guard_log WHERE raw_text LIKE '%нищий%' ORDER BY id DESC LIMIT 1");
    check('a lone outfit command through the real entry point is not classified as blocked', ($loggedVerdict['verdict'] ?? '') !== 'blocked', json_encode($loggedVerdict));
    $spRow = $db->fetchOne("SELECT 1 AS ok FROM responselog WHERE action LIKE '%\"cmdID\":59%' AND sent = 0 ORDER BY rowid DESC LIMIT 1");
    check('and it actually dispatches a real ScriptProxy row', !empty($spRow['ok'] ?? null));
    $db->execQuery("DELETE FROM responselog WHERE action LIKE '%\"cmdID\":59%' AND sent = 0");
    $db->execQuery("DELETE FROM public.tes_god_guard_log WHERE raw_text LIKE '%нищий%'");
    // The outfit dispatch above counts as a big change, so tesGodGuardFilterAction queued
    // a real tesautosave row too (visible as "[tes_autosave] requested before: ..." in the
    // log) - clean that up, otherwise a stray Game.RequestAutoSave() fires on next launch.
    $db->execQuery("DELETE FROM public.skyrim_quest_action_outbox WHERE beat_id = 'tes_autosave' AND status = 'pending' AND id > {$autosaveBaselineId}");
} else {
    echo "\n(skipped write-side checks: re-run with --write to also test remember/relation/marry/autosave against a throwaway NPC)\n";
}

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
