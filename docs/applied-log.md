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
