# TESGodConsoleReport (MO2 mod)

Override of CHIM's `AIAgentQuestProgressionBridge` (AIAgent mod). Identical to the
shipped script except `ExecuteConsoleCommand` / `ExecuteConsoleCommandSequence`: after
every `ConsoleUtil.ExecuteCommand` it reads the last console line
(`ConsoleUtil.ReadMessage`) and sends `"<command>@@<output>"` with
`AIAgentFunctions.logMessage(..., "tes_god_console")`. The server plugin
`ext/tes_god_console` stores it; `ext/tes_god_journal` shows the Narrator
"НЕ вышло, консоль ответила: …" or "выполнено игрой".

`ReadMessage` is read both before and after `ExecuteCommand`; unchanged means the command
printed nothing (reported as empty output).

**Serialized with a spinlock.** The AIAgent plugin dispatches several pending outbox rows
without waiting for each other, so `ExecuteConsoleCommand(Sequence)` calls from different
rows were running concurrently. `ConsoleUtil`'s selected reference and last message are one
shared, global, native state, so two rows racing on it produced two real bugs, both seen in
`tes_god_console_log`: one row's reported output was literally another, later row's own
command text (2026-09-28 16:10-16:12); and, worse, a `prid` from one row could overwrite the
NPC selected by another row's still-running sequence, so a command meant for NPC A landed on
NPC B (2026-09-28 17:54, confirmed by two NPCs' `prid` calls interleaving seven times in
under 0.1 s - impossible if rows ran one at a time with their own `Utility.Wait(0.25)`
between steps). `TESLockAcquire`/`TESLockRelease` (a `StorageUtil.AdjustIntValue` spinlock on
the player, since that call is a single native op) now wrap the whole body of both entry
functions, so only one row ever touches `ConsoleUtil` at a time.
(An earlier, WRONG theory here blamed a `[tes] <command>` marker for not reaching
`ReadMessage` - the marker does reach it, as the same 16:10-16:12 log rows show; the real
bug was the missing lock, not the marker.)

- Built from `MO2/mods/AIAgent/Source/Scripts/AIAgentQuestProgressionBridge.psc`
  (checked 2026-09-28: rebuilding that source gives the shipped .pex, only the
  author/machine strings differ).
- Install: copy this folder (without README/Source if you like) to
  `MO2/mods/TES God Console Report`, enable it and put it BELOW AIAgent in the left
  pane (wins the file conflict).
- After a CHIM/AIAgent update: diff the new bridge source against
  `Source/Scripts/AIAgentQuestProgressionBridge.psc`, re-apply the two functions and
  recompile (see memory/papyrus toolchain command), or disable this mod.
- Compile: `PapyrusCompiler.exe AIAgentQuestProgressionBridge.psc -f=TESV_Papyrus_Flags.flg
  -i="<this Source\Scripts>;<AIAgent Source\Scripts>;<ConsoleUtilSSE NG src>;<SKSE src>;<Data\Scripts\Source>"`
