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
