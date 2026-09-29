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
