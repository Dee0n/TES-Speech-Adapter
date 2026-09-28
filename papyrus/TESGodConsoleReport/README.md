# TESGodConsoleReport (MO2 mod)

Override of CHIM's `AIAgentQuestProgressionBridge` (AIAgent mod). Identical to the
shipped script except `ExecuteConsoleCommand` / `ExecuteConsoleCommandSequence`: after
every `ConsoleUtil.ExecuteCommand` it reads the last console line
(`ConsoleUtil.ReadMessage`) and sends `"<command>@@<output>"` with
`AIAgentFunctions.logMessage(..., "tes_god_console")`. The server plugin
`ext/tes_god_console` stores it; `ext/tes_god_journal` shows the Narrator
"НЕ вышло, консоль ответила: …" or "выполнено игрой".

`ReadMessage` is read both before and after `ExecuteCommand`; unchanged means the command
printed nothing (reported as empty output). A `[tes] <command>` marker printed via
`PrintMessage` before the command was tried first (2026-09-28) but doesn't work: whatever
`ReadMessage` reads back is apparently not updated by `PrintMessage`, so a real error from
one command (an invalid actor value, once) kept bleeding into every later command's
reported output as if it were theirs, until the before/after check replaced it.

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
