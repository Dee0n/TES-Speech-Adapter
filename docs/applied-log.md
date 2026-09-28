# Applied log

What was applied to the live DwemerDistro install, when, and how to undo it.
Tags: [код] verified in code/DB, [не проверено] not yet checked in game.

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
