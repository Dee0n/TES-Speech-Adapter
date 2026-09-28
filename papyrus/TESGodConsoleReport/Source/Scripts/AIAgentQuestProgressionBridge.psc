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
    ; TES-Speech-Adapter: stop the sequence when a target selection fails - otherwise the
    ; next commands hit whatever the console had selected before (a stray disable once did).
    int splitIndex = StringUtil.Find(commands, "||")
    while splitIndex >= 0
        String command = StringUtil.Substring(commands, 0, splitIndex)
        if command != ""
            if !TESRunAndReport(command)
                AIAgentFunctions.logMessage(StringUtil.Substring(commands, splitIndex + 2) + "@@error: aborted, target not found", "tes_god_console")
                return
            endif
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
; server (ext/tes_god_console), so the Narrator learns whether it worked. Reads the console
; before and after: unchanged means the command printed nothing (a genuine console error,
; not one left over from an earlier command in the sequence).
; A "[tes] <command>" marker (PrintMessage before Execute) was tried first but did not work:
; whatever ConsoleUtil.ReadMessage() reads back is apparently not updated by PrintMessage,
; so a marker never got overwritten and a real error from one command (e.g. an invalid
; actor value) kept bleeding into every later command's reported output as if it were theirs.
bool Function TESRunAndReport(String command) Global
    if StringUtil.Find(command, "tesnear ") == 0
        return TESSelectNearby(StringUtil.Substring(command, 8))
    endif
    if command == "tesrussify"
        TESRussifyNames()
        return true
    endif
    if StringUtil.Find(command, "tesdress ") == 0
        TESDress(StringUtil.Substring(command, 9))
        return true
    endif
    if StringUtil.Find(command, "tesroutine ") == 0
        TESRoutine(StringUtil.Substring(command, 11))
        return true
    endif
    if StringUtil.Find(command, "tesoutfit ") == 0
        TESOutfit(StringUtil.Substring(command, 10))
        return true
    endif
    if command == "tesremove"
        TESRemoveSelected()
        return true
    endif
    if StringUtil.Find(command, "tesgive ") == 0
        TESGive(StringUtil.Substring(command, 8))
        return true
    endif
    String before = ConsoleUtil.ReadMessage()
    ConsoleUtil.ExecuteCommand(command)
    String output = ConsoleUtil.ReadMessage()
    if output == before
        output = ""
    endif
    AIAgentFunctions.logMessage(command + "@@" + output, "tes_god_console")
    if StringUtil.Find(command, "prid ") == 0 && StringUtil.Find(output, "not found") >= 0
        ConsoleUtil.SetSelectedReference(None)
        return false
    endif
    return true
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

; TES-Speech-Adapter: "tesnear <Display Name>" selects the NEAREST actor with that name
; (dead ones too, so resurrect works; nearest, so a generic name like "Horse" means the one next to the
; player) as the console reference for the following commands of the same sequence.
; Reports "selected" or which actors it saw instead.
bool Function TESSelectNearby(String actorName) Global
    Actor player = Game.GetPlayer()
    Actor[] actors = MiscUtil.ScanCellNPCs(player, 4096.0, None, false)
    Actor best = None
    float bestDistance = 0.0
    String seen = ""
    int i = 0
    while i < actors.Length
        Actor candidate = actors[i]
        if candidate && candidate != player
            if candidate.GetDisplayName() == actorName
                float distance = candidate.GetDistance(player)
                if !best || distance < bestDistance
                    best = candidate
                    bestDistance = distance
                endif
            elseif i < 8
                seen = seen + candidate.GetDisplayName() + "; "
            endif
        endif
        i += 1
    endwhile
    if best
        ConsoleUtil.SetSelectedReference(best)
        AIAgentFunctions.logMessage("tesnear " + actorName + "@@selected", "tes_god_console")
        return true
    else
        ConsoleUtil.SetSelectedReference(None)
        AIAgentFunctions.logMessage("tesnear " + actorName + "@@not found nearby, seen: " + seen, "tes_god_console")
    endif
    return false
EndFunction

; TES-Speech-Adapter: "tesgive all|around|house" - the selected console reference
; (set by a preceding "tesnear <Giver>") gives the player what it owns:
;   all    - everything the giver carries (worn too), owned by the player afterwards;
;   around - objects within 1500 units of the player owned by the giver or its factions
;            (chests, furniture, items); locked ones are unlocked;
;   house  - the player's current interior cell, if the giver or its faction owns it:
;            the cell and everything in it that the giver owned; locked doors and
;            containers inside are unlocked.
; Reports how many references changed owner.
Function TESGive(String mode) Global
    Actor player = Game.GetPlayer()
    ActorBase playerBase = player.GetActorBase()
    Actor giver = ConsoleUtil.GetSelectedReference() as Actor
    if !giver
        AIAgentFunctions.logMessage("tesgive " + mode + "@@error: the giver was not found nearby", "tes_god_console")
        return
    endif
    if mode == "all"
        giver.RemoveAllItems(player, false, false)
        AIAgentFunctions.logMessage("tesgive all@@" + giver.GetDisplayName() + " gave everything carried to the player", "tes_god_console")
        return
    endif
    Cell here = player.GetParentCell()
    bool house = mode == "house"
    int changed = 0
    if house
        if !here.IsInterior()
            AIAgentFunctions.logMessage("tesgive house@@error: the player is not inside a house", "tes_god_console")
            return
        endif
        if !TESOwnedBy(here.GetActorOwner(), here.GetFactionOwner(), giver)
            AIAgentFunctions.logMessage("tesgive house@@error: this place does not belong to " + giver.GetDisplayName(), "tes_god_console")
            return
        endif
        here.SetActorOwner(playerBase)
        changed = 1
    endif
    int count = here.GetNumRefs(0)
    if count > 5000
        count = 5000
    endif
    int i = 0
    while i < count
        ObjectReference ref = here.GetNthRef(i, 0)
        if ref && !(ref as Actor)
            if house || ref.GetDistance(player) <= 1500.0
                bool owned = TESOwnedBy(ref.GetActorOwner(), ref.GetFactionOwner(), giver)
                if owned
                    ref.SetActorOwner(playerBase)
                    changed += 1
                endif
                if (owned || house) && ref.IsLocked()
                    ref.Lock(false)
                endif
            endif
        endif
        i += 1
    endwhile
    AIAgentFunctions.logMessage("tesgive " + mode + "@@" + giver.GetDisplayName() + " gave " + changed + " references to the player", "tes_god_console")
EndFunction

bool Function TESOwnedBy(ActorBase ownerBase, Faction ownerFaction, Actor giver) Global
    if ownerBase && (ownerBase == giver.GetActorBase() || ownerBase == giver.GetLeveledActorBase())
        return true
    endif
    return ownerFaction && giver.IsInFaction(ownerFaction)
EndFunction

; TES-Speech-Adapter: "tesremove" - disable and delete the selected console reference, but
; only if it was created during play (FormID FFxxxxxx, negative in Papyrus): god summons,
; clones. Anything from the game data is refused.
Function TESRemoveSelected() Global
    ObjectReference target = ConsoleUtil.GetSelectedReference()
    if !target
        AIAgentFunctions.logMessage("tesremove@@error: nothing selected (not found nearby)", "tes_god_console")
        return
    endif
    String targetName = target.GetDisplayName()
    if target.GetFormID() >= 0
        AIAgentFunctions.logMessage("tesremove@@refused: " + targetName + " is part of the game world, not a summon", "tes_god_console")
        return
    endif
    target.Disable()
    target.Delete()
    AIAgentFunctions.logMessage("tesremove@@removed " + targetName, "tes_god_console")
EndFunction

; TES-Speech-Adapter: "tesrussify" - NPCs around the player that Real Names Extended named
; before its Russian lists were installed keep Latin names in the save. Re-roll them with
; the mod's own "[RN] Rechange" spell (RealNamesExtended.esp 0x82C), which now picks from
; the Russian lists. Latin names not given by Real Names (mod NPCs) are only reported.
Function TESRussifyNames() Global
    Actor player = Game.GetPlayer()
    Spell rechange = Game.GetFormFromFile(0x82C, "RealNamesExtended.esp") as Spell
    if !rechange
        AIAgentFunctions.logMessage("tesrussify@@error: Real Names Extended rechange spell not found", "tes_god_console")
        return
    endif
    Actor[] actors = MiscUtil.ScanCellNPCs(player, 8192.0, None, true)
    int renamed = 0
    String others = ""
    int i = 0
    while i < actors.Length
        Actor candidate = actors[i]
        if candidate && candidate != player
            String shown = candidate.GetDisplayName()
            int first = StringUtil.AsOrd(StringUtil.GetNthChar(shown, 0))
            if (first >= 65 && first <= 90) || (first >= 97 && first <= 122)
                if StorageUtil.GetStringValue(candidate, "RNE_Name") != ""
                    rechange.Cast(player, candidate)
                    renamed += 1
                elseif StringUtil.GetLength(others) < 200
                    others = others + shown + "; "
                endif
            endif
        endif
        i += 1
    endwhile
    AIAgentFunctions.logMessage("tesrussify@@renamed " + renamed + "; not Real Names: " + others, "tes_god_console")
EndFunction

; TES-Speech-Adapter: "tesdress <runtime FormID as decimal>" - the selected actor gets the
; item and wears it for good: EquipItem with abPreventRemoval, so the NPC does not switch
; back to its outfit (console equipitem on NPCs does not stick).
Function TESDress(String formIdText) Global
    Actor target = ConsoleUtil.GetSelectedReference() as Actor
    Form item = Game.GetForm(formIdText as int)
    if !target || !item
        AIAgentFunctions.logMessage("tesdress " + formIdText + "@@error: no actor selected or item not found", "tes_god_console")
        return
    endif
    if target.GetItemCount(item) < 1
        target.AddItem(item, 1, true)
    endif
    target.EquipItem(item, true, true)
    AIAgentFunctions.logMessage("tesdress " + formIdText + "@@" + target.GetDisplayName() + " now wears " + item.GetName(), "tes_god_console")
EndFunction

; TES-Speech-Adapter: "tesroutine here|reset" - a new daily life for the selected NPC.
;   here  - a persistent XMarker where the player stands; the NPC is linked to it and gets
;           CHIM's SandboxWork package (AIAgent.esp 0x40BE6, sandbox near the linked ref,
;           needs CHIM's sandbox faction 0x21246) at priority 90, above its own schedule;
;   reset - package, faction, link and marker removed: back to the old schedule.
Function TESRoutine(String mode) Global
    Actor target = ConsoleUtil.GetSelectedReference() as Actor
    if !target
        AIAgentFunctions.logMessage("tesroutine " + mode + "@@error: no actor selected", "tes_god_console")
        return
    endif
    Faction sandboxFaction = Game.GetFormFromFile(0x21246, "AIAgent.esp") as Faction
    Package sandboxWork = Game.GetFormFromFile(0x40BE6, "AIAgent.esp") as Package
    ObjectReference oldMarker = StorageUtil.GetFormValue(target, "TESRoutineMarker") as ObjectReference
    if mode == "reset"
        ActorUtil.RemovePackageOverride(target, sandboxWork)
        target.RemoveFromFaction(sandboxFaction)
        PO3_SKSEFunctions.SetLinkedRef(target, None)
        if oldMarker
            oldMarker.Disable()
            oldMarker.Delete()
        endif
        StorageUtil.UnsetFormValue(target, "TESRoutineMarker")
        target.EvaluatePackage()
        AIAgentFunctions.logMessage("tesroutine reset@@" + target.GetDisplayName() + " is back to the old schedule", "tes_god_console")
        return
    endif
    if !sandboxFaction || !sandboxWork
        AIAgentFunctions.logMessage("tesroutine here@@error: CHIM sandbox package not found", "tes_god_console")
        return
    endif
    ObjectReference marker = Game.GetPlayer().PlaceAtMe(Game.GetForm(0x3B), 1, true, false)
    if oldMarker
        oldMarker.Disable()
        oldMarker.Delete()
    endif
    StorageUtil.SetFormValue(target, "TESRoutineMarker", marker)
    target.SetFactionRank(sandboxFaction, 1)
    PO3_SKSEFunctions.SetLinkedRef(target, marker)
    ActorUtil.AddPackageOverride(target, sandboxWork, 90, 0)
    target.EvaluatePackage()
    AIAgentFunctions.logMessage("tesroutine here@@" + target.GetDisplayName() + " now lives around this place", "tes_god_console")
EndFunction

; TES-Speech-Adapter: "tesoutfit <runtime FormID as decimal>" - change the selected NPC's
; default outfit (Actor.SetOutfit, the same call CHIM uses for its own characters). Unlike
; equipping items, the game itself puts this outfit on again after every reload of the
; NPC's 3D, so it does not get reset.
Function TESOutfit(String formIdText) Global
    Actor target = ConsoleUtil.GetSelectedReference() as Actor
    Outfit wanted = Game.GetForm(formIdText as int) as Outfit
    if !target || !wanted
        AIAgentFunctions.logMessage("tesoutfit " + formIdText + "@@error: no actor selected or outfit not found", "tes_god_console")
        return
    endif
    target.SetOutfit(wanted, false)
    AIAgentFunctions.logMessage("tesoutfit " + formIdText + "@@" + target.GetDisplayName() + " now has a new default outfit", "tes_god_console")
EndFunction