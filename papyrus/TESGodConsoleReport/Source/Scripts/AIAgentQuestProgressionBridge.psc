Scriptname AIAgentQuestProgressionBridge Hidden
{Static bridge used by the CHIM SKSE plugin to apply server-approved quest actions.}

Function SetQuestStage(int questFormId, int stage) Global
    Quest targetQuest = Game.GetForm(questFormId) as Quest
    if targetQuest
        targetQuest.SetStage(stage)
    endif
EndFunction

Function SetQuestObjectiveCompleted(int questFormId, int objectiveIndex, bool completed = true) Global
    Quest targetQuest = Game.GetForm(questFormId) as Quest
    if targetQuest
        targetQuest.SetObjectiveCompleted(objectiveIndex, completed)
    endif
EndFunction

Function SetQuestObjectiveDisplayed(int questFormId, int objectiveIndex, bool displayed = true, bool forceDisplayed = false) Global
    Quest targetQuest = Game.GetForm(questFormId) as Quest
    if targetQuest
        targetQuest.SetObjectiveDisplayed(objectiveIndex, displayed, forceDisplayed)
    endif
EndFunction

Function SetQuestStageObjective(int questFormId, int stage, int objectiveIndex) Global
    Quest targetQuest = Game.GetForm(questFormId) as Quest
    if targetQuest
        targetQuest.SetStage(stage)
        Utility.Wait(0.25)
        targetQuest.SetObjectiveDisplayed(objectiveIndex, true, true)
    endif
EndFunction

Function FailAllQuestObjectives(int questFormId) Global
    Quest targetQuest = Game.GetForm(questFormId) as Quest
    if targetQuest
        targetQuest.FailAllObjectives()
    endif
EndFunction

Function StartQuest(int questFormId) Global
    Quest targetQuest = Game.GetForm(questFormId) as Quest
    if targetQuest
        targetQuest.Start()
    endif
EndFunction

Function StartQuestStageObjective(int questFormId, int stage, int objectiveIndex) Global
    Quest targetQuest = Game.GetForm(questFormId) as Quest
    if targetQuest
        if !targetQuest.IsRunning()
            targetQuest.Start()
            Utility.Wait(0.25)
        endif
        targetQuest.SetStage(stage)
        Utility.Wait(0.25)
        targetQuest.SetObjectiveDisplayed(objectiveIndex, true, true)
    endif
EndFunction

Function ExecuteConsoleCommand(String command) Global
    if command != ""
        TESRunAndReport(command)
    endif
EndFunction

Function ExecuteConsoleCommandSequence(String commands) Global
    int splitIndex = StringUtil.Find(commands, "||")
    while splitIndex >= 0
        String command = StringUtil.Substring(commands, 0, splitIndex)
        if command != ""
            TESRunAndReport(command)
            Utility.Wait(0.25)
        endif
        commands = StringUtil.Substring(commands, splitIndex + 2)
        splitIndex = StringUtil.Find(commands, "||")
    endwhile

    if commands != ""
        TESRunAndReport(commands)
    endif
EndFunction

; TES-Speech-Adapter: run one console command and send its real console output to the
; server (ext/tes_god_console), so the Narrator learns whether it worked. A marker line
; is printed first: if it is still the last console line, the command printed nothing.
Function TESRunAndReport(String command) Global
    if StringUtil.Find(command, "tesnear ") == 0
        TESSelectNearby(StringUtil.Substring(command, 8))
        return
    endif
    String marker = "[tes] " + command
    ConsoleUtil.PrintMessage(marker)
    ConsoleUtil.ExecuteCommand(command)
    String output = ConsoleUtil.ReadMessage()
    if output == marker
        output = ""
    endif
    AIAgentFunctions.logMessage(command + "@@" + output, "tes_god_console")
EndFunction

Function StopQuest(int questFormId) Global
    Quest targetQuest = Game.GetForm(questFormId) as Quest
    if targetQuest
        targetQuest.Stop()
    endif
EndFunction

Function StartScene(int sceneFormId) Global
    Scene targetScene = Game.GetForm(sceneFormId) as Scene
    if targetScene
        targetScene.Start()
    endif
EndFunction

Function SetActorValue(int actorFormId, string actorValue, float value) Global
    Actor targetActor = Game.GetForm(actorFormId) as Actor
    if targetActor
        targetActor.SetActorValue(actorValue, value)
    endif
EndFunction

Function SetActorGhost(int actorFormId, bool ghost) Global
    Actor targetActor = Game.GetForm(actorFormId) as Actor
    if targetActor
        targetActor.SetGhost(ghost)
    endif
EndFunction

Function EvaluateActorPackage(int actorFormId) Global
    Actor targetActor = Game.GetForm(actorFormId) as Actor
    if targetActor
        targetActor.EvaluatePackage()
    endif
EndFunction

Function RemoveItemFromPlayer(int itemFormId, int count = 1, bool silent = false) Global
    Form targetItem = Game.GetForm(itemFormId)
    Actor player = Game.GetPlayer()
    if targetItem && player
        player.RemoveItem(targetItem, count, silent)
    endif
EndFunction

Function AddItemToPlayer(int itemFormId, int count = 1, bool silent = false) Global
    Form targetItem = Game.GetForm(itemFormId)
    Actor player = Game.GetPlayer()
    if targetItem && player
        player.AddItem(targetItem, count, silent)
    endif
EndFunction

Function EnableReference(int refFormId, bool fadeIn = false) Global
    ObjectReference targetRef = Game.GetForm(refFormId) as ObjectReference
    if targetRef
        targetRef.Enable(fadeIn)
    endif
EndFunction

Function SetActorRelationshipToPlayer(int actorFormId, int rank) Global
    Actor targetActor = Game.GetForm(actorFormId) as Actor
    Actor player = Game.GetPlayer()
    if targetActor && player
        targetActor.SetRelationshipRank(player, rank)
    endif
EndFunction

; TES-Speech-Adapter: "tesnear <Display Name>" selects the nearby actor with that name
; (dead ones too, so resurrect works) as the console reference for the following
; commands of the same sequence. Reports "selected" or what it saw instead.
Function TESSelectNearby(String actorName) Global
    Actor player = Game.GetPlayer()
    Actor[] actors = MiscUtil.ScanCellNPCs(player, 4096.0, None, false)
    String seen = ""
    int i = 0
    while i < actors.Length
        Actor candidate = actors[i]
        if candidate && candidate != player
            String candidateName = candidate.GetDisplayName()
            if candidateName == actorName
                ConsoleUtil.SetSelectedReference(candidate)
                AIAgentFunctions.logMessage("tesnear " + actorName + "@@selected", "tes_god_console")
                return
            endif
            if i < 8
                seen = seen + candidateName + "; "
            endif
        endif
        i += 1
    endwhile
    ConsoleUtil.SetSelectedReference(None)
    AIAgentFunctions.logMessage("tesnear " + actorName + "@@not found nearby, seen: " + seen, "tes_god_console")
EndFunction