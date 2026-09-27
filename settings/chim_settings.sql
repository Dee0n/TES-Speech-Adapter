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
      || 'Refer to actors by the hex RefID shown as [RefID: XXXXXXXX] (or player). Placeholders: {item:English item name}, '
      || '{weather:Clear|Cloudy|Fog|Rain|Thunderstorm|Snow|Blizzard|Dark}. Examples: '
      || 'resurrect: XXXXXXXX.resurrect 1 | heal: XXXXXXXX.restoreav health 1000 | dress: XXXXXXXX.additem {item:Fine Clothes} 1; XXXXXXXX.equipitem {item:Fine Clothes} | '
      || 'weather: fw {weather:Thunderstorm} | time: set gamehour to 22 | give player: player.additem {item:Daedric Sword} 1 | '
      || 'level up: player.advlevel | invulnerable: tgm | make friendly: XXXXXXXX.setrelationshiprank player 4 | '
      || 'calm: XXXXXXXX.stopcombat | scale: XXXXXXXX.setscale 2 | bring to player: XXXXXXXX.moveto player | teleport player to actor: player.moveto XXXXXXXX.',
    updated_at = now()
WHERE code_name = 'GodCommand';
