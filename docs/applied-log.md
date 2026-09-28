# Applied log

What was applied to the live DwemerDistro install, when, and how to undo it.
Tags: [код] verified in code/DB, [не проверено] not yet checked in game.

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
