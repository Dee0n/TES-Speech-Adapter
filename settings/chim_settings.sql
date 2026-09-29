-- CHIM database tweaks re-applied by install.sh (idempotent).
-- CHIM updates can reset built-in actions, so keep them here.

-- Let NPCs take the gold they ask for straight from the player's inventory.
-- Off by default; without it NPCs open the trade window, where gold cannot be
-- handed over, and quests waiting for payment stall.
UPDATE public.core_action SET is_activated = true, updated_at = now()
WHERE code_name = 'TakeGoldFromPlayer' AND is_activated IS DISTINCT FROM true;

-- Narrator as game master: on request it can create/spawn NPCs, stage a
-- scene through director mode, or teleport an actor. NPCs can be told to
-- wait here.
UPDATE public.core_action SET is_activated = true, updated_at = now()
WHERE code_name IN ('CreateNewNPC', 'DirectorCommand', 'SpawnNPC', 'TeleportNPC', 'WaitHere')
  AND is_activated IS DISTINCT FROM true;

-- Taverns: staff can actually bring the food or drink the player paid for.
-- The server (herika-npc-spawn-food.patch) only lets regular NPCs spawn food
-- and drink, max 5 at a time; the narrator (game master) may spawn anything.
UPDATE public.core_action SET
    is_activated = true, available_to_npc = true, available_to_followers = true, available_to_narrator = true,
    description = 'Creates a real game item and gives it to the target. If #HERIKA_NAME# is The Narrator (game master): any item #PLAYER_NAME# asks for, by its exact English name from the descriptions database (e.g. Daedric Sword, Fine Clothes, Fine Boots). Any other NPC: ONLY food or drink #HERIKA_NAME# serves or sells at work (innkeeper, tavern staff, cook), and ONLY after #PLAYER_NAME# has paid; exact English name: Ale (эль), Nord Mead (нордский мёд), Honningbrew Mead, Black-Briar Mead, Wine, Alto Wine, Spiced Wine, Bread, Sweet Roll, Apple Pie, Eidar Cheese Wedge, Goat Cheese Wedge, Beef Stew, Vegetable Soup, Cabbage Potato Soup, Horker Stew, Venison Stew, Salmon Steak, Cooked Beef, Grilled Chicken Breast, Leg of Goat Roast. Target is #PLAYER_NAME# unless another recipient is named.',
    updated_at = now()
WHERE code_name = 'SpawnItem';

-- Narrator cheats, enabled on request: create gold, kill a target.
UPDATE public.core_action SET is_activated = true, available_to_narrator = true, updated_at = now()
WHERE code_name IN ('SpawnGold', 'KillTarget') AND is_activated IS DISTINCT FROM true;

-- NPC gifts that really change ownership in game (no "steal"). Server: ext/tes_gifts ->
-- outbox -> bridge override TESGodConsoleReport:
--   horse  -> ["tesnear Лошадь", "setownership"] (nearest such animal to the player)
--   around -> ["tesnear <giver>", "tesgive around"] (giver's things within 1500 units)
--   house  -> ["tesnear <giver>", "tesgive house"] (the interior the player stands in)
--   all    -> ["tesnear <giver>", "tesgive all"] (everything carried)
--   spell:<name> -> ["player.addspell <FormID>"] (game index)
DELETE FROM public.core_action WHERE code_name = 'GiveHorse';  -- first version, replaced
INSERT INTO public.core_action (code_name, action_name, description, return_message, available_to_npc,
    available_to_followers, available_to_narrator, is_activated, parameters_json, metadata, game_function, import_version)
SELECT 'GiveToPlayer', 'Give_To_Player', '', 'Gave #TARGET# to #PLAYER_NAME#', true, true, false, true,
    '{"type": "object", "required": ["target"], "properties": {"target": {"type": "string", "description": "horse | around | house | all | spell:<spell name>"}}}'::jsonb,
    '{"source": "tes-speech-adapter", "status": "active", "builtin": false, "dispatch": "rolecommand"}'::jsonb, true, 0
WHERE NOT EXISTS (SELECT 1 FROM public.core_action WHERE code_name = 'GiveToPlayer');
-- available_to_narrator = false: this is an NPC-owns-it action ("something #HERIKA_NAME#
-- owns"), not a Narrator/god-mode action. Found set to true by accident 2026-09-29 (an
-- unrelated SQL mistake overwrote every core_action row's flags); pinned here explicitly
-- so a future re-apply of this file can't lose it again.
UPDATE public.core_action SET is_activated = true, available_to_npc = true, available_to_followers = true,
    available_to_narrator = false,
    description = 'Really hand over to #PLAYER_NAME# something #HERIKA_NAME# owns, so it is no longer stolen - use it ONLY when #HERIKA_NAME# truly agrees to give, sell (after payment) or bequeath it. target: '
      || '"horse" = a horse/mount standing near #PLAYER_NAME# (a stablemaster gives or sells a horse); '
      || '"around" = #HERIKA_NAME#''s things near #PLAYER_NAME#: chests, furniture, items lying around (take anything, look into the chest); '
      || '"house" = the house #PLAYER_NAME# is standing in right now, with everything inside and its doors (only if it is #HERIKA_NAME#''s home); '
      || '"all" = literally everything #HERIKA_NAME# carries and wears; '
      || '"spell:<name>" = teach #PLAYER_NAME# a spell #HERIKA_NAME# knows (e.g. spell:Огненная стрела). '
      || 'For a single item from the inventory use Give_Item_To, for gold Give_Gold_To.',
    updated_at = now()
WHERE code_name = 'GiveToPlayer';

-- Narrator god mode: run Skyrim console commands (server: herikaQueueGodCommands
-- in herika-actions.patch -> quest action outbox -> AIAgent executes them).
INSERT INTO public.core_action (code_name, action_name, description, return_message, available_to_npc,
    available_to_followers, available_to_narrator, is_activated, parameters_json, metadata, game_function, import_version)
SELECT 'GodCommand', 'God_Command', '', 'Done: #TARGET#', false, false, true, true,
    '{"type": "object", "required": ["target"], "properties": {"target": {"type": "string", "description": "REQUIRED: one or more Skyrim console commands separated by ;"}}}'::jsonb,
    '{"source": "tes-speech-adapter", "status": "active", "builtin": false, "dispatch": "rolecommand"}'::jsonb, true, 0
WHERE NOT EXISTS (SELECT 1 FROM public.core_action WHERE code_name = 'GodCommand');
UPDATE public.core_action SET is_activated = true, available_to_narrator = true, available_to_npc = false,
    description = 'God mode: run Skyrim console commands to change the world directly. target = commands separated by ";" (max 8). '
      || 'Actors: ALWAYS write {npc:Exact Name} exactly as the name appears in the scene (e.g. {npc:Амрен}) - the server or the game finds the actor, also people who never spoke to you; copy a hex RefID only if no name is known, or use player. '
      || 'Placeholders: {item:item name, Russian or English}, {cell:exact place name, e.g. Драконий Предел}, {spawn:creature or person name},{weather:Clear|Cloudy|Fog|Rain|Thunderstorm|Snow|Blizzard|Dark}, '
      || '{explosion:fire|frost|shock|big|huge|visual} (visual = no damage), {spawn:bandit|mage|archer|boss}, {spell:spell name, Russian or English}, {perk:perk name}, {faction:faction name}. '
      || 'tgm is a switch (on/off) - read the journal before using it again. Refused commands and real results are in your god command journal. Recipes: '
      || 'resurrect: {npc:Name}.resurrect | heal: {npc:Name}.restoreav health 1000 | fully heal, revive from unconsciousness and cure disease: {npc:Name}.heal or player.heal | dress someone: {npc:Name}.equipitem {item:exact Russian item name} - clothes may reset after a reload, this is not guaranteed permanent, do not claim it is. outfit is BROKEN (leaves the NPC naked) - never use it, never suggest it. | undress: {npc:Name}.unequipall | '
      || 'weather: fw {weather:Thunderstorm} | time: set gamehour to 22 | give: player.additem {item:Daedric Sword} 1 | level up: player.advlevel | invulnerable: tgm | '
      || 'make friend/lover: {npc:Name}.setrelationshiprank player 4 | calm: {npc:Name}.stopcombat | giant: {npc:Name}.setscale 3 | bring: {npc:Name}.moveto player | '
      || 'spawn people: player.placeatme {spawn:bandit} 6 | explosion here: player.placeatme {explosion:huge} 1 | '
      || 'rain of exploding people: player.placeatme {spawn:bandit} 6; player.placeatme {explosion:huge} 1 | '
      || 'teleport the player: coc {cell:Place or city name, e.g. Рифтен} | '
      || 'summon any creature by name: player.placeatme {spawn:Курица|Великан|Дракон|...} N (max 10); unique people are not cloned - bring the real one with {npc:Name}.moveto player, or make a new person with Create_New_NPC | '
      || 'remove someone you summoned or cloned: {near:Name}.unsummon (only works on beings created during play) | '
      || 'rain of cheese: player.placeatme {item:Cheese Wheel} 10 | slow motion: sgtm 0.3 (back to normal: sgtm 1) | '
      || 'super speed: player.setav speedmult 300 | fus ro dah: player.pushactoraway {npc:Name} 50 | tiny: {npc:Name}.setscale 0.3 | '
      || 'teach a spell: {npc:Name}.addspell {spell:Fireball} | grant an ability: {npc:Name}.addperk {perk:perk name} | join a faction (rank is REQUIRED, use 0 if unsure): {npc:Name}.addfac {faction:faction name} 0 | leave a faction: {npc:Name}.removefac {faction:faction name} | '
      || 'enchanting an item is NOT possible yet (no working command for it) - do not claim you enchanted something. | '
      || 'rewrite a character (their memory in CHIM, no ";" inside the text): {npc:Name}.character personality: new personality | {npc:Name}.character occupation: new trade/status | '
      || '{npc:Name}.character speechstyle: how they talk | feelings towards the player: {npc:Name}.relation <-100..100> <friend|romantic|grateful|admirer|rival|enemy|fearful|...> short reason; feelings between two NPCs: {npc:A}.relation to B 80 romantic reason (set both directions if mutual). '
      || 'spread news or a rumor through the current hold (every local NPC hears it for 14 days): rumor Говорят, что ... (a character occupation change spreads a rumor by itself). '
      || 'To make someone rich/noble/friendly, combine: dress them + character occupation + character personality + relation (+ rumor so family and neighbours know). '
      || 'marry two people (one spouse each: former spouses and romances become exes, both remember the wedding, rumor spreads): {npc:A}.marry B | '
      || 'give someone a lasting memory of what happened (they will know it in every talk): {npc:Name}.remember what happened, in their words. '
      || 'change where someone spends their days (a beggar at the gate, a guard at a door, a new job spot): {npc:Name}.routine here - they will live around the spot where the player stands now; back to their old schedule: {npc:Name}.routine reset. '
      || 'Story changes: always make everyone involved REMEMBER them (remember/marry), and remove leftovers you replaced ({near:Name}.unsummon). '
      || 'Never use disable/enable on NPCs (breaks their model).',
    updated_at = now()
WHERE code_name = 'GodCommand';
