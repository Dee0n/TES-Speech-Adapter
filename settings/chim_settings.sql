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
      || 'tgm is a switch (on/off) - read the journal before using it again. Refused commands and real results are in your god command journal. '
      || 'NEVER guess or invent names: if an item/place/actor name is refused, or you are unsure, look it up first: find предмет|заклинание|способность|персонаж|фракция|место <words> (e.g. find предмет мантия Седобородых) - the exact existing names appear in your god command journal on your NEXT turn, use them verbatim then. '
      || 'Recipes: '
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
      || 'deep mind rewrite (hypnosis: CHIM regenerates personality, goals, speech style and trade from your instruction - use it when someone must truly change, e.g. stop hating the player): {npc:Name}.hypnosis instruction in Russian, naming the player, e.g. {npc:Назим}.hypnosis благодарен Шаману за воскрешение, добр и вежлив с ним, раскаивается в прошлых ссорах; combine with relation. '
      || 'To change how someone TREATS the player (kind instead of rude), set ALL THREE, each as its own command: character personality + character speechstyle (how they now talk to the player) + character goals (what they now want) - old speechstyle/goals keep them rude otherwise. '
      || 'marry two people (one spouse each: former spouses and romances become exes, both remember the wedding, rumor spreads): {npc:A}.marry B | '
      || 'give someone a lasting memory of what happened (they will know it in every talk): {npc:Name}.remember what happened, in their words. '
      || 'change where someone spends their days (a beggar at the gate, a guard at a door, a new job spot): {npc:Name}.routine here - they will live around the spot where the player stands now; back to their old schedule: {npc:Name}.routine reset. '
      || 'Story changes: always make everyone involved REMEMBER them (remember/marry), and remove leftovers you replaced ({near:Name}.unsummon). '
      || 'Never use disable/enable on NPCs (breaks their model). '
      || 'documents and papers (deed, permit, pass, receipt, contract, letter, certificate, or a FORGERY written in someone else''s name with their seal) - a real readable paper appears in the inventory: player.document <paper name>: <full text in Russian, signed>, e.g. player.document Купчая на дом: Сим удостоверяется, что Шаман приобрёл дом в Вайтране за 5000 септимов. Подпись: Провентус Авениччи, управитель ярла | {npc:Name}.document <paper name>: <text>. Never invent item names for papers - write the document. No ";" and no line breaks inside the text.',
    updated_at = now()
WHERE code_name = 'GodCommand';

-- 2026-10-03: the model reads action descriptions from core_action, NOT from functions.php's
-- $F_TRANSLATIONS_LOCAL (edits there never reached it). Gold must not go through the
-- trade/gift window (live: Назим answered a million septims with OpenInventory2).
UPDATE public.core_action SET
    description = 'Opens the trade window to exchange ITEMS with #PLAYER_NAME#. Never use it for money: to take gold from #PLAYER_NAME# use Take_Gold_From_#PLAYER_NAME#, to give gold use Give_Gold_To.',
    updated_at = now()
WHERE code_name = 'OpenInventory';
UPDATE public.core_action SET
    description = 'Opens the gift window so #PLAYER_NAME# can hand ITEMS to #HERIKA_NAME#. Never use it for money (gold, coins, septims, payment, debt, loan): use Take_Gold_From_#PLAYER_NAME# to take gold, or Give_Gold_To to give gold.',
    updated_at = now()
WHERE code_name = 'OpenInventory2';
UPDATE public.core_action SET
    description = '#HERIKA_NAME# gives gold, coins, or septims to another actor or #PLAYER_NAME# (also when #PLAYER_NAME# asks for money and #HERIKA_NAME# agrees) - never the trade window. REQUIRED: Must include ''target'' field with recipient name and ''item'' field with amount as a number string.',
    updated_at = now()
WHERE code_name = 'GiveGoldTo';

-- Any NPC can write a real paper for the player (server: functions.php postfilter
-- WriteDocument -> tesGodGuardMakeDocument -> spawnBook, the same channel CHIM's physical
-- NPC diaries use). Owner, 2026-10-03: "чтоб они все умели какие-то документы делать".
INSERT INTO public.core_action (code_name, action_name, description, return_message, available_to_npc,
    available_to_followers, available_to_narrator, is_activated, parameters_json, metadata, game_function, import_version)
SELECT 'WriteDocument', 'Write_Document', '', '#HERIKA_NAME# hands #PLAYER_NAME# a written paper.', true, true, false, true,
    '{"type": "object", "required": ["target"], "properties": {"target": {"type": "string", "description": "<paper name>: <full text in Russian, signed by its author>, e.g. Расписка: Получил от Шамана 500 септимов. Назим"}}}'::jsonb,
    '{"source": "tes-speech-adapter", "status": "active", "builtin": false, "dispatch": "rolecommand"}'::jsonb, true, 0
WHERE NOT EXISTS (SELECT 1 FROM public.core_action WHERE code_name = 'WriteDocument');
UPDATE public.core_action SET is_activated = true, available_to_npc = true, available_to_followers = true,
    available_to_narrator = false,
    description = '#HERIKA_NAME# writes a real paper and hands it to #PLAYER_NAME#: a receipt, contract, deed of sale, permit, pass, letter of recommendation, IOU, note, map directions - whatever #HERIKA_NAME# would plausibly write in their role (a steward writes deeds and permits, a merchant receipts, a scholar notes). A shady character may forge one in someone else''s name. target = "<paper name>: <text>" (e.g. Расписка: Получил от Шамана 500 септимов. Назим), text in Russian, signed by its author. Use it whenever #HERIKA_NAME# promises to write, sign or issue a paper - do not just talk about it.',
    updated_at = now()
WHERE code_name = 'WriteDocument';
UPDATE public.core_action SET parameters_json = '{"type": "object", "required": ["target"], "properties": {"target": {"type": "string", "description": "<paper name>: <full text in Russian, signed by its author>, e.g. Расписка: Получил от Шамана 500 септимов. Назим"}}}'::jsonb WHERE code_name = 'WriteDocument';
-- TES-ESTATE (2026-10-03, owner: "сделай так чтоб он умел это делать"): stewards and jarls
-- really sell the city houses. Live 07:16: Proventus had no such action, "sold" Breezehome by
-- walking around and handing over a nameless item (GiveItemTo 0x001046D3). Server:
-- ext/tes_estate -> bridge tesbuyhouse (vanilla HousePurchase stage, gold checked in game).
-- Also included in chim_settings.sql. Rollback: DELETE FROM core_action WHERE code_name='SellHouse';
INSERT INTO public.core_action (code_name, action_name, description, return_message, available_to_npc,
    available_to_followers, available_to_narrator, is_activated, parameters_json, metadata, game_function, import_version)
SELECT 'SellHouse', 'Sell_House', '', '#HERIKA_NAME# draws up the sale of the house.', true, false, false, true,
    '{"type": "object", "required": ["target"], "properties": {"target": {"type": "string", "description": "the house: Дом теплых ветров, Высокий шпиль, Медовик, Влиндрел-холл or Хьерим"}}}'::jsonb,
    '{"source": "tes-speech-adapter", "status": "active", "builtin": false, "dispatch": "rolecommand"}'::jsonb, true, 0
WHERE NOT EXISTS (SELECT 1 FROM public.core_action WHERE code_name = 'SellHouse');
UPDATE public.core_action SET is_activated = true, available_to_npc = true, available_to_followers = false,
    available_to_narrator = false,
    description = 'Only a city steward or jarl: actually sell #PLAYER_NAME# the city''s house for sale (Whiterun: Дом теплых ветров, Solitude: Высокий шпиль, Riften: Медовик, Markarth: Влиндрел-холл, Windhelm: Хьерим). The game itself takes the price in gold and gives the key, the decorating guide and ownership - no walking, no GiveItemTo of keys. Prices (Requiem): Дом теплых ветров 3000, Медовик 4000, Влиндрел-холл 5000, Хьерим 6000, Высокий шпиль 10000. Use it once #PLAYER_NAME# agrees to buy; the result (sold / not enough gold / already owned) comes back to you.',
    updated_at = now()
WHERE code_name = 'SellHouse';

-- Live 07:21-07:23: Proventus said "follow me" four times while issuing FollowPlayer (he
-- followed the player instead). Make the direction explicit.
UPDATE public.core_action SET
    description = '#HERIKA_NAME# walks BEHIND #PLAYER_NAME# (the player leads). To lead #PLAYER_NAME# somewhere ("follow me", "I will show you the way") use TravelTo with the place instead.',
    updated_at = now()
WHERE code_name = 'FollowPlayer';
UPDATE public.core_action SET
    description = '#HERIKA_NAME# walks to a building, city, door or other location - also to LEAD #PLAYER_NAME# there ("follow me"). Name the place as it is called in the game.',
    updated_at = now()
WHERE code_name = 'TravelTo';
