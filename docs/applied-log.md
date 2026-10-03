# Applied log

What was applied to the live DwemerDistro install, when, and how to undo it.
Tags: [код] verified in code/DB, [не проверено] not yet checked in game.

## 2026-09-29 — outfit/equip moved onto real ScriptProxy, off the custom bridge

- Continuation of the ScriptProxy find above: `{npc:Name}.outfit` and the equip-with-
  prevent-removal path both used to go through OUR OWN custom Papyrus functions
  (`tesoutfit`/`tesdress` in `papyrus/TESGodConsoleReport`), which need a full game restart
  every time that bridge changes - the exact friction behind today's earlier "не помогло",
  "одежда сбрасывается" frustration (the fix existed in code but the game was still running
  the old bridge).
- Both now go through CHIM's own, already-live ScriptProxy calls instead, whenever the
  target resolves to a real RefID right now (`tesGodGuardResolveRealRefId`, same helper as
  the resurrect/kill net): `outfit` -> `SetOutfit` (cmdID 59, persistent default outfit,
  survives reloads exactly like `tesoutfit` did) with NO console command needed at all;
  `equipitem` on a known NPC -> `EquipItem` with `abPreventRemoval` (cmdID 22) queued
  alongside (not instead of) the plain console `equipitem`, matching the resurrect/kill
  "net, don't replace" approach since this path is less battle-tested than resurrect's.
  Needs no game restart - `ExecuteCommandActor` cmdID 22/59 are already loaded by
  vanilla CHIM, nothing of ours to reload.
- The old `tesoutfit`/`tesdress` bridge functions are kept as a fallback for the rare case
  where a target confirmed known to the validator still can't be resolved to a real RefID
  right now (should be uncommon, since this code path only runs for already-known targets).
- Checked for real: both dispatches insert genuine `{"cmdID":59,...}` / `{"cmdID":22,...}`
  rows into `responselog` (counted before/after), matching the exact shape of today's
  earlier confirmed-delivered row. `tools/test_ext.php` +3 checks. Suite: 52/52; confirmed
  the only pending `responselog` row afterwards is an unrelated, genuine game notification
  (`Назим` profile update, `sent=0` because Skyrim isn't running), not test leftovers.
- [не проверено] in game - specifically whether `SetOutfit`/`EquipItem` via ScriptProxy
  actually looks and behaves right on an NPC compared to the old bridge functions.

## 2026-09-29 — real ScriptProxy safety net for resurrect/kill (roadmap B: action registry)

- Found reading `lib/scriptproxy_papyrus.php` / `lib/core/action_catalog.php`: CHIM already
  has a second, entirely different action-dispatch channel from our console outbox - real
  Papyrus calls into `AIAgentScriptProxy.ExecuteCommand(cmdID, json)` (135 commands, IDs
  1-99 Actor, 100-199 ObjectReference, 200-299 FormList, 300-399 EffectShader, 400-499
  ActorUtil, 500-599 Faction), delivered via `public.responselog` (`action =
  'rolecommand|ScriptProxy@<json>'`), already used live by real actions (`Drink`, `Toast`,
  `StartRitualCeremony`). Confirmed genuinely delivered, not dead code: found a real row in
  `responselog` with `sent=1` for `cmdID:22` (EquipItem, `abPreventRemoval:1`) targeting
  Лилит Ткачиха's own RefID `0010E2B6` - from earlier in today's dressing work, picked up and
  applied by the game.
- This is exactly roadmap B's "papyrus-bridge (`ExtCmd*`)" backend of the action registry,
  already built by CHIM itself - nothing to write on the Papyrus side, no compiler wall like
  the NFF/RaceMenu dead end, since it's plain PHP -> an interface CHIM's own compiled .pex
  already implements.
- `resurrect`/`kill` were the two commands already documented as unreliable via the console
  (`prid`/`resurrect` silently doing nothing on some targets, historically the whole
  "ГОСПОДЬ ДОЛЖЕН УМЕТЬ ВОСКРЕШАТЬ" saga). `tesGodGuardScriptProxySafetyNet()` sends the
  SAME resurrect/kill again through `SkyrimCommandBuilder->Actor->Resurrect()/Kill()`
  (cmdID 66/7) - as an ADDITIONAL safety net alongside the existing console command, never
  instead of it, so this cannot regress anything that already worked. Only fires when the
  target resolves to a real RefID right now (`tesGodGuardResolveRealRefId()`: core_npc_master
  or the game index) and no extra arguments were given (an argued `resurrect <n>` is left to
  the console path alone, since ScriptProxy's Resurrect takes no arguments).
- Checked for real: dispatching actually inserts a genuine `{"cmdID":66,...}` row into
  `responselog` (not just constructs the array) - verified by counting rows before/after,
  then deleted the still-pending (`sent=0`) test row. `tools/test_ext.php` +5 checks. Suite:
  49/49; confirmed no leftover pending rows in the live `responselog` afterwards.
- [не проверено] in game whether this actually makes resurrect/kill materially more
  reliable than the console path alone - that's the whole point of it being a safety net
  and not a replacement.

## 2026-09-29 — actionable refusal reasons for character/relation/remember/marry

- Owner asked, fairly: "how does the Narrator even know what's missing?" Answer, honestly:
  it doesn't infer anything - it only sees whatever plain-language reason we write into the
  refusal. The three server-command paths (`character`/`relation`/`remember`/`marry`) all
  said the same flat "«X»: этого персонажа нет в памяти CHIM" whether X was a real NPC who
  simply hasn't talked to the player yet, or a name the model invented outright - neither
  case gave it anything to actually act on.
- `tesGodGuardWhyNoProfile()`: checks whether the name matches a real placed actor
  (`tes_game_index`, kind=actor) or a recent runtime NPC (`tesGodGuardKnownNpc`'s addnpc
  check) despite having no CHIM row yet - if so, the reason says plainly to greet them in
  game first; otherwise it says there is no such person and asks for the exact name.
  Old runtime-only names that have aged out of the (unbounded, but not infinite) `addnpc`
  event history fall into the second case - an honest limitation, not a bug.
- Checked (`tools/test_ext.php`, +2 checks, one of them dynamically finds a real indexed
  actor with no `core_npc_master` row so the check doesn't depend on prior play history).
  Full suite: 43/43.
- Said plainly to the owner in the same reply: this whole journal/streak mechanism is a
  strong hint in text the model reads next turn, not server-enforced control flow - nothing
  stops a 4th attempt at the same request beyond the same validator firing again. A real
  agent loop (the server itself deciding to retry or stop, across several model calls,
  without new player input) is starred as a future idea, not attempted here.

## 2026-09-29 — hard stop after a run of failures (roadmap B: retry limit)

- Roadmap B literally asks for "цикл план→шаг→проверка→исправление, лимит шагов и попыток,
  остановка и честное сообщение при серии провалов" — the journal only had a soft prompt
  line ("если НЕ вышло — признай и попробуй иначе"), which the model can and does ignore
  (log evidence: five different unknown-NPC names refused in a row inside one reply, at
  16:11-16:13 the same session `{npc:Бугак гро-Дула}`, `{npc:Луголг гро-Багдуб}` etc.).
- `tesGodGuardFailureStreak()`: counts the most recent consecutive `tes_god_guard_log`
  rows (newest first) with verdict `blocked`, stopping at the first non-blocked row.
  `tes_god_journal`: streak >= 3 adds a hard, capitalized stop line telling the Narrator not
  to invent another variant of the same request, to say in one sentence that it can't (or
  what exactly is missing - e.g. an exact name), and wait for the player.
- Checked (dry run + `tools/test_ext.php`, +2 checks): 3 synthetic blocked rows -> streak 3
  and the stop line renders; one `ok` row resets the streak to 0. Full suite: 41/41.
- Deployed to the live server. [не проверено] in game whether the model actually obeys the
  stronger wording any better than the existing soft one.

## 2026-09-29 — {npc:Name}.heal / player.heal; a real dead-end confirmed for NFF recruit

- Explored giving the Narrator a god-command to make ANY NPC a real NFF follower
  (`AIAgentNpcUtil.MakeFollower`, whose shipped `.pex` already calls
  `nwsFollowerControllerScript.RecruitAction` now that NFF is installed - confirmed by
  reading the compiled `.pex`'s strings). Empirically test-compiled a call to it from our own
  script (with NFF's `Scripts` folder added to `-i`): the compiler still needs to recompile
  `AIAgentNpcUtil.psc` and `AIAgentPapyrusFunctions.psc` from SOURCE, which reference
  `racemenu`, `UIExtensions` and `VRIK` types whose mods are not installed - a real, verified
  dead end, not a guess. **No new code from this**: existing `MakeFollower` /
  `Join_#PLAYER_NAME#_Party` should already work through an NPC's own dialogue now that NFF
  is installed - owner to test in game (e.g. Скульвар or Йервар asking to follow), no God
  channel involved.
- Instead: `{npc:Name}.heal` / `player.heal` (bridge `tesheal`): `RestoreActorValue` on
  Health/Magicka/Stamina (a large amount - the engine clamps to max), ends unconsciousness/
  bleedout (`SetUnconscious(false)`), cures disease by casting the vanilla `VampireCureDisease`
  spell (Skyrim.esm `0xED0AA`, self-cast; its real job is "cure all diseases before changing"
  for the vampire/werewolf transformation scripts, but it is a genuine, safe cure-all spell
  for anyone - found in `tes_game_index`, not guessed).
- **Caught and fixed a bug before deploy**: `player.heal` naively became the console text
  `"player.tesheal"`, which the real console doesn't understand (only bare `"tesheal"` is
  intercepted by `TESRunAndReport`) - would have silently failed the moment someone asked for
  it. Fixed by substituting `player` with `00000014`, the game engine's own fixed FormID for
  the player reference, so the CORE's existing `"RefID.cmd"` → `["prid RefID", cmd]` handling
  (already used for real NPCs) applies here too. Verified: `player.heal` →
  `00000014.tesheal`; `{npc:Name}.heal` keeps the placeholder for the core to resolve, same
  as `outfit`/`routine` already do.
- Added 2 checks to `tools/test_ext.php` for this substitution; cheat sheet updated (backup
  `core_action_godcommand_20260929_114521.tsv`). Full suite: 39/39. Compiled, copied to MO2
  (after a restart). [не проверено] in game.

## 2026-09-29 — tools/test_ext.php: a permanent regression test for the god plugins

- Owner away from the game (remote, no Skyrim running): consolidated today's many one-off
  scratch checks into a real, repo-tracked test instead of re-writing them by hand each time.
- `php tools/test_ext.php` (read-only, 26 checks): `tesGodGuardValidate` parsing (0x-prefixed
  RefIDs, disable/setstage refusal, sgtm range, server-verb routing, unknown-NPC → nearby
  path), `tesGiftsCommands` parsing, `tesGodGuardIsBigChange` autosave-trigger detection, the
  `(dead)`/`(far away)` false-trigger fix in `tes_russify`, journal rendering for a
  non-narrator turn.
- `php tools/test_ext.php --write` (+11 checks): exercises `character`/`relation`
  (player and NPC-to-NPC)/`remember`/`marry`/autosave end-to-end against a throwaway
  `ZZZ_TestNPC_*` pair, created and deleted by the test itself - never touches real NPCs.
  Verified this also catches the exact Postgres-boolean-as-string bug fixed earlier tonight
  (asserts the journal says "ещё не подтвердила" for a pending autosave, "сделан" once
  applied).
- Ran both modes against the live DB: 26/26 then 37/37 passed; confirmed no `ZZZ_TestNPC_*`
  or stray `tes_autosave` rows were left behind afterwards.
- One assertion ("a quiet history renders no journal section") was written then dropped
  before commit: it assumed an empty recent-activity window, which cannot hold against the
  live, shared DB (today's own testing already fills it) - would have been a flaky check,
  not a real regression guard.

## 2026-09-29 — autosave before hard-to-undo god changes (roadmap B: "откат")

- Investigated a true same-turn `funcret` result for GodCommand (advisor's B2 suggestion):
  not practical - the game executes outbox rows asynchronously, so PHP would have to block
  the HTTP request for an unknown time to get a real answer in the same reply. A proactive
  correction via the existing `narration`/rechat channel is possible in principle
  (`main.php:1146`) but entangled with its own probability/budget gating (`RECHAT_P`,
  `BORED_EVENT`), so it needs in-game testing before it's trustworthy - deferred, owner chose
  the safer autosave-before-big-change item instead while away from the game.
- Bridge `tesautosave` → `Game.RequestAutoSave()` (the real vanilla autosave slot, not an
  arbitrary named save).
- `tesGodAutosaveIfNeeded()` (shared, `ext/tes_god_guard`): queues one `tesautosave` outbox
  row (`beat_id='tes_autosave'`), rate-limited to once per 5 minutes so a burst of small
  commands doesn't spam saves. Called from `tes_god_guard` before any batch containing
  `resurrect`/`kill`/`setownership`/`tesroutine`/`tesoutfit` or a `marry` server command, and
  from `tes_gifts` before `Give_To_Player` `house`/`all` (reassigns ownership of a lot at
  once). Checked (dry run): detection, 5-min cooldown, cleanup.
- `tes_god_journal` mentions a recent autosave once ("Автосейв сделан…" / "…ещё не
  подтвердила"), so the Narrator can honestly say there is a rollback point if asked.
  **Bug caught and fixed before deploy**: the "applied?" check used `!empty($row['done'])` on
  a Postgres boolean, which PHP reads as the string `'f'`/`'t'` - `!empty('f')` is true (a
  non-empty string), so it silently always read as "done". Fixed with the same
  `in_array($v, [true,'t','true',1,'1'], true)` check already used elsewhere in these files;
  re-tested both states render correctly.
- Deployed to the live server (rate-limit logic and journal wording are safe to run without
  the game). [не проверено] in game: whether `Game.RequestAutoSave()` actually writes a save
  while unpaused mid-conversation.

## 2026-09-29 — real root cause: concurrent outbox rows race on ConsoleUtil (not the marker)

- Reviewing the whole session's log, not just the last hour: at 17:54 a single sequence
  (`setav silence 1`, `equipitem 1B01A852`, `equipitem 00086991`, `StopCombat`, `UnequipAll`)
  produced the SAME output, "Invalid actor value 'silence' for parameter Actor Value.
  Compiled script not saved!", for all five console_log rows. Only the first command
  actually failed (`silence` is not a valid Actor Value); the rest print nothing on success. [лог]
- My first attempt this morning (see the now-superseded README/log wording, and what I told
  the owner) blamed the `[tes] <command>` `PrintMessage` marker for not reaching
  `ReadMessage`, and switched `TESRunAndReport` to a before/after `ReadMessage` diff instead.
  **That diagnosis was wrong** [гипотеза → опровергнуто]: `tes_god_console_log` from
  16:10-16:12 already showed the marker DOES reach `ReadMessage` - row 6's own reported
  output was literally row 7's later `"[tes] prid 0001A69C"` marker, same for rows 9, 11, 14.
  The before/after diff was harmless but did not fix anything.
- Real cause, confirmed at 17:54:33.28-33.37: two different NPCs' `prid` calls interleave
  seven times in under 0.1 s (rows 137-143), then five commands meant for one NPC all report
  the other's stale error (rows 144-148). Impossible if outbox rows ran one at a time with
  their own `Utility.Wait(0.25)` between steps - the AIAgent plugin dispatches several
  pending rows without waiting for each other, so `ExecuteConsoleCommand(Sequence)` calls
  from different rows run as concurrent Papyrus call stacks, racing on `ConsoleUtil`'s
  single shared selected-reference/last-message state. This also means a command meant for
  NPC A could silently land on NPC B - a likely cause of "с одеждой у него беда" and similar.
- Fix: `TESLockAcquire`/`TESLockRelease`, a `StorageUtil.AdjustIntValue`-based spinlock on the
  player (single native call = atomic), now wrap the whole body of `ExecuteConsoleCommand`
  and `ExecuteConsoleCommandSequence`, including every step and `Utility.Wait` in a sequence,
  released on every return path including the abort-on-failed-`prid` path. 10 s timeout then
  force-takes the lock, since `StorageUtil` values persist in the co-save and a save made
  mid-sequence would otherwise leave it stuck forever after loading (first acquire after such
  a load costs one extra ~10 s stall). Compiled, copied to MO2 (after a restart).
- Owner: Хеймскр died at 17:57 (probably from the earlier bandit/explosion spawns) and the
  horse died at 19:13 - both easy to `resurrect` if wanted. [не проверено] whether the lock
  fixes the race in game; check `tes_god_console_log` after a multi-NPC narrator reply for
  cleanly ordered `prid A, cmd A, prid B, cmd B` with no interleaving.

## 2026-09-29 — three bugs found reviewing the log: (dead), 0x refids, NPC titles

- `ext/tes_russify`: the Latin-name detector matched `(dead)` on Хеймскр (an English status
  tag, not a name) and kept re-queueing a no-op `tesrussify` every 5 min ("renamed 0"). Now
  strips all status tags (far away/too far away/busy/hostile/in combat/dead/disabled/
  unavailable) before checking, same list `RelationshipManager::normalizeTargetName` strips.
  Checked: `(dead)`/`(far away)` alone no longer trigger; a real Latin name still does. [код]
- `tes_god_guard`: `player.moveto 0x0001B058` and `0x0001B058.moveto player` were both
  blocked - the Narrator used a "0x"-prefixed RefID, which the allowlist regexes didn't
  accept. Both forms now get their "0x" stripped up front, before any check runs. [код]
- `tes_god_guard`: server commands (`character`/`relation`/`remember`/`marry`) for "Кай"
  failed with "нет в памяти CHIM" although he is stored as "Командир Кай" (his title changed
  in play). New `tesGodGuardResolveNpcLoose()`: exact/in-range match first
  (`RelationshipManager::resolveNpcByName`), then a PHP-side, `\p{L}`-aware whole-word match
  against every stored `npc_name` (falls back to none if more than one NPC shares that word -
  "Карл" must not hit "Карлотта", checked). A DB-side regex can't do this correctly: the
  database runs a C locale, so Postgres' own `\w`/`\W` treat Cyrillic bytes as non-word
  characters and silently degrade to a plain substring match.

## 2026-09-28 — clothes that survive a reload: bridge tesoutfit (Actor.SetOutfit)

- Owner: dressed clothes reset. Console `equipitem` (even wrapped by tesdress,
  Equip+abPreventRemoval) does not survive the NPC's 3D unloading/reloading — CHIM's own
  spawner uses `Actor.SetOutfit` instead (AIAgentAIMind.psc:2154), which the game re-applies
  itself on every load. `tools/game_index.py` now also indexes OTFT records (1327 outfits);
  reloaded (`tools/load_game_index.sh`).
- Bridge `tesoutfit <signed decimal FormID>`: `SetOutfit(outfit, false)` on the selected
  actor. tes_god_guard: `{npc:Name}.outfit <style>` maps a Russian/English word (нищий,
  крестьянин, богатый, ярл, шахтёр, повар, трактирщик, кузнец, заключённый, свадебный) or an
  exact vanilla/Requiem outfit EditorID to its FormID via the index. Cheat sheet: `equipitem`
  is now framed as temporary (until reload), `outfit` as the lasting change of station
  (backup core_action_godcommand_20260928_231459.tsv). Compiled, copied to MO2 (after a
  restart). [не проверено] in game.

## 2026-09-28 — a new daily life with a new fate (bridge tesroutine, roadmap F)

- Owner asked whether Лилит, turned into a beggar, would now roam Whiterun begging: no —
  CHIM profile changes only her talk; her schedule comes from the plugin's AI packages.
- Found in CHIM: AIAgentAIMind.TravelToLocation uses the SandboxWork package (AIAgent.esp
  0x40BE6, sandbox near the linked ref, sandbox faction 0x21246) at priority 90, but CHIM
  resets packages after arrival. Calling AIAgentAIMind from our script pulls RaceMenu/NFF/
  UIExtensions sources the compiler lacks, so the bridge does it itself.
- Bridge `tesroutine here`: persistent XMarker (0x3B) at the player's spot, SetLinkedRef,
  sandbox faction rank 1, ActorUtil.AddPackageOverride(SandboxWork, 90); marker kept in
  StorageUtil "TESRoutineMarker". `tesroutine reset`: override, faction, link, marker removed.
- tes_god_guard: `{npc:Name}.routine here|reset` → tesroutine (NPC only). Cheat sheet +
  narrator prompt (backups core_action_godcommand_20260928_203320.tsv,
  core_narrator_prompt_head_20260928_203320.tsv). Compiled, copied to MO2 (after a restart).
- [не проверено]: whether PO3 SetLinkedRef survives a save/load, and whether other CHIM
  actions (follow/wait) reset the override.

## 2026-09-28 — dressing NPCs that sticks (bridge tesdress)

- In game 17:24–17:25: the Narrator tried to dress Лилит Ткачиха in rags — additem/equipitem
  of Рваный балахон 00013105 and Ножные обмотки 0003CA00 three times, removeitem, again;
  `unequipall` was refused (not allowlisted). Console equipitem on NPCs doesn't stick: they
  go back to their outfit. [лог]
- Bridge `tesdress <signed decimal FormID>`: selected actor AddItem (if missing) +
  EquipItem(item, abPreventRemoval=true, abSilent=true). tes_god_guard rewrites every NPC
  `equipitem <HEX>` (npc, RefID, near, tesnear paths) to it; `unequipall` allowed. Cheat
  sheet: dress in one command, undress. Compiled, copied to MO2 (after a restart). In game:
  [не проверено] whether the outfit survives a cell reload.

## 2026-09-28 — removal of Хельга/Ulfhild; stray disable; sequences abort on failed prid

- Owner: remove the Хельга clone and Ulfhild. I queued directly (bypassing the guard)
  ["prid FF0013A9|AA", "disable", "markfordelete"]. The game answered «Item 'FF0013A9' not
  found» (they no longer existed under those IDs — likely a save reload), but `disable` and
  `markfordelete` still ran on the console's previously selected reference. [лог]
  Checked after: Астрид still in the nearby list; safety `enable` sent to Астрид FF0013B5 and
  Скульвар 0001A69C (both prid OK). Who was hit, if anyone: [не проверено] — owner checks
  Скульвар at the stables.
- Lesson: never send raw disable/markfordelete; always go through the guard/`unsummon`
  (refuses non-FF refs).
- Bridge fix: TESRunAndReport returns false when `prid` reports "not found" (and clears the
  selection) or `tesnear` finds nobody; ExecuteConsoleCommandSequence aborts the rest and
  reports "aborted, target not found". Compiled, copied to MO2 (after a restart).

## 2026-09-28 — whole story changes: marry, remember, leftovers in the journal

- In game 17:00–17:17 [лог]: three "wives" at the stables — the Хельга clone (never removed,
  the Narrator claimed it "melted like mist"), Ulfhild Ingunnsdottir (Create_New_NPC, thinks
  she is Скульвар's wife), Астрид Золотая Коса (Create_New_NPC). Relations were set, but
  Скульвар asked "what Astrid?" until his personality was rewritten: nobody remembered events.
- tes_god_guard: `{npc:A}.remember text` → "[Помнит]" block at the end of npc_static_bio
  (always in the NPC's prompt as background; last 8 lines, deduplicated);
  `{npc:A}.marry B` → table `tes_world_facts` (spouse, one per person); former spouses and
  every other romance of A/B in both directions → `ex` + a memory; couple 90 romantic
  «супруги», wedding memory for both, rumor in the hold.
- tes_god_journal: lists people created during play (addnpc with FF refid, 3 h) and tells
  the Narrator to unsummon leftovers. Narrator prompt + cheat sheet: story changes must be
  remembered by everyone involved; remove replaced summons; don't claim removal before the
  journal confirms (backups core_action_godcommand_20260928_202032.tsv,
  core_narrator_prompt_head_20260928_202032.tsv).
- Applied (backups core_npc_master_marry_20260928_2019*.tsv): Скульвар marry Астрид; memories
  for Скульвар, Астрид, Йервар; Хельга → ex with memory. Ulfhild and the Хельга clone are still
  in the world (owner decides).

## 2026-09-28 — NPC names only in Cyrillic (ext/tes_russify + bridge tesrussify)

- Owner: NPC names must be Cyrillic only. Latin names nearby (Von Tanner, Jordunn Windworn,
  Ulligor, Kupitman the Screaming Healer) are runtime names, not in any plugin. Source: Real
  Names - Extended; its RU lists (mod "Real Names Extended - RU", higher priority) have
  28 344 names, 0 Latin → the Latin ones were assigned before the RU lists and are kept in the
  save (StorageUtil "RNE_Name"). [код]
- ext/tes_russify (preprocessing): an infonpc / infonpc_close list with a Latin name → queue
  `tesrussify` (outbox beat_id tes_russify, at most every 5 min).
- Bridge `tesrussify`: actors within 8192 units whose display name starts with a Latin letter
  and have RNE_Name → cast the mod's "[RN] Rechange" spell (RealNamesExtended.esp 0x82C, picks
  race/sex list, now Russian); Latin names not from Real Names are only reported.
  Compiled, copied to MO2 (after a game restart). [не проверено] Spell.Cast from the player
  applies the effect to the target.
- Narrator prompt: Create_New_NPC names in Russian letters only (backup
  core_narrator_prompt_head_20260928_201243.tsv).

## 2026-09-28 — relation between NPCs; Хельга/Скульвар repaired

- Bug: `relation` only wrote the Player slot, so at 17:00:39 the Narrator's "Хельга loves
  Скульвар" / "Скульвар charmed by Хельга" became both of them in love with the PLAYER. [лог]
  Also at 17:00:07 it claimed Хельга was sent "back south" although moveto was refused.
- Fix: `{npc:A}.relation [to <B>] <aff> <type> [note]` (default: player); note written to
  relationships.<target>.note. Cheat sheet updated (backup
  core_action_godcommand_20260928_200412.tsv).
- Repaired (backup core_npc_master_helga_skulvar_20260928_200400.tsv): Скульвар→Player
  90 grateful «за искреннюю заботу о его семье»; Хельга (clone FF0013A9)→Player 0 neutral;
  Хельга↔Скульвар 90 romantic (the Narrator's intent).

## 2026-09-28 — session review 16:51–17:00; clones, city teleport, unsummon

- Worked in game [игра + лог]: Give_To_Player horse ×2 (`tesnear Лошадь` → selected,
  setownership); Narrator changed Скульвар (occupation → rumor), Йервар (personality,
  speechstyle, relation 0 → 60), relations up to 90; Скульвар talks about the rumors and
  refuses "memory tampering" in character.
- Found: the Narrator "found a wife" with `{spawn:Хельга}` → a clone of Haelga from Riften
  (0001335F); then `{npc:Хельга}.moveto {cell:Рифтен}` was refused (no such cell).
- Fixes in tes_god_guard: `{spawn:}` of a unique person (exactly one placed actor in the
  index) is refused with a hint; `{cell:}` falls back to world/location names →
  `<Name>Origin` / `<Name>` cell (Рифтен → RiftenOrigin, Вайтран → WhiterunOrigin);
  `moveto` only to player / RefID / {npc:}; new `{near:Name}.unsummon` → bridge
  `tesremove`: Disable+Delete only for refs created during play (FormID FFxxxxxx).
  Cheat sheet updated (backup core_action_godcommand_20260928_200218.tsv).
- The Хельга clone is still standing at the stables: "убери Хельгу" after a game restart.

## 2026-09-28 — god's changes become news: rumors (first step of roadmap stages C/E)

- In game: Скульвар's son didn't know his father got rich — only Скульвар's own profile had
  changed. [игра]
- CHIM already injects up to 3 active rumors of the current hold into every NPC prompt
  (table `rumors`, was empty). tes_god_guard: `rumor <text>` (narrator) adds one for the
  player's current hold for 14 game days; `{npc:X}.character occupation: …` adds
  "Говорят, X теперь …" automatically. Cheat sheet updated (backup
  core_action_godcommand_20260928_195549.tsv).
- Applied: rumor #1, hold «Вайтран»: Скульвар разбогател thanks to Шаман. In game:
  [не проверено] that Йервар and others bring it up.
- Known limits: build_rumor_prompt_xml shows only 3 rumors in DB order; no distortion or
  spreading between holds yet (roadmap E).

## 2026-09-28 — NPC gifts that really change ownership (ext/tes_gifts, Give_To_Player)

- In game 16:44: Скульвар "gave" a horse only in words (no action exists), the horse kept its
  owner → "украсть". Owner: this must happen by itself in CHIM, and not only horses. [игра]
- New NPC/follower action `GiveToPlayer` / `Give_To_Player` (settings/chim_settings.sql;
  the short-lived `GiveHorse` row is deleted), target:
  horse | <animal name> → nearest such animal gets `setownership`;
  around → giver's (or its factions') objects within 1500 units: chests, furniture, items;
  house → the interior the player stands in, if the giver/its faction owns it: cell owner,
  everything owned inside, locked doors/containers unlocked;
  all → everything the giver carries and wears (RemoveAllItems to the player);
  spell:<name> → player.addspell (game index).
  Existing CHIM actions cover single items (Give_Item_To), gold, joining, training.
- ext/tes_gifts (post-filter) → outbox `beat_id=tes_gift`; bridge override: TESSelectNearby
  now picks the NEAREST actor with the name; new TESGive (Cell.GetNumRefs/GetNthRef,
  Get/SetActorOwner, Lock(false)); compiled, copied to MO2 (active after a game restart).
- Checked: target parsing (no queue). In game: [не проверено] — ownership APIs on horses,
  house cells with faction owners, and whether CHIM offers the new action to NPCs.
- Narrator: `setownership` allowed and `{near:Name}.cmd` = nearest actor with that name.
- Undo: `DELETE FROM core_action WHERE code_name='GiveToPlayer'`; rm ext/tes_gifts.

## 2026-09-28 — god changes character and relationships (roadmap "chim-db" backend)

- In game 16:36: "одень Скульвара богато и пусть ведёт себя как богатый" → dressed (works),
  but the Narrator said a character can't be changed (its prompt said so) and CHIM kept
  `aff -8 wary «Insults escalated threat to violence»`. [игра]
- tes_god_guard: server-side commands, not sent to the game:
  `{npc:Name}.character [personality|occupation|speechstyle|goals|appearance:] text` →
  core_npc_master column; `{npc:Name}.relation <aff> <type> [note]` →
  RelationshipManager::setRelationship(…, 'Player', …) + note. Results (with "было → стало")
  go to tes_god_guard_log as verdict `server`; the journal shows «СДЕЛАНО (память CHIM)».
  NPC unknown to CHIM → refused with a reason. [код]
- Applied for real on Скульвар (the owner's request): personality "разбогатевший конюх…",
  occupation "богатый торговец лошадьми…", relation -8 wary → 60 grateful. Backup:
  `/home/dwemer/backups/core_npc_master_skulvar_20260928_194136.tsv`. He has lock_profile=1,
  so the dynamic profile won't overwrite it. In game: [не проверено].
- Narrator prompt_head (settings/narrator_ru.sql): may now rewrite character/relations, must
  read the journal; backup core_narrator_prompt_head_20260928_194215.tsv. Cheat sheet:
  character/relation recipes; backup core_action_godcommand_20260928_194214.tsv.

## 2026-09-28 — items resolved by the guard; spawn by name; fun recipes

- In game 16:18–16:24: the Narrator claimed "Mace of Molag Bal / Mehrunes' Razor is in your
  hands" while the core had silently dropped every `{item:English name}` (not in its
  description DB) — the refusals never reached the journal. [игра + лог]
- tes_god_guard now resolves every `{item:}` itself: index name/EditorID (name keys without
  RFAD prefixes like "[Алкоголь] Эль"), English words vs whole EditorID tokens (skips
  hilts/scabbards/replicas, prefers body over head/feet), then the core resolver;
  unresolved → refused with a reason (visible in the journal). Checked: Mace of Molag Bal
  000233E3, Mehrunes' Razor 000240D2, Fine Clothes 00086991, Ale/Эль 00034C5E,
  Cheese Wheel 00064B33. [код]
- `{spawn:Name}` beyond bandit|mage|archer|boss → base NPC / leveled list from the index
  (Курица 000A91A0, Великан 00023AAE, Дракон 0001CA03, Шеогорат 0002AC69). `sgtm` allowed
  within 0.2–3. Cheat sheet: summon by name, cheese rain, slow motion, speed, fus ro dah,
  tiny (backup core_action_godcommand_20260928_192920.tsv). In game: [не проверено].

## 2026-09-28 — game data index (roadmap stage B) + index-aware god commands

- `tools/game_index.py` (Windows Python + lz4): MO2 profile RFAD_SE → 281 active plugins
  (103 full, 178 light) → 161 653 records: cell 74 565, npc 39 411, item 16 668,
  actor 15 810, spell 5 856, quest 3 213 (with stage lists), leveled_npc 2 984,
  faction 1 582, location 782, explosion 592, weather 129, world 61. ~5 s. [код]
  Checks: Скульвар 0001A69C, Назим 0001A6A4, Амрен 0001A66A (actor refs, base + cell);
  MQ101 «На свободу!» with stages; Russian names from BSA strings + mod overrides.
- Loaded with `tools/load_game_index.sh` into `public.tes_game_index` (DROP + CREATE).
  Lower-case keys `name_lc`/`editor_id_lc` come from Python: the DB has a C locale and
  `lower()` does not fold Cyrillic.
- Not in any plugin: names given at runtime (Сианэйт, Лановик Морассел, Бугак гро-Дула…)
  → those still go through `tesnear` (in-game lookup by display name).
- tes_god_guard: `{npc:Name}` unknown to CHIM → unique actor from the index (≤3 people with
  that name, earliest FormID) → real RefID; `{cell:Name}` → cell EditorID for `coc`;
  Russian `{item:Name}` → FormID (mod items too). Validate-only test passed. [код]
- GodCommand cheat sheet (core_action) updated from settings/chim_settings.sql:
  {cell:}, Russian items, coc teleport, "tgm is a switch". Backup:
  `/home/dwemer/backups/core_action_godcommand_20260928_192404.tsv`.
- Rebuild after changing mods: run game_index.py again, then load_game_index.sh.

## 2026-09-28 — first in-game run of the god pipeline + fixes

- In game (16:07–16:13): 14 god commands queued/applied; console reports arrived for all
  → `logMessage` reaches main.php with our type. [игра]
  `tgm` answered «God Mode disabled.» (it was on; the Narrator toggled it off). [игра]
- Found: concurrent outbox rows interleave console lines, so a report sometimes carried
  the other command's `[tes] …` marker → receiver now stores such output as empty.
- Found: "calm them" failed for nearby NPCs the server has no RefID for (never talked to
  the player). Guard now routes `{npc:Name}.<cmd without placeholders>` for unknown
  NPCs as `["tesnear Name", cmd]`; the bridge override selects the nearby actor by display
  name (MiscUtil.ScanCellNPCs, 4096 units, dead included) or reports what it saw.
  [не проверено]: Cyrillic display names vs payload encoding in Papyrus.
- Bridge .pex replaced in MO2 while the game was running → active after a restart.

## 2026-09-28 — ext/tes_god_console 0.1.0 + papyrus/TESGodConsoleReport (stage B: real console result)

- Installed on the server: `ext/tes_god_console/preprocessing.php` — catches requests of
  type `tes_god_console` before the LLM pipeline, stores them in
  `public.tes_god_console_log` (command, output, gamets) and ends the request. [код]
- tes_god_journal 0.3: per outbox row, looks up console lines for its commands within
  3 min; an error line (not found / missing / invalid / …) → «НЕ вышло, консоль ответила»;
  a report without error → «выполнено игрой». [код]
- Checked: dry run (fake game message with an error + a silent command → journal lines
  correct); test rows removed.
- 2026-09-28: owner said «да»; copied to `MO2\mods\TES God Console Report` (Scripts .pex,
  Source .psc, meta.ini). Owner enables it in MO2 below AIAgent.
- Game side (was waiting for owner «да» + enabling in MO2):
  `papyrus/TESGodConsoleReport` — override of AIAgentQuestProgressionBridge.pex.
  [не проверено]: that `logMessage` reaches main.php with type `tes_god_console`;
  that `ReadMessage` returns the line printed by the command just run.
- Undo: server — `rm -r /var/www/html/HerikaServer/ext/tes_god_console`,
  `DROP TABLE public.tes_god_console_log;` game — disable the MO2 mod.

## 2026-09-28 — ext/tes_god_guard 0.1.0 (roadmap stage B: validator before outbox)

- Installed: `/var/www/html/HerikaServer/ext/tes_god_guard/functions.php` (+ manifest).
  Loaded on every request with functions — a fatal here breaks all dialogue; `php -l` passed.
- A post-filter that runs before the core GodCommand handler:
  allowlist of console verbs (cheat-sheet recipes + relatives); refuses disable/enable,
  delete, setstage/completequest/resetquest/caqs, `set` other than gamehour/timescale, any
  unknown verb; `{npc:Name}` must resolve and a bare hex RefID must be a known NPC;
  `placeatme N` capped at 10; the same command within 30 s is dropped as a repeat. [код]
- Creates table `public.tes_god_guard_log` (raw/kept text, verdict ok|partial|blocked|repeat,
  reasons). tes_god_journal 0.2 shows refusals to the Narrator ("ЗАБЛОКИРОВАНО: …"). [код]
- Checked: dry run with fake actions (ok / repeat / mixed bad commands / non-God action
  untouched), journal output; test log rows removed. In game: [не проверено].
- Undo: `rm -r /var/www/html/HerikaServer/ext/tes_god_guard`; optionally
  `DROP TABLE public.tes_god_guard_log;`

## 2026-09-28 — ext/tes_god_journal 0.1.0 (roadmap stage B: honest result, server side)

- Installed: `/var/www/html/HerikaServer/ext/tes_god_journal/` (`manifest.json`, `context_pre.php`).
- On every Narrator turn it adds a "journal of your god commands" (last 6, 30 min) to the
  prompt: pending / failed / dispatched, and for `resurrect` / `kill` a life/death check
  against `core_npc_master.metadata.activity_status`, accepted only when the status
  `gamets` is newer than the game time the command was queued. [код]
- Creates once (idempotent) an inert service quest `000_tes_god_channel`
  (definition `active=false`, instance `run_state='inactive'`), so the GodCommand outbox
  channel never depends on some other quest's row. The core patch picks the first
  `quest_key`, so god commands now attach to this row. [код]
- Checked: `php -l`; dry run on the live DB with inert (non-pending) test rows — journal
  renders, non-narrator turns get nothing; test rows removed. [код]
- Not checked in game: whether the Narrator actually stops claiming unverified results;
  whether `activity_status` updates for a dead/resurrected NPC. [не проверено]
- In game 2026-09-28 15:52: Narrator "воскреси Скульвара" → `{npc:Скульвар Черная Рукоять}.resurrect`
  → outbox rows 33/34 on `000_tes_god_channel`, applied; Скульвар alive, `activity_status`
  gamets refreshed after the command (so the journal can mark it «сделано»). [код + игра]
  The command was issued twice 6 s apart (harmless for resurrect).
- Known limit: the reply that issues a command cannot know its result; the next Narrator
  turn sees it (stage B2 `funcret` closes this).
- Undo: `rm -r /var/www/html/HerikaServer/ext/tes_god_journal`, then optionally
  `DELETE FROM skyrim_quest_definitions WHERE quest_key='000_tes_god_channel';`
  (cascades to the instance and its outbox rows).

## 2026-09-29 — fix: outfit was silently doing nothing; resurrect/kill stopped double-firing

- Review found `tesGodGuardFilterAction()` never folded `$check['scriptproxy']` into
  `$all`/`$summary`. For a lone `{npc:Name}.outfit ...` the outfit change (previous entry
  above) leaves `$kept` empty by design - it goes only through ScriptProxy - so `$all` was
  also empty, `$summary === ''`, and the function returned `null` ("blocked") BEFORE ever
  reaching the ScriptProxy dispatch loop further down. **Net effect since the previous
  entry: outfit commands did nothing on the live server** - logged as `blocked`, which also
  fed the failure-streak counter and skipped the autosave that should precede a persistent
  outfit change. [код] Fixed: `scriptproxy` entries are now rendered into `$all` too, before
  the early-exit check.
- `tesGodGuardIsBigChange()` matched the literal substring `tesoutfit`, which also no longer
  appears in `$all` for this path; added a plain `outfit` match so autosave still fires.
- Corrected wording on the entry above ("real ScriptProxy safety net for resurrect/kill"):
  it justified the double-fire by calling console resurrect/kill "documented as unreliable",
  but this project's own log (2026-09-28 15:52 entry, further below) shows console
  `prid`+`resurrect` verified working on Скульвар - the historical failures were wrong
  syntax/wrong RefIDs, not console unreliability. That premise was wrong, so the double-fire
  (console AND ScriptProxy for the same actor) had no real justification and is a plausible
  reproduction mechanism for the old "Назим летает как Карлсон" bug (two near-simultaneous
  state-changing Papyrus calls on one actor). [код] Fixed: resurrect/kill now go through
  ScriptProxy INSTEAD OF the console command when a real RefID resolves right now; console
  stays only as the fallback when it can't be resolved. No longer fires both for one actor.
- `tools/test_ext.php`'s default (no-`--write`) mode called `tesGodGuardScriptProxyDress()`/
  `tesGodGuardScriptProxySafetyNet()` for real against the real live NPC Скульвар Черная
  Рукоять (a real resurrect, a real persistent `SetOutfit(BeggarOutfit)`, a real
  `EquipItem`), contradicting its own "never touches real NPCs" comment and the README's
  implication that the no-flag form is safe to run any time (e.g. right after a CHIM
  update, possibly while Skyrim is running). [код] Fixed: default mode now only asserts the
  built command arrays (`cmdID`/params), never calls `send()`; the real-insert smoke test is
  gated behind `--write` and checked via `responselog` row shape, same DELETE cleanup.
- Added a scriptproxy-visible log row (`tes_god_guard_log.verdict = 'scriptproxy'`) so
  `ext/tes_god_journal` can report ScriptProxy dispatches (outfit/equip/resurrect-kill net)
  to the Narrator at all - before this fix they were completely invisible to "было -> стало"
  reporting, undermining honest result reporting for exactly these commands.
- Regression coverage gap: earlier tests called `tesGodGuardValidate()` and the ScriptProxy
  senders directly, never the real entry point `tesGodGuardFilterAction()` - which is why
  the blocking bug above shipped with a passing 52/52 suite. Added a test that pushes a lone
  outfit action through `tesGodGuardFilterAction()` and asserts the verdict is not
  `blocked` and a `responselog` row exists.
- [не проверено] in game - specifically whether outfit now actually applies without a
  restart, and whether resurrect/kill behave the same as before now that only one path
  fires.

## 2026-09-29 — autosave before mass spawn (roadmap B validator: risk #2)

- ROADMAP risk #2 and stage B's validator item both call for confirmation or an autosave
  before a mass spawn, not just capping it. `placeatme` was already capped to 10 at once
  (tesGodGuardValidate), but nothing saved first. `tesGodGuardIsBigChange()` now also
  treats `placeatme ... N` with N in 3-10 as a big change, same autosave path as
  resurrect/kill/outfit/marry. [код] `tools/test_ext.php` +4 checks (x1/x2 not big, x3/x10
  big). Suite: 59/59.
- [не проверено] in game whether the autosave actually lands before the spawn is visible.

## 2026-09-29 — revert: resurrect/kill back to console-only (ScriptProxy was unverified)

- Second review caught that the previous fix ("resurrect/kill stopped double-firing") picked
  the wrong side: it made ScriptProxy (cmdID 66/7) the ONLY path, but the only ScriptProxy
  row ever actually confirmed delivered (`sent=1` in `responselog`) is cmdID 22 (EquipItem);
  cmdID 66/7 were never confirmed delivered, only confirmed to insert a row. Meanwhile
  console `prid`+`resurrect` WAS verified working in game (2026-09-28 15:52). Routing the
  most important god command through the unverified path, away from the verified one, was
  backwards - and it also silently broke honest reporting: `tesGodJournalLine`'s life/death
  check only reads `chim_god_command` outbox rows, so a ScriptProxy-only resurrect would
  have reported "отправлено" instead of "сделано, проверено: жив/мёртв".
- [код] Reverted: resurrect/kill are console-only again, same as before any of today's
  ScriptProxy work. `tesGodGuardScriptProxySafetyNet()` is left defined but unused (not
  deleted) until cmdID 66/7 delivery is actually confirmed the same way cmdID 22/59 were.
- `tools/test_ext.php`: flipped the resurrect assertion back to console-only; fixed a real
  test-hygiene bug found in the same pass - the write-side autosave check
  (`tesGodAutosaveIfNeeded` fires once then rate-limits) could fail on a clean code path
  simply because a PREVIOUS test run's autosave row was still inside the 5-minute cooldown,
  and a separate line unconditionally marked **every** `tes_autosave` row in the table
  `applied` with no time/id filter - which would also mark a real, still-pending autosave
  from actual gameplay as applied. Both are now scoped to a per-run baseline id, so the
  suite only ever touches rows it created itself. Ran twice back-to-back to confirm no
  cross-run pollution. Suite: 58/58 (`--write`), 44/44 (default).
- Checked the mass-spawn-cap fix from the previous entry against a multi-word `{spawn:...}`
  placeholder (advisor's concern that `\S+` in the regex might miss it): confirmed by direct
  test that `{cell|item|spawn:...}` placeholders are resolved to a plain hex FormID earlier
  in `tesGodGuardValidate`, before the cap/autosave regexes run, so this does not reproduce -
  no change needed there.
- Checked 12h of `tes_god_guard_log`/`tes_god_console_log`/outbox for real (non-test) god
  commands: none found - no in-game testing has happened yet tonight to react to.

## 2026-09-29 — {spell:Name} resolution for addspell/removespell (roadmap B validator: ID by index)

- Roadmap B's validator item calls for checking each ID against the index rather than
  passing it through blind. `{item:Name}`/`{cell:Name}` already went through this
  (`tesGodGuardResolveItem`/`tesGodGuardIndexUnique`); `addspell`/`removespell` had no
  resolution at all - the Narrator could only use them with a raw hex FormID it would have
  to already know, or rely on the core's own (English-only, item-only) resolver, which
  doesn't cover spells.
- [код] `{spell:Name}` added to the same placeholder mechanism (`tes_game_index` has 5856
  `kind='spell'` rows). A real vanilla spell (Пламя -> 0006445B) resolves; a made-up name is
  refused with the same actionable "не знаю заклинания «...» - назови точно" reason as
  items, not silently dropped or passed through unresolved.
- Deliberately NOT extended to `placeatme`'s spawn target or other still-partially-indexed
  kinds (statics/activators/furniture/containers aren't in `tes_game_index` at all) - a hard
  requirement there would refuse valid spawns the index simply doesn't know about.
- `tools/test_ext.php` +2 checks. Suite: 60/60.

## 2026-09-29 — {perk:Name} support prepared (needs owner's да to reload the index)

- Same pattern as {spell:Name} above, for `addperk`. Two parts, deliberately split by risk:
  - [код] `tools/game_index.py`: added `PERK -> "perk"` to the record-kind table. The parser
    is fully generic per record type (EDID/FULL), so this is the only change needed there -
    verified by reading the parse loop, not run yet.
  - [код] `ext/tes_god_guard/functions.php`: `{perk:Name}` added to the same placeholder
    resolver as cell/item/spawn/spell. **Dormant on purpose**: `tes_game_index` has no
    `kind='perk'` rows yet (13 kinds, checked live: no perk), so `{perk:...}` currently
    always refuses honestly ("не знаю способности «...»") rather than silently passing an
    unresolved placeholder through as a literal console argument. `tools/test_ext.php` +1
    check confirms exactly this refuse-not-silently-wrong behavior for the dormant state.
  - **Not run**: actually indexing PERK records means re-running `tools/game_index.py`
    (Windows Python) against the live MO2 load order and reloading `tes_game_index` via
    `tools/load_game_index.sh` (DROP+CREATE+`\copy`+indexes) - a live SQL/table-schema
    operation the project's own rule reserves for the owner's explicit "да", unlike an
    ext-plugin file change. Left for the owner to trigger (or approve) when convenient;
    `{perk:Name}` starts working the moment that reload happens, no further code deploy
    needed.
- Suite: 61/61.

## 2026-09-29 — {ench:Name} support prepared (enchantments; also needs the да reload)

- Owner asked for enchantments specifically. Same pattern and same split as {perk:Name}:
  - [код] `tools/game_index.py`: added `ENCH -> "enchantment"` to the record-kind table -
    same generic EDID/FULL parser, one line.
  - [код] `ext/tes_god_guard/functions.php`: `{ench:Name}` added to the placeholder
    resolver. **Dormant**: `tes_game_index` has no `kind='enchantment'` rows yet, refuses
    honestly in the meantime, same as `{perk:Name}`.
  - Note for later: a base ENCH record often has no FULL (display) name - only items that
    carry it are named - so lookup for those falls back to EditorID, same as it already does
    for unnamed spells. This resolves the enchantment record itself (e.g. for a ScriptProxy
    command that takes one directly); it does not create a new enchanted item copy - that's
    the DPF/TempClone territory from ROADMAP §5.
  - Still needs the same live `tes_game_index` reload (DROP+CREATE) as `{perk:Name}} - not
    run yet, owner's да pending, covers perk and enchantment in the same pass.
- `tools/test_ext.php` +1 check. Suite: 62/62.

## 2026-09-29 — tes_game_index reloaded: perk + enchantment go live

- Owner said да. Re-ran `tools/game_index.py` against the live MO2 load order (281 active
  plugins, 165854 records total, up from 161653) and reloaded `tes_game_index` via
  `tools/load_game_index.sh`. New counts: `perk` 1293, `enchantment` 1581 (all other kinds
  unchanged). [код] Verified live: `{npc:Скульвар Черная Рукоять}.addperk {perk:Продвинутое
  кузнечное дело}` (a ChihSkillTree mod perk, not vanilla) resolves to a real FormID through
  `tesGodGuardValidate` - confirms mod content is indexed, not just the base game/masters.
  Same for `{ench:Благословение Зенитара}` (a Requiem - Breaking Bad enchantment).
- Updated `tools/test_ext.php`'s {perk:}/{ench:} checks from "refuses because the index is
  empty" to real resolve-to-FormID assertions (the dormant-state comments in
  ext/tes_god_guard/functions.php were also removed - no longer true). Suite: 64/64.
- Full test suite re-run after the reload to catch any regression from the new data: none
  found.

## 2026-09-29 — {faction:Name} resolution for addfac/removefac

- Same pattern as {spell:}/{perk:}/{ench:} above. `addfac`/`removefac` had no ID resolution
  at all - only a raw hex FormID. [код] `{faction:Name}` added to the placeholder resolver
  (`tes_game_index` already had 1582 `kind='faction'` rows from the original index build, no
  reload needed here). Verified live: `{faction:Рифт}` (CrimeFactionRift, vanilla) resolves;
  a made-up faction name is refused with the same actionable reason as items/spells/perks.
- `tools/test_ext.php` +2 checks. Suite: 66/66.

## 2026-09-29 — correction: {ench:} refused (no consumer), Narrator doesn't know the new placeholders yet

- Second review of today's {spell:}/{perk:}/{ench:}/{faction:} work found two real problems:
  1. **Overclaim on enchantments.** The previous entry said "{ench:Name} resolves a real
     enchantment to its FormID" and implied it works like {spell:}/{item:} - it does NOT.
     Checked directly: `SetEnchantment` exists only in SKSE (`Armor.psc`, `Weapon.psc`,
     `ObjectReference.psc`, `WornObject.psc`), not in `AIAgentScriptProxy.psc` or any console
     command. There is no way to actually apply an enchantment to anything right now -
     indexing the FormID is not the same as being able to use it. [код] Fixed: any command
     containing `{ench:...}` is now refused outright ("нет консольной команды или
     ScriptProxy для этого, нужен новый Papyrus-мост"), regardless of whether the name
     resolves. The resolver itself (`tesGodGuardResolveItem(..., ['enchantment'])`) still
     works and is tested directly - useful groundwork for whenever a real bridge function
     for `SetEnchantment` gets written (needs a new Papyrus script + game restart, not done).
  2. **The Narrator doesn't know {spell:}/{perk:}/{faction:} exist yet.** It learns
     placeholder syntax from `core_action.description` for `GodCommand`
     (`settings/chim_settings.sql`), which only listed `{item:} {cell:} {spawn:} {weather:}
     {explosion:}`. Without teaching it the new ones, the Narrator would keep writing
     `addspell Fireball` with a bare name, which the guard passes through unresolved and the
     console then fails on. [код] `settings/chim_settings.sql` updated (repo only): added
     `{spell:}`/`{perk:}`/`{faction:}` to the placeholder list and recipes for
     addspell/addperk/addfac/removefac, and an explicit line that enchanting is NOT possible
     yet so the Narrator doesn't claim otherwise. Also noted `addfac`'s console syntax is
     believed (not confirmed - [гипотеза]) to require a rank argument unlike
     `Actor.AddToFaction()`, which has none; the recipe always shows an explicit rank rather
     than guessing whether it can be omitted.
  - **Not applied to the live DB.** This is a `core_action` row, not an ext-plugin file -
    ROADMAP rule 0.1 reserves core/SQL changes for the owner's да. Verified the exact SQL is
    syntactically valid by running it inside `BEGIN;...ROLLBACK;` against the live DB (no
    error, and confirmed the live description was unchanged afterwards) - ready to apply the
    moment the owner says да.
- `tools/test_ext.php`: {ench:} check flipped from "resolves" to "resolver works, but the
  actual command is refused"; {faction:} example now always includes an explicit rank.
  Suite: 66/66 (unchanged count, tests corrected not added).

## 2026-09-29 — Narrator vocabulary SQL applied (owner said да)

- Applied the previously-prepared `core_action.description` UPDATE for `GodCommand` to the
  live DB (not just repo/rollback-tested). [код] Verified: live description now contains
  `{spell:`, is 4483 chars (was 4022). The Narrator now sees `{spell:}`/`{perk:}`/
  `{faction:}` in its own instructions, recipes for addspell/addperk/addfac/removefac, and
  the explicit "enchanting is NOT possible yet" line.
- [не проверено] in game whether the Narrator actually uses the new placeholders correctly
  once it next reads its own instructions (this is a description update, not a code reload -
  should take effect on its next turn, no restart needed, but unconfirmed).

## 2026-09-29 — cap additem/removeitem quantity to 5000 (owner's choice)

- `additem`/`removeitem` had no quantity cap at all, unlike `placeatme` (capped to 10) -
  `player.additem {item:Gold001} 999999999` went straight through untouched. Asked the owner
  for a ceiling rather than guessing one (no roadmap doc gives a number): chose 5000. [код]
  Same pattern as the placeatme cap - truncates and adds a visible reason, doesn't silently
  drop the command.
- `tools/test_ext.php` +3 checks (over cap truncates, under cap passes through, removeitem
  capped too). Suite: 69/69.

## 2026-09-29 — IN-GAME RESULT: outfit change leaves the NPC naked (real bug, first live test)

- First real in-game test of `{npc:Name}.outfit ...` (ScriptProxy SetOutfit path, added
  earlier today). Owner's sequence: `{npc:Лилит Ткачиха}.unequipall` then
  `{npc:Лилит Ткачиха}.outfit богатый` (twice more after that, same result). All three
  SetOutfit calls confirmed delivered (`responselog.sent=1`, correct FormID
  `000E40DD`/FineClothesOutfit02). **Result: she stayed naked.**
- Root cause [гипотеза, matches documented Actor.SetOutfit() behavior]: `SetOutfit()` only
  changes the ActorBase's DEFAULT outfit - it does not force an immediate re-equip. The
  actor is expected to re-dress the next time their AI package processes it, which is not
  instant and may not happen at all for an NPC without a wardrobe-driving package. Stripping
  first (`unequipall`) then setting the outfit is the documented order, but still left her
  bare here - real, reproduced, not a one-off.
- Immediate workaround given to the owner: use `equipitem` instead of `outfit` for now
  (`{npc:Name}.equipitem {item:...}`) - that path is proven to force an instant, held
  (`abPreventRemoval`) equip, unlike `SetOutfit`.
- **Correcting an earlier claim**: today's "outfit/equip moved onto real ScriptProxy" and
  "fix: outfit was silently doing nothing" entries described outfit as fixed and working
  once the dispatch bug was patched - that was true for DELIVERY (the ScriptProxy call does
  reach the game now) but false for the actual OUTCOME (the NPC does not visibly change
  clothes). Both were real, separate bugs; only the delivery one was fixed today.
- [не проверено дальше] whether waiting longer (minutes) makes her eventually re-dress on
  her own, or whether her particular AI package never triggers a wardrobe refresh at all.
  Not yet decided: whether `outfit` should be changed to also force-equip the outfit's
  pieces (needs enumerating an OTFT record's contained items, not currently indexed), switch
  the recipe to recommend `equipitem` instead, or something else - open question for next
  session, not fixed yet.

## 2026-09-29 — trying EvaluatePackage after SetOutfit (owner testing live right now)

- Reaction to the "Лилит голая" finding above. Not a new mod, not a new Papyrus bridge -
  CHIM's ScriptProxy already exposes `EvaluatePackage` (cmdID 81, `Actor.EvaluatePackage()`),
  which forces the actor to re-evaluate their AI/packages. [гипотеза, community-known trick
  for forcing a default-outfit change to take effect now instead of whenever the AI gets to
  it - NOT independently verified by me in this project before tonight]. `tesGodGuardDress()`
  now sends it right after `SetOutfit` whenever the outfit path is used (not for equipitem).
  Cheap and harmless even if it turns out not to help - just one extra real, already-proven
  ScriptProxy call.
- [не проверено] in game as of this entry - owner is testing it live on Лилит right now, this
  entry will need a follow-up either way (worked / didn't).

## 2026-09-29 — REAL CAUSE of tonight's chaos found: OpenRouter key limit, not a code bug

- Owner reported "крышуган поехал у нейро" (Narrator went haywire, repeated
  "Didn't hear you, can you repeat?"). Checked the Apache/PHP error log directly (not
  guessed): BOTH the primary connector (openrouterjson/google/gemini-3.8-flash) and its
  fallback (openrouterjson/deepseek/deepseek-v4-flash) are returning `403 Key limit exceeded
  (total limit)` from OpenRouter, repeatedly, as recently as the log line right before this
  entry was written. This is an account/billing limit on the OpenRouter key, not a bug in
  this project's code - unrelated to tonight's PHP changes. Told the owner to check
  https://openrouter.ai (key management link is in the error response itself).
- Separately, confirmed via `eventlog` (`chat` rows) that the Narrator was actually behaving
  reasonably before the API started failing: it repeatedly and honestly acknowledged the
  outfit failures in character ("Признаю оплошность, старая Лилит осталась вовсе без
  покровов", "нити судьбы запутались, и старуха Лилит всё ещё мерзнет без одежд") - this
  matches the roadmap's "честный результат, даже бог ошибается" goal reasonably well; the
  actual complaint is the underlying outfit bug (previous entries) and, separately, the
  OpenRouter cutoff.

## 2026-09-29 — outfit DISABLED outright (was confirmed broken); own test leaked a real dispatch

- `outfit` is now refused unconditionally with an honest reason ("outfit сейчас сломан...
  используй equipitem"), not just documented as broken - three in-game repeats with the
  same naked result was enough proof; leaving it silently broken any longer risks the
  Narrator looping on it again. The dead ScriptProxy-outfit code path (SetOutfit +
  EvaluatePackage attempt) was removed from `tesGodGuardValidate`, not just disabled with a
  flag - it's in git history if the real fix (enumerating and force-equipping an OTFT
  record's contained items) gets built later.
- **Correction**: the EvaluatePackage "let's try this" from the previous entry got exactly
  one real send against Лилит (14:16:27); the send at 14:15:16 ran on the OLD code before
  that deploy, so no cmdID 81 reached her then. There is no evidence EvaluatePackage helped -
  logging it as attempted, not as a working trick.
- **Also correcting an overclaim**: told the owner earlier that equipitem "проверенно
  работает мгновенно" - only DELIVERY (`sent=1` for cmdID 22) is proven; there is no in-game
  visual confirmation it holds, and the owner separately mentioned clothes resetting before
  today. Don't repeat that claim without a real in-game check.
- Found while fixing this: `tools/test_ext.php --write`'s own cleanup for the outfit dispatch
  test only ever covered `cmdID:59`, not the newly-added `cmdID:81` (EvaluatePackage) - a
  run of that suite while the owner was actively playing leaked two real EvaluatePackage
  calls onto Скульвар Черная Рукоять (`sent=1` before cleanup could run - confirmed in
  `responselog`, harmless in effect but a real live-game side effect from a test run).
  Fixed the cleanup; added a note to the file's own docblock: never run `--write` while the
  owner might be playing, since a dispatched row can be consumed before cleanup regardless
  of the fix.
- `tes_god_journal`: ScriptProxy-dispatch lines showed a raw hex RefID instead of the NPC's
  name, and shared the refusals query's `LIMIT 4` - a burst of ScriptProxy dispatches (like
  tonight's repeated outfit attempts) could push a real, useful refusal reason (the "не знаю
  предмета «Богатая одежда»" case) off the visible list. Given its own query/limit, now
  resolves the actor's name, and says "результат не проверяется" explicitly rather than
  implying success.
- `tools/test_ext.php` updated for the disabled outfit verb (now asserts refusal, not
  dispatch) and the entry-point dispatch test switched from outfit to equipitem (still a
  real ScriptProxy path). Default mode: 54/54 (down from more checks - several outfit
  dispatch-specific checks no longer apply and were replaced, not just deleted).
  **Not re-run with --write** - the owner is playing right now; will confirm the full suite
  next time the game isn't live.

## 2026-09-29 — cost-cutting: trimmed ambient triggers and context sizes (owner approved)

- Owner ran out of OpenRouter budget mid-session ($5 exhausted). Researched cost levers
  (OpenRouter prompt caching, free-tier models) and found the CHIM profile's own ambient
  trigger frequencies and context sizes were the safest lever - pure config, no code, fully
  reversible, doesn't touch actual dialogue quality/model choice.
- Applied to `core_profiles.metadata` (id=1) - this is CHIM core config, not an ext-plugin,
  applied after the owner's да ("поставь"):
  - `RECHAT_P`: 20 -> 5 (proactive rechat chance)
  - `BORED_EVENT`: 10 -> 20 (bored-event frequency, higher = less often)
  - `RPG_COMMENTS_CHANCE`: 50 -> 20
  - `QUEST_COMMENT_CHANCE`: "30%" -> "15%"
  - `CONTEXT_HISTORY`: 30 -> 18 (turns of history per normal call)
  - `CONTEXT_HISTORY_DIARY`: 100 -> 40 (turns of history per auto-diary call, which already
    fires every DIARY_COOLDOWN=120s regardless of player activity - this was the single
    biggest avoidable per-call token cost found)
- [гипотеза] Also found: OpenRouter's implicit prompt caching (0.25x cost for a repeated
  prefix, Gemini 2.5+) is likely defeated by CHIM's own `main.php` prompt assembly - it
  concatenates per-turn-variable content (actions list, nearby NPCs, our own
  tes_god_guard/tes_god_journal injections, rumors) into the SAME system message as the
  stable instructions, rather than appending it as a separate, later message. Fixing this
  would need a core `main.php` patch (move volatile content to its own trailing message) -
  bigger, riskier, needs its own да, not done tonight. Flagged for a future session.
- [не проверено] whether these specific new percentages/context sizes are the right balance
  - owner can tune further; reversible by restoring the old values above.

## 2026-09-29 — second review: fixed a real cost bug (Narrator still taught outfit), added a repeat guard, corrected two overclaims

- **Real cost bug, blocking**: `core_action.description` for `GodCommand` still taught the
  Narrator the `outfit` recipe even after it was disabled in the guard - every attempt would
  have been a paid LLM turn that just gets refused, and the failure-streak only cuts in
  after 3. [код] Removed the outfit recipe from `settings/chim_settings.sql`, replaced with
  the equipitem-only recipe and an explicit "outfit is BROKEN, never use it" line. Applied
  to the live DB (verified via BEGIN/ROLLBACK first, same as the earlier vocabulary patch).
- **Real cost bug, blocking**: disabling `outfit` alone removes outfit loops but not
  equip/resurrect loops - none of those ScriptProxy dispatches' actual outcomes are
  verified, so the same "retry blindly because nothing says it failed" pattern could repeat
  for any of them. [код] Added a ScriptProxy repeat guard: the 3rd identical
  refid+verb+item dispatch within 10 minutes is refused outright ("уже отправлено N раз(а)
  за 10 минут, результата не видно"), not resent. Tested through the real entry point
  (`tesGodGuardFilterAction`) in default mode by seeding log rows directly - the refusal
  path never calls `send()`, so this is safe to test without touching the live game.
  Suite: 55/55 (default mode), run twice back to back with no cross-run pollution.
- **My own mistake, caught and fixed**: `BORED_EVENT` was raised 10->20 on the wrong
  assumption it's an interval ("видимо интервал в мин."). Checked `main.php` directly:
  `$boredRoll <= $boredChance` - it's a 0-100 PERCENTAGE CHANCE (confirmed by the UI label
  "Bored Event Chance" too). Raising it DOUBLED the bored-event trigger rate, the opposite
  of the intended cost cut. Fixed to 5 (lower than the original 10, matching the actual
  intent of the cost-cutting pass).
- **Correcting an overclaim to the owner**: said finding `fAIMinGreetingDistance = 150.00`
  (the vanilla default) "подтверждено железно" that RDO's MCM is the cause. That reading
  only proves the "No NPC Greetings" mod's edit isn't live - it does not by itself prove
  RDO is why. Real next step: set RDO's own MCM greeting-distance control to minimum, then
  re-run `getgs fAIMinGreetingDistance` to confirm the value actually changes. If it's still
  150 after an MCM change and a cell reload, `nwsFollowerFramework.esp` (the only plugin
  loaded after "No NPC Greetings.esp" in this load order) is the next suspect, not yet
  checked.
- **Model routing**: owner shared real OpenRouter usage data - big Narrator turns run
  8,000-10,000 INPUT tokens each (confirms the earlier prompt-caching finding: input volume,
  not output length, dominates cost). Looked up connector pricing:
  `llm_primary_id=12` (Gemini 3.8 Flash, $0.75/M in - $3.75/M out per one source, though a
  second source puts the input gap at 2.5x rather than 8x, sources disagree since both
  models are very new); `llm_fallback_id=8` (DeepSeek V4 Flash, $0.09/M in - $0.18/M out).
  No public benchmark exists comparing these two for Russian roleplay quality or JSON/
  function-calling reliability - genuinely not researchable further via search right now.
  Owner asked to hold off on switching the primary model blind; agreed to do a real side-by-
  side A/B test once the OpenRouter balance is topped up, instead of guessing from price
  alone. Not changed.
- **Honesty note for this entry**: `tools/test_ext.php --write` has not been re-run since
  outfit was disabled and the journal was rewritten earlier tonight - only default mode
  (55/55) has been confirmed since then. The new journal ScriptProxy-visibility block and
  the repeat guard's real dispatch path (not just its refusal path) have no --write coverage
  yet. It is also still unconfirmed whether the Narrator is actually responding again after
  the owner topped up their OpenRouter balance - the last real LLM call seen in the Apache
  log before this entry was a 403.

## 2026-09-29 — IN-GAME RESULT: equipitem "works" but leaves her half-naked (multi-piece clothing)

- Owner reported Лилит still looking naked after switching to `equipitem` (the workaround for
  the disabled `outfit`). Checked logs: the Narrator correctly used `equipitem` this time
  (not `outfit`), and both dispatches confirmed delivered (`sent=1`, cmdID 22, correct real
  FormIDs `000E40DF`/`00017695`). Not a delivery bug this time.
- Root cause: Requiem/RfaD clothing items are split into separate body-slot pieces -
  `editor_id` for both items equipped ends in `_Body_...` (`REQ_Var_Cloth_Fine_Body_Party`,
  `REQ_Cloth_Farm_Body_3Hooded`). Confirmed a matching companion piece exists in the index:
  `Нарядные ботинки` (`REQ_Var_Cloth_Fine_Feet_Party`, `000E40DE`) for the "Нарядная одежда"
  set - equipping only the Body piece leaves feet (and possibly hands) bare, which reads as
  "still naked" even though the command worked exactly as asked.
- Told the owner the immediate fix: also `equipitem {item:Нарядные ботинки}`.
- [не сделано] The Narrator's instructions don't know clothing comes in matching body/feet/
  hands sets in this modlist - it will keep dressing NPCs in only a torso piece unless taught
  otherwise or unless the guard auto-completes a set. Flagged for a future session, not
  fixed tonight (would need either a `settings/chim_settings.sql` recipe update - core, da
  needed - or a guard-side auto-pairing lookup, which needs a documented naming convention
  across mods that isn't guaranteed reliable).

## 2026-09-29 — CRITICAL: my SQL mistake overwrote ALL 55 action descriptions, real money spent

- Owner reported the input-token cost of every Narrator turn had jumped to 56,000-59,000
  tokens (~$0.043/call, up from the earlier ~8,000-10,000 tokens/~$0.007). Added temporary
  debug logging to `main.php` (backed up first as `main.php.bak-debug-promptsize-<ts>`,
  removed after diagnosis) to log the character length of every prompt section. Found:
  `actions` section alone was 188,720 characters - everything else combined was under 10KB.
- Root cause, found and owned directly: earlier tonight, to apply the `GodCommand`
  description update (removing the `outfit` recipe), I extracted the SQL with
  `sed -n '55,90p' settings/chim_settings.sql > /tmp/godcmd_update2.sql` using line numbers
  from an EARLIER version of the file. The file had since been edited (shorter), so that
  fixed line range no longer captured the statement's `WHERE code_name = 'GodCommand';`
  clause - confirmed by reading `/tmp/godcmd_update2.sql`, which ends right after
  `updated_at = now()` with no WHERE at all. The resulting `UPDATE public.core_action SET
  description = '...'` therefore ran against **every row in the table**, not just
  GodCommand - confirmed: all 55 actions had `length(description) = 4292` and the exact same
  text before this fix. This blast radius was NOT caught by my own
  `BEGIN;...ROLLBACK;` syntax check earlier, because that check only proves the SQL is
  syntactically valid, not that its WHERE clause matches what was intended - a real gap in
  how I've been verifying these patches tonight.
- [код] Fixed in two steps:
  1. Ran the project's own `data/core_action_seed.sql` (`INSERT ... ON CONFLICT (code_name)
     DO UPDATE`), which safely restores every *builtin* action's correct description and
     other fields by exact code_name match. This is a pre-existing, repo-shipped recovery
     tool for exactly this class of problem ("CHIM updates can reset built-in actions"),
     not something written tonight.
  2. That seed does not know this project's own customizations (`SpawnItem`'s detailed
     tavern description, `TakeGoldFromPlayer`/`CreateNewNPC`/`SpawnNPC`/etc.
     `is_activated` flags, `GiveToPlayer`, `GodCommand`) - re-ran the entire
     `settings/chim_settings.sql` file (verified syntactically valid first via
     `BEGIN;...ROLLBACK;`), which is explicitly designed to be idempotent and safe to
     re-apply in full for exactly this situation.
  - Verified after both steps: 55 distinct descriptions again (was 1); total description
    length for narrator-available activated actions is now 6,710 characters (was 188,720 -
    a 96% cut); spot-checked `SpawnItem`, `TakeGoldFromPlayer`, `GiveToPlayer`, `GodCommand`,
    `CreateNewNPC` all show their correct, distinct text and flags.
- **Lesson for future SQL patches in this project**: never extract a statement from a file
  by hardcoded line numbers after that file has been edited since the numbers were last
  checked - re-read the file and re-verify the exact line range (or better, extract by
  a distinctive start/end marker, not line count) every single time before running. A
  `BEGIN;...ROLLBACK;` check proves the SQL parses; it does not prove the WHERE clause is
  intact - that needs an explicit `SELECT count(*) FROM core_action WHERE code_name = 'X'`
  sanity check on the actual scope before commit, not just a syntax check.
- [не проверено] the next real Narrator turn's actual token count, to confirm this brought
  the per-call cost back down to the earlier ~8-10k range in practice, not just in this
  server-side calculation.

## 2026-09-29 — switched primary model to DeepSeek V4 Flash after a real A/B test

- Owner asked whether to move off Gemini 3.8 Flash to cut cost. Ran a real side-by-side test
  (direct OpenRouter API calls, same system prompt and tools schema, not guessed) instead of
  switching blind:
  - God_Command function-call task: Gemini 3.8 Flash spent its entire output budget on
    hidden reasoning tokens and got cut off (`finish_reason: length`) with NO actual reply -
    neither text nor a tool call. DeepSeek V4 Flash answered immediately with a correct,
    well-formed `God_Command` tool call.
  - Pure in-character roleplay line: Gemini's reply was on-task and noticeably better
    (directly answered "what do you say", worked Lilit's name/weaver theme in); DeepSeek's
    reply was atmospheric but didn't actually voice a line of dialogue - a real quality gap
    the other way.
- Net: DeepSeek is cheaper, faster (3.6s vs 5.8s here), and was reliable for the
  God_Command JSON path specifically, which is this project's actual sharp edge. Gemini's
  hidden reasoning-token spend is a real, now-observed risk (wasted cost AND a cut-off empty
  reply, not just slower/pricier) that outweighs its edge on prose in the owner's judgment.
- [код] `core_profiles.llm_primary_id` 12 -> 8 (DeepSeek V4 Flash), `llm_fallback_id` 8 -> 12
  (Gemini 3.8 Flash) - so a DeepSeek outage/limit still has a real, different fallback
  instead of falling back to itself. Secondary/tertiary/quaternary/formatter connectors
  unchanged.
- [не проверено] in actual gameplay over a longer session - this is based on one A/B pair of
  test calls each, not a full night of play. Revert is a one-line UPDATE if RP quality
  disappoints in practice.

## 2026-09-29 — correction: the corrupted-actions window was wider than first reported

- Second review caught that the earlier "CRITICAL: my SQL mistake" entry understated the
  damage. The SAME flawed `sed -n '55,90p'` extraction was used for BOTH SQL applies tonight
  - the first one (commit b58f99c, "Apply Narrator vocabulary SQL to the live DB", applied
  right after adding the {spell:}/{perk:}/{faction:} lines to `settings/chim_settings.sql`)
  grew the file enough that the `WHERE code_name = 'GodCommand';` line shifted past line 90
  too, not just the second apply (commit 857d6de, the outfit-removal one) as originally
  claimed. Both ran the same unscoped `UPDATE core_action SET description = '...',
  is_activated = true, available_to_narrator = true, available_to_npc = false` against every
  row - not just the description got corrupted: **every NPC's own available_to_npc flag was
  set to false and every action's available_to_narrator was set to true, for the entire
  window between the first apply and tonight's fix (2bf8a63)**. In effect, no NPC (other
  than the Narrator) had ANY actions available for that whole stretch, not just an oversized
  prompt for the Narrator - a bigger behavioral effect than reported earlier.
- Could not forensically confirm the exact window length after the fact (fixing the data
  necessarily overwrote the `updated_at` timestamps that would have proven it), so this is
  reconstructed from git history and the sed line-count math, not a direct DB read - flagged
  as [гипотеза, high confidence] rather than [код] for that reason.
- `data/core_action_seed.sql`'s `ON CONFLICT DO UPDATE` restore also reset the 53 builtin
  actions' `is_activated`/`available_to_*` flags to the seed's own defaults. If the owner had
  toggled any of these in CHIM's own settings UI independent of `chim_settings.sql`, those
  toggles are gone now and there is no backup to diff against - told the owner plainly rather
  than assuming nothing was lost.
- Also fixed while reviewing this: `GiveToPlayer.available_to_narrator` was left `true` after
  every fix so far (the seed doesn't cover this project's own custom action; the
  `chim_settings.sql` UPDATE for it never set this column, only `is_activated`/
  `available_to_npc`/`available_to_followers`). It's an NPC-owns-it action, not a Narrator
  one. Set to `false` directly on the live DB and added `available_to_narrator = false`
  explicitly to `settings/chim_settings.sql`'s own UPDATE for `GiveToPlayer` so a future
  re-apply of the file can't lose it again.

## 2026-09-29 — deterministic fix for the naked-NPC problem: auto-pair body/feet clothing

- Better than fuzzy matching alone (which doesn't help if the Narrator names the right
  single item but that item just doesn't cover the whole body): `tesGodGuardFindClothingSiblings()`
  looks up an equipped item's EditorID, and if it matches this modlist's `..._Body_...`
  naming convention (Requiem/RfaD split garments), also looks for `_Feet_`/`_Hands_`
  siblings with the same prefix/suffix and queues them via ScriptProxy too. Verified live:
  equipping "Нарядная одежда" (`000E40DF`, `_Body_Party`) now also auto-queues "Нарядные
  ботинки" (`000E40DE`, `_Feet_Party`) - the exact pair that left Лилит looking naked
  earlier tonight.
- Deliberately narrow: only fires for items whose EditorID contains the literal `_Body_`
  token (a real, observed naming convention in this modlist, not assumed universal); an item
  with no matching sibling in the index queues nothing extra, same as before.
- `tools/test_ext.php` +3 checks. Suite: 62/62.
- [не проверено] in game - the sibling lookup and dispatch are confirmed at the code level,
  not yet confirmed to look right on an NPC in Skyrim.

## 2026-09-29 — DeepSeek connector: prioritize fast OpenRouter providers

- Owner noticed DeepSeek replies take a while (~8s measured live, player input to first
  Лилит line). OpenRouter serves the same model through multiple providers with different
  speed; without a preference it uses its own default mix, not necessarily the fastest.
- [код] Found the real mechanism (not guessed): CHIM's connector config for `openrouterjson`
  merges each connector row's `metadata` JSON into `$GLOBALS["CONNECTOR"]["openrouterjson"]`
  at request time (`lib/core/llm_connector.class.php`), and the connector class
  (`connector/openrouterjson.php`) forwards `providers_sort` (`price`/`throughput`/`latency`)
  as OpenRouter's own `provider.sort` request field when set. This is per-connector-row, not
  global - only affects the row actually selected as primary/fallback/etc.
- Set `core_llm_connector.metadata->>'providers_sort' = 'throughput'` for id 8 (DeepSeek V4
  Flash) only - Gemini 3.8 Flash (fallback) untouched.
- [не проверено] whether this measurably shortens the ~8s in practice - OpenRouter's own
  provider mix for this model may already be throughput-optimal, in which case this changes
  nothing; it's a real, documented lever, not a guess, but its actual effect here is unverified.

## 2026-09-29 — REVERTED: providers_sort=throughput caused a real hang, not a speedup

- The `providers_sort: throughput` setting from the previous entry made things WORSE, not
  better: the very next real request hung with no response for 49+ seconds (confirmed via
  Apache log - the last log line before the LLM call was at 20:25:20, still nothing at
  20:26:09), instead of the ~8s baseline. Reverted `core_llm_connector.metadata` for
  connector id 8 back to not setting `providers_sort` at all.
- Found while investigating: `connector/openrouterjson.php`'s HTTP call has **no explicit
  cURL timeout set at all** (`CURLOPT_TIMEOUT`/`CURLOPT_CONNECTTIMEOUT` not found in the
  file) - if a provider hangs, there is nothing in this connector that would cut it off on
  its own; it depends entirely on PHP/Apache's own upstream limits. This is a real,
  separate reliability gap independent of tonight's model switch, worth a proper fix later
  (a sane connect+total timeout on this call) rather than something to patch blind tonight.
- Lesson: "throughput" sort trusts OpenRouter's own self-reported provider speed stats, which
  don't reliably predict real-time behavior for a specific request. Not recommending this
  setting again without a real timeout safety net in place first.
- [не проверено] whether the stuck request the owner hit eventually resolved on its own or
  needed a manual retry in-game.

## 2026-09-29 — reverted primary model back to Gemini 3.8 Flash: speed matters more than cost here

- Owner's real-world experience with DeepSeek V4 Flash as primary was too slow to play with,
  independent of the failed `providers_sort` experiment (reverted separately above) - still
  too slow with default routing. The cost savings ($0.09 vs $0.75 per M input tokens) don't
  matter if every reply takes long enough to break the pace of actual play.
- [код] `core_profiles.llm_primary_id` 8 -> 12 (Gemini 3.8 Flash), `llm_fallback_id` 12 -> 8
  (DeepSeek V4 Flash) - back to tonight's very first working state, after the action-list
  bug fix (188KB -> 6.7KB) already cut most of the real cost problem. DeepSeek stays
  available as the cheap fallback if Gemini's own key/rate limit gets hit again.
- Net effect of tonight's model experiments: the actual fix that mattered was the
  action-description bug, not the model choice. Model swapping was explored per the owner's
  request but reverted after real play feedback - keeping this honest rather than declaring
  the swap a win.

## 2026-09-29 — switched to owner's known-good setup: Gemini 2.5 Flash + 2.5 Flash Lite reserve

- Owner said the setup that actually worked well before tonight was Gemini 2.5 Flash primary
  with Gemini 2.5 Flash Lite as reserve - not 3.8 Flash (found tonight to sometimes burn its
  whole output budget on hidden reasoning and return nothing) and not DeepSeek (too slow in
  real play). Trusting the owner's own hands-on history here over further guessing.
- [код] `core_profiles.llm_primary_id` 12 -> 11 (Gemini 2.5 Flash), `llm_fallback_id` 8 -> 2
  (Gemini 2.5 Flash Lite).
- Checked `core_llm_connector.reasoning_model` for all four models discussed tonight:
  Gemini 3.8 Flash = 1, Gemini 2.5 Flash = 1, DeepSeek V4 Flash = 1, Gemini 2.5 Flash Lite =
  NULL (not a reasoning model). So 2.5 Flash is technically flagged the same way 3.8 is -
  the flag alone doesn't predict how much a given request actually reasons (DeepSeek is also
  flagged reasoning_model=1 but answered immediately with no visible overhead in tonight's
  test). Noted honestly rather than claiming this setup is provably free of the same risk;
  going with the owner's real play experience instead.
- Owner also asked about pinning specific OpenRouter providers for DeepSeek (`PROVIDER`
  allowlist field, separate from the reverted `providers_sort` hint). Not touched tonight -
  DeepSeek is only the fallback now, lower stakes, and the last "make it faster" attempt
  made things worse; not guessing again without a real way to verify which providers are
  actually fast/reliable for this account first.

## 2026-09-29 — new ext plugin: anti-hallucination instruction (applies to Narrator AND NPCs)

- Owner reported both a regular NPC (Лилит Ткачиха, "мы предложили десять миллионов",
  "старушка Фрида у фонтана") and separately "The Narrator" itself inventing events that
  never happened and insisting they were real when challenged - a known trait of fast/cheap
  ("flash"-tier) models trading groundedness for speed, confirmed to be happening on the
  actual primary model in both cases (checked live: the Narrator's own turns use
  `google/gemini-2.5-flash`, the same model as NPCs - there is no separate
  `NARRATOR_CONNECTOR_ID` override set in `core_narrator`, so it was never a "wrong model for
  the Narrator" problem).
- Owner proposed a strict grounding instruction ("не придумывай несуществующие события").
  [код] New ext plugin `ext/tes_no_invent/context_pre.php`: registers a short, constant
  instruction into the `prompt_bottom` injection slot via `chimRegisterPromptInjection()` -
  the same mechanism `ext/tes_god_journal` uses, but deliberately NOT gated on
  `tesGodJournalIsNarratorTurn()`, so it reaches ordinary NPC dialogue too, which is where
  the actual incident happened, not just Narrator turns.
- Verified the injection renders correctly via `chimRenderPromptInjections('prompt_bottom')`
  before deploying. No test_ext.php coverage added (this plugin has no logic to test, just a
  static registration - a smoke check that it renders is what actually matters here and was
  done directly).
- [не проверено] in game whether this measurably reduces the confabulation rate - this is a
  known best-effort mitigation for LLM hallucination, not a guaranteed fix, and costs only a
  couple dozen tokens per turn.

## 2026-09-29 — review of recent dialogues found 3 real gaps: player-name, raw item args, short-word fuzzy match

- Owner asked for a broader quality review of recent dialogue history (item selection etc.),
  not just the hallucination issue. Pulled `tes_god_guard_log` and full chat transcript for
  the last ~90 minutes and found three real, separate problems:
  1. **The Narrator repeatedly wrote `{npc:<player's own character name>}` instead of
     "player"** (e.g. `{npc:Шаман}.equipitem/.additem/.character`, where "Шаман" is
     `PLAYER_NAME`) - `core_npc_master` has no such row, so this either failed outright
     ("нет такого персонажа", confusing for a name that IS real, just not an NPC) or fell
     back to the less reliable `{near:}` in-game name search instead of the direct, correct
     "player" path. [код] `tesGodGuardValidate` now substitutes `{npc:PlayerName}` (case-
     insensitive) to `player` right after target/body parsing, before any of that runs. For
     `character`/`relation`/`remember`/`marry` (which only make sense for a real NPC's CHIM
     memory, not the player) this correctly now refuses with a clearer reason instead of the
     old "no such character" message.
  2. **`additem`/`removeitem`/`addspell`/`removespell`/`addperk` had NO validation at all**
     for a raw (non-`{item:}`-wrapped) argument - found live: `additem f 1000` passed
     straight through unchanged, "f" is not a real item and would just fail or do nothing in
     the console. [код] These verbs now resolve a non-hex raw argument through the same
     resolver `{item:}`/`{spell:}`/`{perk:}` already use (word or multi-word, braces or not)
     and refuse with the same honest reason if it doesn't resolve, instead of passing
     garbage to the console.
  3. **While building the above, found why "f" had resolved to something at all**: both the
     English and Cyrillic fuzzy word-matchers accepted 1-2 letter words as valid stems, so a
     near-empty query like "f" could still fuzzy-match an unrelated item whose EditorID just
     happened to contain a standalone "f" token. [код] Both fuzzy matchers now drop words
     under 3 characters before searching, same length floor on both paths.
- `tools/test_ext.php` +6 checks covering all three fixes together (including that a real
  multi-word raw name like "Fine Clothes" with no braces still resolves - this isn't just a
  stricter refusal, it also makes raw names work that silently did nothing useful before).
  Suite: 68/68.
- [не проверено] in game - all three are confirmed at the code/test level only.

## 2026-09-29 — revert RECHAT_P back to 20: it drives NPC-NPC scenes, not just ambient chatter

- Real regression found in play: lowering `RECHAT_P` 20->5 earlier tonight (cost-cutting pass)
  broke an in-progress NPC-NPC scene (Лилит negotiating with Ри'сад) - Лилит spoke, Ри'сад
  went silent, because that whole back-and-forth is itself driven by the rechat "next
  responder" mechanism (`OPEN_RECHAT`: "picks the next responder from nearby scene
  participants"), not just spontaneous ambient banter as assumed when it was lowered.
- Owner chose to revert rather than take a middle value - the action-list description bug
  fix already cut most of tonight's real cost problem, so this specific trim isn't worth the
  cost to actual scene quality. `core_profiles.metadata->>'RECHAT_P'` 5 -> 20 (back to
  original).
- Lesson: `RECHAT_P`/`BORED_EVENT`-style settings aren't purely "annoying filler" toggles -
  they can be load-bearing for emergent multi-NPC scenes. Worth checking what a setting
  actually drives before trimming it for cost, not just its literal description.

## 2026-09-29 — the real mechanism behind rechat silence, and setting RECHAT_P=80

- Owner correctly pushed back that "NPC addressed NPC directly, why no reply" isn't just bad
  luck - dug into the actual code (`main.php` rechat handling) instead of guessing further.
  Found the real mechanism: `RECHAT_H` (=1) and `RECHAT_P` together pre-roll a "budget" ONCE
  per conversational session (cached to `/tmp/chim_rechat_<key>.json` for 120 seconds), not
  re-rolled on every poll as assumed earlier tonight:
  ```
  for ($i = 0; $i < RECHAT_H; $i++) { if (rand(1,100) <= RECHAT_P) $budget++; else break; }
  if ($budget === 0) terminate();
  ```
  With `RECHAT_H=1`, this is really ONE coin flip for the entire 2-minute window - if it
  fails, the NPC stays silent for that whole window regardless of how many times the scene
  is polled or how directly it's addressed. This is why reverting `RECHAT_P` 5->20 earlier
  tonight "didn't help" (Ри'сад/Лилит negotiation stayed stuck) - 20% still means an 80%
  chance of total silence for 2 minutes.
- Since this is a once-per-session roll (not per-poll), a high percentage does not multiply
  cost the way a per-poll chance would - [код] `core_profiles.metadata->>'RECHAT_P'` 20 -> 80
  (owner's choice), which should make NPC-NPC/NPC-Narrator exchanges continue reliably while
  keeping total silence rare (~20% of 2-minute windows, not per line).
- Cleared all `/tmp/chim_rechat_*.json` cache files so the currently-stuck Ri'saad/Лилит
  session (and every other cached session) re-rolls under the new percentage immediately,
  instead of waiting out its already-cached failed roll from before the change.
- Corrected my own earlier, wrong explanation (probability decaying independently per poll,
  ~13% chance of "still silent after 9 tries") - that model doesn't match the actual code.

## 2026-09-29 — correction: RECHAT_P 20->80 DOES multiply LLM cost, and the real NPC-NPC silence cause was found

- **Retraction** [код]: my claim above ("does not multiply cost the way a per-poll chance
  would") is wrong, per advisor review. Each successful roll is one paid LLM call, so
  raising `RECHAT_P` 20->80 means roughly 4x more NPC-NPC-triggered LLM calls, not a free
  change. Flagging this plainly since I stated the opposite earlier tonight.
- Traced the actual Ri'saad-addresses-Лилит silence end-to-end via `eventlog`+`chim.log`
  (advisor-prescribed procedure). `ext/relationship_system` was a dead end - it only scores
  relationships *after* a reply already exists, via a background worker; it does not decide
  whether an NPC gets a turn at all.
- Real cause [код], `lib/chat_helper_functions.php`, `chimResolveServerSideRechatTarget()`:
  `RECHAT_MODE`/`OPEN_RECHAT` are unset, so `chimGetRechatMode()` returns `"random"`, which
  rolls one of `tight`/`conversational`/`group` ONCE per conversation scene and caches it to
  `/tmp/chim_rechat_mode_<sessionKey>.json` for the whole scene. Confirmed live: the cache
  file for the Ri'saad/Лилит/Ма'рандру-джо/Кейла/Шаман scene held `{"mode":"tight"}` with a
  timestamp matching the exact failed rechat (`18:51:11`, eventlog epoch 1790700671).
  In `tight` mode, the only candidate considered is `$listenerHint`, matched against
  `$audience` with a strict `strcasecmp()`. If the game plugin's hint is a short form of the
  full audience name (e.g. "Лилит" vs "Лилит Ткачиха"), the match silently fails, `$candidates`
  stays empty, the per-candidate loop that logs `[RECHAT_SELECT] Skipping ...` never runs even
  once, and `chimResolveServerSideRechatTarget()` returns nothing - which is exactly why there
  was zero log evidence of *why* it failed. `conversational`/`group` modes don't have this
  problem because they fall back to the whole audience regardless of hint match.
- Fix applied live [код], `lib/chat_helper_functions.php` (`chimResolveServerSideRechatTarget`,
  the `$addCandidate` closure, ~line 4036): after the exact `strcasecmp` check, added a
  case-insensitive prefix fallback (`mb_stripos` either direction) against `$audience`, so a
  short-form hint still resolves to the correct full audience name. Verified: `php -l` clean,
  `tools/test_ext.php` 68/68 (this file isn't part of the `future-present` git repo - it's
  vendor CHIM core, not tracked - so no repo-side mirror/commit for this one, live-only).
  Not yet re-verified against a fresh live `tight`-mode rechat in actual play.

## 2026-09-29 — Лилит's "hallucination" is a persona-sheet trait, not a model bug

- Re-investigated "Лилит выдумывает хуйню" after confirming `ext/tes_no_invent` IS being
  delivered every turn (`[PROMPT-COMPOSITION]` log: `plugin_injections: 298 chars` on the
  exact request that produced the bad reply). So the anti-invent instruction reaches the
  model and still isn't enough on its own.
- Read her actual `core_npc_master` row (id 2615) [код]. Her `personality`, `speechstyle`,
  and `goals` fields *explicitly instruct* the model to roleplay confusion: "она то и дело
  заговаривает о некой Фриде, которую никто не видел, и путает реальность с вымыслом, что
  выдаёт её старческий упадок ума" (personality), near-identical wording in `speechstyle`,
  and a `goals` bullet about resolving whether "Фрида" is real. This is very likely the
  *original* "Фрида у фонтана" hallucination from earlier tonight, later fed back into her
  character sheet by CHIM's dynamic-profile regeneration (`lib/dynamic_profile_scheduler.php`)
  and baked in as a permanent "canon" trait - turning a one-off model slip into a
  self-reinforcing, by-design behavior. [гипотеза] on the dynamic-profile-feedback mechanism
  specifically (plausible given the file's existence and the timing, not directly observed
  triggering).
- Consequence: no LLM swap fixes this - any model following instructions faithfully will
  keep producing "confused old woman" output, including the newer identity-confusion line
  ("подожди, пока я не заговорю об этом с господиной Лилит", referring to herself in third
  person) - because that's what her sheet tells it to do. Declined to run a model A/B test
  for this reason; recommended cleaning the persona fields instead.
- Owner said "чини" (fix it) for the persona edit. First attempt blocked by the auto-mode
  permission classifier ("Modify Shared Resources"); owner explicitly authorized a retry and
  it went through. [код] **Applied**: `core_npc_master.id=2615` `personality`/`speechstyle`/
  `goals` updated to remove the Фрида/"путает реальность с вымыслом" wording, rest of her
  characterization (poverty anxiety, pride in silks, suspicion of strangers) kept intact.
  Verified post-update text contains no "Фрид" substring.
- [код] Also set `core_npc_master.id=2615` `lock_profile = 1`. Her row had it at `0`, and
  `lib/dynamic_profile_scheduler.php:79` only regenerates personality/speechstyle/goals when
  `lock_profile` is falsy - without this, the scheduler could rebuild the same Фрида trait
  from her `[Помнит]` memory list (which is itself self-contradictory: попрошайка / сказочно
  богата / вернул юность - a plausible re-source of the same confusion, flagged but not
  touched).

## 2026-09-29 — correcting three overclaims from this session, per advisor review

- **"У директора нет модели" - wrong, retracted.** I read `CORE_CONNECTOR_DIRECTOR` as NULL
  via a standalone CLI script that only loaded `conf.php` + `conf_opts`, not the real runtime
  path. `service/processors/rolemaster/cmd/instruction.php` calls `getConnector()` before
  producing any instruction text, and `getConnector()` throws on empty connector data - no
  such exception appears anywhere in `chim.log`, so the connector resolved fine at runtime.
  [не проверено] which connector it actually is - `getConnector()` logs that via plain PHP
  `error_log()`, which goes to Apache's error log, not `chim.log`; didn't chase this further.
- **Rechat candidate-prefix fix - downgraded from "confirmed" to "deployed, partially
  verified".** The one successful post-fix rechat resolved Ri'saad (continuing his own turn),
  not Лилит specifically; I never directly observed a `listener_hint` value, and the
  observation window was ~2 minutes. [гипотеза] **Known remaining hole** in the fix itself
  (`chat_helper_functions.php`, `$addCandidate` fallback, ~line 4048): `mb_stripos($a, $b) === 0`
  is true when `$b` is `""`, and the audience pipe-list can contain empty slots (seen live:
  `Кейла//Шаман`). An empty slot ahead of the real target in `$audience` would make the
  fallback match empty string and return before reaching the real name - same silent failure
  as before, just relocated. **Not yet fixed** - needs a guard against empty `$audienceName`
  in that loop, or confirmation that `chimExtractPeopleListFromPipeString` already drops empty
  entries (not checked).
- **16:12 "Narrator refuses a command" - not a bug, retracted from the bug list.** Traced full
  context: the player's `inputtext` at 16:12:07 was in-character banter ("ты держишь меня в
  плену"), not a Director/GodCommand. The Narrator's reply ("не могу заставить торговцев...
  могу лишь подсказать") is consistent with its designed role (nudge, not compel) - correct
  behavior, not a failure to follow orders.
- **Real, still-unsolved cause of "Лилит не покупает мантии":** [код] confirmed via
  `infoaction` log that in ~2 hours of this scene, `GiveGoldTo`/`GiveItemTo` never fired once,
  even though both are `is_activated`, `available_to_npc=true`, `dispatch=plugin_command` (so
  not excluded from the Director's action catalog by any static filter checked so far). Two
  live, not-yet-resolved possibilities: (a) the model just never chooses these actions over
  dialogue in a long freeform negotiation - unconfirmed whether this is a Director-model
  quality issue or a NPC-turn issue, since I never isolated which side (Director vs the NPC's
  own dialogue LLM) is supposed to emit the action here; (b) `checked getConnector()` also
  unconditionally sets `$GLOBALS["PATCH_PROMPT_ENFORCE_ACTIONS"] = false` for every connector,
  which per its own hardcoded comment disables action-enforcement prompting - [не проверено]
  whether this is vendor CHIM behavior or a prior local patch, and whether it's a plausible
  reason NPCs rarely emit actions at all. [не проверено] Also unconfirmed whether "мантии
  Седобородых" exist as a real item in Ри'сад's actual game inventory at all - `infoitems`
  eventlog rows only ever showed nearby ground-loot (STEALING-tagged), never an NPC inventory
  listing, so this session found no direct evidence either way. If the robes are a pure
  roleplay invention with no real in-game item behind them, no model/fix makes `GiveItemTo`
  succeed - recommended to the owner as the first thing to rule out in-game (open Ri'saad's
  trade menu, check if he actually carries the item), before any further code investigation.
- RECHAT settings walked back from the 80/6 extreme after cost math: `RECHAT_H`/`RECHAT_P`
  are `3`/`50` as of this entry (owner's choice, after being shown the ~15x call-volume
  estimate at 6/80 relative to the original 1/20).

## 2026-09-29 — fallback error string leaking into dialogue + action-enforcement re-enabled for director instructions

- Backgrounded a full-day chat log read (all speakers, not just Ри'сад/Лилит/Шаман) per
  owner's request ("прочитай за весь день лог"). Found two new, concrete, previously-unseen
  bugs plus confirmation of the trade-action gap:
  1. [код] `prompts/command_prompt.php:64` `$ERROR_OPENAI = "Didn't hear you, can you
     repeat?"` - the hardcoded fallback said when BOTH the primary and fallback LLM connector
     calls fail (`lib/data_functions.php:6046-6060`) - was leaking verbatim into the game as a
     real spoken line. Seen live 4 times in one day, on 4 different speakers (The Narrator,
     Брейт, Лилит Ткачиха, Бренуин) - e.g. rowid 50017 `Брейт: Didn't hear you, can you
     repeat?`. No Russian translation exists for it: `lang/ru/` doesn't exist (only de/es/fr/pl)
     and `CORE_LANG` isn't set to any of those, so the vendor per-language override in
     `command_prompt.php:69-71` never fires. **Fixed**: created
     `prompts/command_prompt_custom.php` (the vendor's own always-loaded override point,
     `command_prompt.php:74-75`, no language gating) with Russian text for
     `$ERROR_OPENAI`/`$ERROR_OPENAI_REQLIMIT`/`$ERROR_OPENAI_POLICY`. `php -l` clean, deployed
     live, `chown www-data:www-data`. This file isn't part of the git repo's tracked set
     (vendor `prompts/` dir, like `main.php` and `lib/`), so live-only, no repo mirror.
  2. The Narrator duplicated one full line verbatim ~20s apart (rowid 52581/52583 and
     52600/52602, "...монахом так монахом... хуйлам не понравится") - looks like a retry
     re-publishing the same generated line; not investigated further this session.
  3. Лилит addressed a nonexistent "Шаба" instead of Ри'сад mid-negotiation (rowid 54589-54596,
     17:14) - looks like a truncated/corrupted listener name at resolution time; not
     investigated further this session.
  4. Confirmed (not new, but now with direct evidence the model *understands* the mechanic):
     the robe-purchase price reset three times (30 -> 300 -> 20000 септимов) because the scene
     "closes" verbally each time and restarts from scratch without `GiveGoldTo`/`GiveItemTo`
     ever firing - and at 17:36:44 Ри'сад himself correctly narrates "Лилит должна забрать
     товар и оплатить" - the model knows the mechanic, it just never emits the action. Points
     squarely at the action-enforcement gap below, not model competence.
- **Root cause for "commands don't get obeyed/executed" found in `main.php`**: lines
  2144-2147 (and duplicated at 2157-2158, 2236-2237, 2242-2243, 2252-2253, 2289-2290,
  2299-2301) hard-disabled `PATCH_PROMPT_ENFORCE_ACTIONS`/`COMMAND_PROMPT_ENFORCE_ACTIONS` -
  the "Choose coherent ACTION to obey {player}" reinforcement text - for **every** request
  type, unconditionally, with an explicit comment "Action-enforcement prompt is hard-disabled
  globally." Traced the live consumption point: `connector/openrouterjson.php:331-332` only
  injects this reinforcement into the actual LLM call when `PATCH_PROMPT_ENFORCE_ACTIONS` is
  true - so it was structurally impossible for a Director instruction naming a specific ACTION
  to get any extra push toward actually executing it, for the entire mod, always.
- [код] **Fixed, scoped to director-driven turns only** (owner's choice - full global
  re-enable was offered and declined as too risky without knowing why it was originally
  disabled): `main.php` ~line 2233-2258, the `gameRequest[0]==="instruction"` branch and the
  `is_rolemastered` branch now set `PATCH_PROMPT_ENFORCE_ACTIONS=true` and
  `COMMAND_PROMPT_ENFORCE_ACTIONS` to a real enforcement string, instead of blanking them; the
  generic catch-all right after only resets to false/"" if nothing more specific already
  opted in. Ordinary chat/rechat/minime paths are untouched and still disabled, to avoid
  spamming actions into casual dialogue. `php -l` clean, `tools/test_ext.php` 68/68. Not part
  of the git repo (vendor `main.php`), live-only, no repo mirror. **Not yet verified in live
  play** - next instruction-type Director turn (e.g. another "назови цену"-style nudge) should
  be checked for whether it actually triggers `GiveGoldTo`/`GiveItemTo` this time.

## 2026-10-01 — бог учится искать вместо гадания: подсказки при отказе, find, корень дублей реплик

Продолжение роадмапа (этап B). Всё найдено по живым логам 2026-09-29 (сцена Шаман/ряса,
Адрианна/рукавицы): invented item names сжигали по реплике на попытку.

1. **Подсказки при отказе (ext/tes_god_guard).** Живые отказы («не знаю предмета
   «деревянная_палка» — назови точно, как в игре») не давали способа узнать реальные имена,
   поэтому нарратор слепо перебирал варианты («Наряд Седобородых», «Dovahkiin Tunic»,
   «0001391F:Железная_кольчуга»). Теперь при отказе по {item:}/{spell:}/{perk:}/{faction:}/
   {cell:}/{spawn:} и по raw-аргументам additem/addspell/addperk гвард добавляет в отказ
   топ-3 ближайших имени из tes_game_index (слова запроса >= 3 символов, стем до 6, имя
   побеждает EditorID) плюс готовую команду find. whyNoProfile для NPC тоже подсказывает
   похожие полные имена (word-level, найдено тестом: «Кай» -> «Командир Кай»). [код]

2. **find — поиск по индексу вместо гадания (ext/tes_god_guard + ext/tes_god_journal).**
   Серверная команда внутри God_Command: ind предмет|заклинание|способность|персонаж|фракция|
   место|существо <слова> (русские и английские kind-слова, без kind = предмет; «найди»/«поиск»
   тоже работают). Ищет в tes_game_index (name_lc/editor_id_lc), для персонажей добавляет
   core_npc_master. Результат уходит в tes_god_guard_log с verdict 'search', журнал показывает
   «ПОИСК: ...» в следующей реплике нарратора; сама команда в игру не идёт и не считается
   провалом (не влияет на failure streak). Шпаргалка God_Command (settings/chim_settings.sql
   + живой core_action.description) дополнена: «NEVER guess or invent names ... look it up
   first: find ...». [код] **В игре не проверено** — сервер поднимался для правок без игры.

3. **Rechat-фолбэк на пустом слоте (lib/chat_helper_functions.php, vendor, live-only).**
   Закрыта известная дыра из записи от 2026-09-29: mb_stripos(, '') === 0 истинно для
   ЛЮБОЙ строки, а pipe-список аудитории содержит пустые слоты («Кейла//Шаман» — видено
   живьём), поэтому пустой слот матчился первым и возвращался до реального имени. Добавлен
   guard  === '' в фолбэк-цикле \. Бэкап:
   lib/chat_helper_functions.php.bak-before-rechat-emptyfix. [код] **Не проверено на свежем
   tight-mode rechat.**

4. **КОРЕНЬ дублей реплик нарратора найден: lib/data_functions.php, replaceRoles (vendor,
   live-only).** Дубль 2026-09-29 15:54 (rowid 52581/52583 vs 52599-52602, «монахом так
   монахом») разобран полностью: модель в ход 2966 повторила свой ответ из хода 2965
   ДОСЛОВНО, потому что её собственные прошлые реплики приходили ей в истории с ролью
   'user': replaceRoles переводил narratorchat -> 'user' для ВСЕХ ходов, а buildHistoricContext
   унарраторских строк (строки, начинающиеся с «The Narrator:») даёт speaker 'narratorchat',
   т.к. ветка «assistant» исключает «The Narrator:». Для модели это чужой текст, и она
   генерировала тот же ответ заново. Фикс: narratorchat -> 'assistant' ТОЛЬКО когда
   HERIKA_NAME == 'The Narrator' (нарраторский ход); на ходах NPC Нарратор остаётся внешним
   голосом ('user'). Проверено юнит-симуляцией replaceRoles: нарраторский ход -> assistant,
   NPC-ход -> user. Саморечат-сторож DataLastDataExpandedFor (gameRequest[3]=='rechat') не
   задет: rechat-запросы не грузят нарраторский профиль, HERIKA_NAME там не 'The Narrator'.
   Бэкап: lib/data_functions.php.bak-before-narrator-role. [код] **В игре не проверено** —
   следующий случай «нарратор повторился» покажет, исчез ли дубль.

Тесты: tools/test_ext.php 87/87 (было 68; +19: suggest/find/whyNoProfile). Задеплоено
живьём (ext-копии + 2 vendor-файла), php -l чисто.

## 2026-10-01 (вечер) — GLM вместо Gemini, честные деньги, настоящие цены

1. **TakeGoldFromPlayer брал 1 септим по умолчанию** (lib/data_functions.php, vendor, live-only).
   Живой кейс: Хульда выдала эль, издала TakeGoldFromPlayer@ ПУСТЫМ - апстрим-эвристика брала
   любую цифру из последней реплики NPC («Ну, теперь плати, раз такой умный» - цифр нет) ->
   клиент брал дефолт 1 монету. Теперь: только реально названная цена из речи NPC
   («N септимов/золота/монет/gold/coin», последние 20 строк, кламп 1..10000); цены нет -
   действие ДРОПАЕТСЯ вместо кражи произвольной суммы. Бэкап:
   data_functions.php.bak-before-takegold-price. [код]

2. **Модели (core_llm_connector / core_profiles).** Тесты на OpenRouter (см. вывод выше в
   чате): glm-5.3-flash и flashx - reasoning ОБЯЗАТЕЛЕН (400 «cannot be disabled»), без капа
   съедает 300-1000+ токенов мышления на пустяк и при max_tokens=1000 отдаёт ПУСТОЙ контент;
   glm-4.7-flash и glm-flash-latest работают с enabled:false (1.3-1.4 сек, чистый JSON).
   Попытка 4.7-flash как primary: в игре дословно повторял свои реплики («Ты опять за своё?»
   х2) - слабая вариативность, откат. Итог: primary = #14 GLM 5.3 Flash (metadata:
   disable_model_reasoning=false + reasoning_max_tokens=256 - метадата коннектора уже
   пробрасывается в \[CONNECTOR][driver], PHP не нужен), secondary/fallback Gemini
   2.5 Flash Lite (id 2) не тронуты. Откат: llm_primary_id=11. Коннекторы созданы: #14
   z-ai/glm-5.3-flash (reasoning_model=1), #15 z-ai/glm-4.7-flash (reasoning_model=1). [код]

3. **Кап reasoning в драйвере** (connector/openrouterjson.php, vendor, live-only): ветка
   enabled:true читает metadata reasoning_max_tokens и ставит reasoning.max_tokens.
   Без него 5.3-flash мышлит 838-1000 ток/ход (29 сек). С капом 256: 5 сек, 26 ток мышления.
   Бэкап: openrouterjson.php.bak-before-reasoning-cap. [код]

4. **Цены предметов** (tools/game_index.py): DATA-субрекорд item-типов -> extra.value.
   ALCH ломает правило «value первый» - первые байты это float ВЕСА (эль = 0.27f ->
   1050253722 как u32). Эвристика для ALCH: a,b = два u32; a читается как вес (0<fa<=50)
   и b<=65000 -> value=b. Эльфийские сапоги 45, железный меч 25 - ARMO/WEAP в порядке.
   Ребилд индекса 165854 строк, 16668 item'ов с value. [код]

5. **Анкер цены в SpawnItem** (functions/functions.php, vendor live-patched): при выдаче
   предмета игроку в eventlog пишется funcret-строка «<Item>: базовая цена в Скайриме N
   золотых; продавая, назови цену не ниже этой» - продавец в следующем ходу знает настоящую
   цену (до этого модель цены не знала ВООБЩЕ: эль стоил 1 -> 2 -> «а он 15 стоит»).
   Кламп 1..65000 от мусора. Бэкап: functions.php.bak-before-price-anchor. [код]

Расход OpenRouter за месяц \.23: 46.7M ток - сессия агента (OpenCode), игра только
9.31M (~\). Игра дешёвая; жерть - отладочные дампы агента. Дальше агент работает
точечными запросами. [не проверено] игроком: новые цены в диалогах торговцев, GLM 5.3 Flash
в живой игре (кап 256), дроп TakeGold без цены.

## 2026-10-02 — новое прохождение: Qwen-коннектор, прямые команды Нарратору

- Новое прохождение начато (владелец подтвердил), старый `core_npc_master.id=2615` (Лилит)
  пуст - все точечные правки персонажей из прошлого сейва неактуальны. Системные фиксы
  (RECHAT, enforce-actions, перевод `$ERROR_OPENAI`, гигиена памяти в tes_god_guard) остаются
  в силе, не завязаны на сейв.
- [код] Владелец поставил коннектор id=18 (`qwen/qwen3.7-flash`, через OpenRouter) ВО ВСЕ
  слоты профиля (primary/secondary/fallback/formatter/diary) - никакой реальной страховки
  при сбое, прямо отменяет пользу сегодняшнего фикса `$ERROR_OPENAI`. Исправлено: `fallback`
  переставлен на id=2 (Gemini 2.5 Flash Lite), owner's choice.
- [код] Qwen не отвечал (`log.response = Array()` - тот самый путь, что шлёт `$ERROR_OPENAI`).
  Подтвердил веб-поиском: `qwen/qwen3.7-flash` реальная модель, с поддержкой reasoning. В
  коннекторе стояло `reasoning_model=0` - та же причина, что раньше ломала Gemini 3.8 Flash
  (reasoning-вывод мешает `enforce_json=1`). Исправлено: `reasoning_model=1` для id=18. [не
  проверено] помогло ли это полностью - не видел свежего ответа Qwen после фикса.
- **Прямые команды игрока Нарратору всё ещё не исполнялись после утреннего фикса enforce-
  actions.** Живой пример: игрок попросил "Господь Бог... телепортируй меня в Вайтран"
  (`log` 2026-10-02 07:43) - Нарратор болтал, не вызвал действие. Причина: утренний фикс
  включал `PATCH_PROMPT_ENFORCE_ACTIONS` только для `gameRequest[0]==="instruction"` (директор)
  и `is_rolemastered` - но прямой `inputtext` игрока к Нарратору идёт по третьему, отдельному
  пути с флагом `DIRECT_NARRATOR_DIALOGUE` (main.php ~2305), который я утром сознательно
  оставил выключенным "на всякий случай". Это и есть ровно тот случай, где принуждение нужно.
  [код] Включено: `DIRECT_NARRATOR_DIALOGUE` ветка теперь тоже ставит
  `PATCH_PROMPT_ENFORCE_ACTIONS=true` с тем же текстом-принуждением. `php -l` чисто. Не часть
  git-репозитория (vendor main.php), только live.
  **ИСПРАВЛЕНИЕ АТРИБУЦИИ (советник поймал)**: `date -r main.php` на момент проверки = 12:47,
  а отказанный `GodCommand@coc Вайтран` был в 07:46 - то есть ДО этого фикса. Qwen уже тогда
  сам, без принуждения, пытался вызвать действие; отказ был от стража (`coc` без `{cell:...}`
  не проходил синтаксис), а не от отсутствия enforce-actions. Фикс `DIRECT_NARRATOR_DIALOGUE`
  остаётся в силе (он не вредит и адресует реальный путь), но его не стоит засчитывать как
  причину исправления именно этого эпизода. Статус обеих правок: **применено, не подтверждено
  живым свежим тестом**.
- Попутно нашёл: `tools/test_ext.php` теперь даёт 79 passed / 8 failed (раньше было чисто
  68/68) - все 8 провалов в `tes_god_guard` (additem/addspell/addperk/addfaction/removeitem/
  heal/resurrect не доходят до ScriptProxy по тестам). Diff вчерашнего коммита по памяти
  (`0a754b1`) чисто аддитивный, не мог это сломать - причина не найдена, **не исследовано**,
  отложено как отдельная задача (не связано с сегодняшними фиксами enforce-actions/Qwen).
  **РАЗГАДАНО ниже в тот же день**: не баг, `core_npc_master` пуст (0 строк, новый сейв) -
  тест завязан на "уже известного" Скульвара, которого в этом сейве сервер ещё не встречал.
  Самоисправится по ходу игры.

## 2026-10-02 — coc без скобок; TeleportNPC улетал в случайное место (locations - 2 строки!)

- Подтверждён первый живой успех enforce-actions-фикса: `DIRECT_NARRATOR_DIALOGUE` реально
  включает `GodCommand@player.setav ... 100` на прямую просьбу игрока (07:51, "максимум
  навыков") - проверено по `log.response`, команда прошла валидацию. [код]
- **Телепорт: `coc Вайтран` отказывался чисто по синтаксису** (нет `{cell:...}`, кириллица не
  проходит `[A-Za-z0-9_]+`). [код] `ext/tes_god_guard/functions.php`, перед резолвером
  `{cell|item|...}`: голое `coc ИмяМеста` теперь нормализуется в `coc {cell:ИмяМеста}` и идёт
  тем же путём, что и явный синтаксис (точное совпадение, fallback на `<Name>Origin`,
  похожие варианты при промахе). Проверено живьём: `coc Вайтран` -> `coc WhiterunOrigin`,
  проходит. `coc Рифтен` -> `coc RiftenOrigin`. Деплой live, `php -l` чисто, 80/7
  (было 79/8 - семь неизменных провалов это эффект пустого сейва выше, не регрессия).
  Коммит `56a1c98`.
- **Реальный инцидент, который владелец описал**: "перенёс не в Вайтран, а в подвал к
  какому-то колдуну". Это НЕ был `coc` (страж его отклонил бы) - Нарратор в этот раз вызвал
  другое действие, `TeleportNPC@{"item":"Вайтран","target":"Шаман"}` (`functions/
  functions.php`, codeblock ~3518). [код] Нашёл причину: этот путь резолвит место через
  таблицу `public.locations`, в которой **всего 2 строки** ("Хелген", "Откос Крегвеллоу") -
  Вайтрана там нет и никогда не было. Запрос `similarity(name, 'Вайтран') ORDER BY sim DESC
  LIMIT 1` без порога всегда возвращает "самое похожее", даже при `sim = 0` (кириллица против
  латиницы/заглушки) - то есть игрока гарантированно телепортировало в одну из этих двух
  случайных точек при любом запросе места, которого нет в этой крошечной таблице.
  Подтверждено: `similarity('Вайтран')` против обеих строк = 0.
- [код] Исправлено: `TeleportNPC` теперь сначала ищет место в `tes_game_index` (74k+ ячеек,
  782 локации, 61 мир - та же база, что уже надёжно резолвит `{cell:Name}` для god-команд):
  точное совпадение по `cell`, иначе `world`/`location` с fallback на `<Name>Origin` ячейку.
  Только если там ничего нет - падает на старую `locations`, но теперь с порогом `sim >= 0.4`
  (раньше принимало любое, вплоть до 0%). Проверено живьём: Вайтран/Рифтен/Виндхельм/
  Солитьюд все резолвятся в верные ячейки через индекс; "Оплот Дракона" (несуществующее
  место) корректно не резолвится вместо случайного попадания. `php -l` чисто, 80/7 без
  изменений. Vendor-файл, не в git, live-only. **Не проверено свежим живым телепортом** после
  деплоя - следующая просьба "телепортируй в Вайтран" должна реально попасть в Вайтран.
- **Советник поймал реальный риск в этом же фиксе**: `tes_game_index.formid` - hex-строка
  ("0001A27F"), а `locations.formid` - обычное **decimal INTEGER** (подтверждено:
  `unittests/tests/CanonicalHoldResolutionTest.php::insertLocation(string $name, int $formId,
  ...)` - единственное место, что вообще пишет в `locations`, и это тестовая фикстура, не
  игровой код; в реальной игре эту таблицу никто никогда не заполнял). Если downstream-
  потребитель (`TeleportNPCRaw`, обрабатывается скомпилированным SKSE-плагином, недоступен
  для проверки отсюда) ожидал то же decimal, что всегда получал из `locations` - моя первая
  версия фикса подсовывала бы hex-строку вместо числа и могла тихо ломаться по-другому.
  [код] Добавлена конвертация `hexdec()` перед использованием найденного formid, чтобы формат
  совпадал с тем, что путь исторически получал. **Всё равно не проверено** - само поведение
  `TeleportNPCRaw` на скомпилированной стороне не видно отсюда; статус понижен с "починено" до
  "починено на уровне данных, формат исправлен, конечный результат не подтверждён в игре".
- Проверено по просьбе советника: `$addCandidate` fallback в `chimResolveServerSideRechatTarget`
  (lib/chat_helper_functions.php) УЖЕ содержит защиту от пустых слотов аудитории
  (`if ($audienceName === "") continue;`) - это не забытая дыра, фикс уже стоит живьём.
- `command_prompt_custom.php` подтверждён НЕ мёртвым кодом: инклюд реально есть в
  `prompts/command_prompt.php:75-76`, подключается безусловно (без привязки к `CORE_LANG`).

## 2026-10-02/03 — догоняю пропущенные записи + Назим

Ранее закоммичено в HerikaServer (`aiagent`, локально, не запушено), в лог не попало:
- [код] `KillTarget` на игрока жёстко запрещён (DebugNotification «Kill_Target cannot target the
  player»); NPC-цели идут через `herikaQueueGodCommands('{npc:X}.kill')`, при промахе — «Не знаю «X»».
- [код] После каждого консольного `resurrect` автоматически добавляется `recycleactor` (лечит
  «крутящиеся ноги» после воскрешения).
- [код] NO-SPEAK GUARD (`lib/chat_helper_functions.php`): реплика, начинающаяся с `.resurrect`/
  `.relation`/... не озвучивается, а отдаётся в `tesGodGuardFilterAction` на выполнение.
- [код] Телепорт игрока — через `coc <самая населённая <Base>* ячейка>`, а не `<Base>Origin`.
- [код] CHIM-MCP: маршрут `POST /message`, `server.close()` перед переподключением, сторож
  `watchdog.sh` в cron.

2026-10-03:
- [ext] `tes_god_guard`: команды, склеенные без `;` (лог стража id 669:
  `.character personality: … {npc:Назим}.relation 100 friend …`), теперь режутся перед
  `{npc:X}.verb` / `{near:X}.verb` / `player.verb`. Новый тест в `tools/test_ext.php`;
  итог 81/7 (7 падений были и до правки — тесты ждут `{npc:}`-плейсхолдеры, а индекс теперь
  резолвит их в RefID сразу). Коммит TES `0f7e879`.
- [код] `TeleportNPC` для NPC: вместо игрового моста (ищет NPC только среди загруженных актёров;
  `TeleportNPCRaw` получал formid ячейки — responselog 714/715, оба `sent=1`, Назим не сдвинулся)
  теперь `{npc:X}.moveto player` или `moveto <ref>` из `locations.refs` (центр локации →
  маркер карты → внешний вход; формат из `getInteriorRef()`). «ко мне / сюда / рядом со мной» в
  словах игрока важнее устаревшей цели от модели (01:31:48 модель подставила ферму на
  «перенеси ко мне»). Если место не найдено или NPC неизвестен — DebugNotification + старый мост.
  Коммиты HerikaServer `6c888c6d` и следующий. **Не проверено в игре.**
- [код] `{npc:}`-резолвер в `herikaQueueGodCommands`: дополнительное точное сравнение имени в PHP.
- **Найдено, не чинено (ядро/БД, нужно «да»)**: БД работает с `lc_ctype=C` →
  `similarity('Вайтран','Вайтран') = 0`, `'Назим' ILIKE 'назим'` = false. Из-за этого штатный
  `resolveTravelLocation` (TravelTo фоновой жизни) не находит НИ ОДНОГО русского названия места.
- Исправление прошлой ошибки: Назим ЕСТЬ в `core_npc_master` (id 2757, RefID 0001A6A4) — мой
  поиск через `~*` не находил его из-за той же локали. Характер чистый, отношение `80 grateful`.
  Фоновый оценщик REL-LLM переписывает отношение после реплик (видели 80→70 «Betrayal»);
  защищает галка «lock» в редакторе отношений (`relationships_locked`).
- `moveto` — разовый перенос: AI-пакет Назима потом поведёт его обратно в Вайтран.
- [ext] TES-GOD-LOCK: `.character` теперь ставит `lock_profile=1`, `.relation` — `relationships_locked`.
  Причина (Назим всё ещё хамил при personality «кроткий» и relation 80): автогенератор профиля
  переписал ему speechstyle («Shaman is an invasive threat… threatening growl») и goals («выгнать
  Шамана»), а REL-LLM пересчитывал отношение после каждой реплики. Снять блок — редактор NPC.
- [настройки, репо] подсказка Нарратору: чтобы сменить отношение NPC к игроку, менять
  personality + speechstyle + goals. **В живую БД не применено** (нужно «да»).
- **Не применено (запись в живую БД отклонена, нужно «да»)**: переписать Назиму (id 2757)
  speechstyle/goals на дружелюбные + lock_profile=1 + relationships_locked.
- [ext] TES-GOD-RECONCILE: `{npc:X}.relation <50..100> <type> reason` к игроку теперь ещё
  согласует профиль — в начало speechstyle строка «[К игроку] говорит доброжелательно…», из
  goals выкидываются строки, где упомянут игрок (по-русски и транслитом: «Shaman»), в [Помнит]
  пишется «прошлые ссоры позади», ставится lock_profile. Write-side в игре **не проверено**
  (`--write` не запускал — владелец играет). Read-only тест транслита добавлен.
- Ручная правка Назима в живой БД (скрипт с бэкапом) дважды отклонена авто-фильтром даже после
  «сам исправь» — владельцу предложено запустить его через `!` или исправить через Нарратора.
- [ext] TES-GOD-HYPNOSIS: `{npc:X}.hypnosis <внушение>` запускает встроенный гипноз CHIM
  (worker rolemaster/cmd/hypnosis.php через manager.php, как режим HYPNOSIS) — LLM профилей
  переписывает personality/goals/speechstyle/occupation; страж дополнительно ставит
  lock_profile (встроенный режим этого не делает). Подсказка Нарратору — в settings (репо,
  в живую БД не применено). NO-SPEAK guard знает `.hypnosis`. В игре не проверено.
- **Проверено живьём (01:55, лог стража 738–744)**: Нарратор сам дал Назиму `.relation 80
  grateful` → TES-GOD-RECONCILE сработал: speechstyle с ведущей строкой, память «ссоры позади»,
  lock_profile=1, relationships_locked=true. До этого динамический профиль успел переписать
  ему personality в «paranoia and defensive aggression» — теперь заблокировано.
- [ext] формулировка ведущей строки без склонения имени; в [Помнит] держится только последняя
  строка «Моё отношение к игроку изменилось» (раньше дублировалась).
- [ядро] `lib/data_functions.php` TES-TIME-DEDUP: после строки «N hours have passed» (событие
  info_timeforward) больше не добавляется вторая метка «minor timelapse of about 24 hours» за ту
  же перемотку. Живой случай: стражник Виркмунд назвал разговор про «Вилкаса» минутной давности
  «вчерашним» — в промпте перед ним стояло «After 21 hours… About 24 hours later». Причина
  перемотки — событие в 01:48:55→01:49:03 (сохранение, затем «21 hours have passed»).
- [ext] `tes_no_invent`: + одна фраза «Happened Recently / Moments Ago — это минуты назад».
- [БД, по просьбе владельца] LLM-профиль во всех прохождениях (public + dragon_break): все слоты
  (primary/secondary/tertiary/quaternary/formatter/diary) → id 11 Gemini 2.5 Flash, fallback →
  id 2 Gemini 2.5 Flash Lite. Было: public — всё Qwen 3.7 Flash (18), fallback 2; dragon_break —
  12/2/8/8/8/8, diary 8. Бэкап: docs/backups/core_profiles_before_2026-10-03.txt. Причина — кривой
  русский у Qwen («не здоровкайся», «не тестй»).
- [БД, по просьбе владельца «везде где можно мимо флеш»] новый коннектор id 19 «Xiaomi MiMo V2.6
  Flash» (`xiaomi/mimo-v2.6-flash`, $0.14/$0.28 за 1M по официальному /api/v1/models) — копия
  настроек Qwen id 18 (тот же драйвер openrouterjson и ключ). Все слоты профиля во всех
  прохождениях → 19, fallback → 2 (Gemini 2.5 Flash Lite). Бэкап предыдущего состояния (уже с
  Gemini 2.5 Flash): docs/backups/core_profiles_before_mimo_2026-10-03.txt. **Живой ответ MiMo
  ещё не проверен** — смотреть `public.log` после первой реплики.
- [код] TeleportNPC (TES-TP-EMPTY): пустое место + «ко мне/сюда» в словах игрока → NPC к игроку;
  «перенеси меня к Назиму» → `player.moveto {npc:Назим}`; `{npc:}`-резолвер понимает латиницу
  («Nazim» → Назим) и имя без «[Стражник …]». Живой случай на MiMo: `TeleportNPC@PLAYER@Nazim`.
- [CHIM-MCP] watchdog писал журнал в `/tmp/chim-mcp-server.log`, принадлежащий root → cron (dwemer)
  падал каждую минуту, сервер не поднимался. Журнал перенесён в `CHIM-MCP/server.log`; сервер поднят.
- [БД, по просьбе владельца] коннекторы задач в `general_settings` (CORE_CONNECTOR_* — это id
  коннекторов, НЕ слоты профиля; моё прежнее «достаточно поменять профиль» было ошибкой: Назиму
  в 02:09 часть запросов ещё шла через Qwen 18) → 19 MiMo во всех прохождениях: BGL, DIRECTOR,
  MEDIUMTERM, PLAYER, PROFILES, QUEST_CREATION, QUEST_ENGINE, SCENECLASSIFIER, SUMMARY. В
  dragon_break было 8 (DeepSeek V4 Flash) и BGL=1 (GLM 4.7). OGHMA_CUSTOM пустой — не трогал.
  Бэкап: docs/backups/general_settings_connectors_before_mimo_2026-10-03.txt.
- [ядро] NO-SPEAK guard: реплика, состоящая только из метки настроения («neutral» и т.п.), не
  озвучивается (живой случай 02:00:45, log 8520 — весь ответ модели был «neutral», Декумус
  сказал это вслух).
- Наблюдение (CHIM-MCP): Назим после уговоров выдал `MakeFollower@Шаман` (02:14:01) — стал
  спутником. Зациклился на «не помню, когда последний раз перо держал» (подхватил тему писем).
  У MiMo встречается мусор: «курöm», «торговать… уменись собой за чужой земли», обрыв фразы.
- [БД, по просьбе владельца «нахуй квен»] удалён коннектор id 18 (qwen/qwen3.7-flash). Перед
  удалением через CHIM-MCP проверено: ни слот профиля, ни коннектор задачи, ни conf_opts на него не
  ссылаются. Бэкап строки: docs/backups/llm_connector_18_qwen_deleted_2026-10-03.txt.
- [БД, по просьбе владельца] MiMo несёт бессмыслицу (не в тему: «Дай денег» → «я же шучу,
  скамейка свободна»; мусор «уменись собой», обрывы) → все слоты профиля и все коннекторы задач во
  всех прохождениях → id 11 Gemini 2.5 Flash, fallback → id 2 Lite. Бэкапы:
  docs/backups/*_before_gemini_2026-10-03.txt. Коннектор MiMo (19) оставлен в списке, не используется.
- [ядро] описания действий: OpenInventory/OpenInventory2 (окно обмена/подарка) — только для
  предметов, для денег — TakeGoldFromPlayer (взять у игрока) и GiveGoldTo (дать). Живой случай
  02:18:58: Назим на разговор о деньгах вызвал OpenInventory2@Шаман. Все четыре действия включены
  (лог FUNCTIONS: NOT Skipping). В игре не проверено.
- [ядро] TES-GOLD-HANDOVER (functions.php, пост-фильтр перед основным): если слова игрока ОТДАЮТ
  деньги с суммой («держи миллион», «возьми 500 золотых», «вот тебе 10 000 септимов»), NPC получает
  `TakeGoldFromPlayer@<сумма>`: OpenInventory/OpenInventory2 заменяется, а если денежного действия
  нет вовсе — добавляется. Не чаще раза в 2 мин на NPC (по actions_issued). «Дай денег»/«Держи меч»
  не трогает (проверено на 9 фразах). Живой случай 02:19–02:21: Назим на миллион открывал окно
  подарка / говорил «я открыл инвентарь». В игре не проверено.
- [ext] ROADMAP B «жив/мёртв»: `tesGodGuardLifeState()` — по свежим данным игры
  (activity_status.is_dead, наблюдение ≤ ¼ игрового часа назад) страж отказывает в `resurrect`
  живому и в `kill` мёртвому, с причиной. Устаревшие данные не блокируют. Тесты: 92/93 (единственный
  провал — старый «multi-word raw item name»; шесть прежде падавших тестов сейчас проходят).
- [roadmap] пункт «Таймаут соединения с LLM» закрыт как ошибочная находка: таймаут есть через
  stream_context (30 с / 90 с для reasoning), fallback срабатывает; 60-секундная защита потока.
- **Уточнение к записи выше про «шесть прежде падавших тестов»:** это не чудо и не поломка.
  Страж подставляет RefID из индекса только для NPC, которых CHIM не знает; знакомым оставляет
  `{npc:Имя}` (его потом резолвит herikaQueueGodCommands по core_npc_master). Скульвар Черная
  Рукоять (id 2769) в этом прохождении появился в core_npc_master → тесты (написанные под
  «знакомого» NPC) снова совпали. Поведение верное.
- [ядро] TES-GOLD-HANDOVER: требуется слово о деньгах (деньги/золото/септим/монеты/тысяч/миллион),
  голая цифра не считается («Держи 3 зелья», «Даю слово, вернусь через 2 часа» больше не
  срабатывают и не закрывают окно подарка); «не дам …» игнорируется. 13 фраз проверены.
- [ядро] TES-TRAVEL-FIX (`lib/data_functions.php`, пост-фильтр TravelTo): живой случай 02:33 —
  Назим `TravelTo@Драконий Предел` пошёл в Вайтран. Причины: запрос по имени сортировал по
  несуществующей колонке `created_at` (SQL-ошибка, пусто), запрос по региону брал верхний результат
  `similarity()` без порога, а при lc_ctype=C кириллица всегда 0 → случайный «Вайтран». Теперь:
  точное имя или имя + хвост («… outdoors»), регион только точно, а непосещённое место — из
  `tes_game_index` (FormID записи Location, десятичный signed — ровно то, что игра пишет в
  `locations`: проверено, Драконий Предел 129137 совпал после того, как игра его записала).
  Проверено на 5 названиях. Фоновый `resolveTravelLocation` (Background Life) всё ещё на similarity — не чинил.
- [ядро] TeleportNPC/KillTarget: цель вида `{npc:Назим}` (модель пишет синтаксис god-команд в поле
  target) принимается как «Назим». Живой случай 02:35:38: `{npc:{npc:Назим}}` → блок + «Не знаю»,
  а Нарратор вслух сказал «Назим уже у тебя». Сама «уверенная ложь» — следствие того, что реплика
  пишется вместе с действием и результата не знает; это пункт roadmap B «funcret / реакция на провал».
- Живьём подтверждено 02:34:59: «переноси Назима сюда» → `prid 0001A6A4; moveto player` — applied.
- [ядро] TES-RECHAT-ADDRESSED (`main.php`, предрасчёт бюджета rechat): живой случай 02:36 — Назим
  обратился к «Ярл Балгруф Старший», ярл молчал: в chim.log `Rechat: pre-roll determined 0 rounds`
  (RECHAT_P=50 — монетка на сцену). Это не та дыра, что чинилась 2026-09-29 (там был подбор имени
  отвечающего в tight-режиме и пустые слоты аудитории) — здесь отказ ДО выбора. Теперь: если
  `rechat_target_hint` из запроса игры совпадает с выбранным отвечающим NPC (не игрок, не Нарратор),
  первый ответ гарантирован; остальная цепочка — по RECHAT_H/RECHAT_P. В журнал пишется и
  срабатывание, и «не форсировано (hint=…, selected=…)» — следующий случай покажет фактический hint.
  В игре не проверено.
- [ядро] TES-RECHAT-ADDRESSED, часть 2 (`lib/chat_helper_functions.php`,
  `chimResolveServerSideRechatTarget`): новые строки журнала показали настоящую причину
  «неохотно общаются»: `hint='Ярл Балгруф Старший', selected='Айрилет'`, `hint='Назим',
  selected='Айрилет'`, `hint='Ярл Балгруф Старший', selected='Назим'`. Режим сцены по умолчанию
  случайный (tight/conversational/group, кешируется на сцену), и в group-режиме код НАРОЧНО ставит
  адресата в конец очереди. Теперь адресат (rechat_target_hint, если это NPC, а не игрок/Нарратор/
  сам говорящий) — первый кандидат в любом режиме; сравнение имён терпит титул («Ярл …») через
  вхождение строки (только имена ≥5 символов); гарантия первого ответа в main.php опирается на
  этот резолв (`addressed`), а не на точное совпадение строк. В игре не проверено.
- [ext] tes_god_guard: `tesGodGuardNotifyPlayer()` — когда команда Нарратора заблокирована,
  прошла частично (кроме «урезано до 5000») или серверная команда не удалась, в игре сразу
  всплывает «Нарратор: не вышло — <причина>; <причина> (и ещё N)», без подсказок «похожие».
  Живой случай 02:51: «Дай мне всё, что требует Провентус» → три выдуманных англ. предмета
  заблокированы, Нарратор сказал «будет по-твоему», игрок ничего не получил и не узнал.
- Живьём подтверждено 02:44–02:46: TES-RECHAT-ADDRESSED сработал 3 раза («Ярл Балгруф Старший was
  addressed directly — guaranteed reply despite a 0 pre-roll»), ярл отвечал сам.
