# Applied log

What was applied to the live DwemerDistro install, when, and how to undo it.
Tags: [код] verified in code/DB, [не проверено] not yet checked in game.

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
