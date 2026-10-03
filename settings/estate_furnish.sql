-- TES-ESTATE furnishings (2026-10-04, owner: "улучшения хаты все разом тоже купить бы хотел").
-- Server: ext/tes_estate -> bridge tesfurnish (per room: HD* global price, DecorateMarker.Enable,
-- OldMarker.Disable - what the vanilla steward dialogue does). Rollback:
-- DELETE FROM core_action WHERE code_name='FurnishHouse';
INSERT INTO public.core_action (code_name, action_name, description, return_message, available_to_npc,
    available_to_followers, available_to_narrator, is_activated, parameters_json, metadata, game_function, import_version)
SELECT 'FurnishHouse', 'Furnish_House', '', '#HERIKA_NAME# orders the furnishings for the house.', true, false, false, true,
    '{"type": "object", "required": ["target"], "properties": {"target": {"type": "string", "description": "the house: Дом теплых ветров, Высокий шпиль, Медовик, Влиндрел-холл or Хьерим"}}}'::jsonb,
    '{"source": "tes-speech-adapter", "status": "active", "builtin": false, "dispatch": "rolecommand"}'::jsonb, true, 0
WHERE NOT EXISTS (SELECT 1 FROM public.core_action WHERE code_name = 'FurnishHouse');
UPDATE public.core_action SET is_activated = true, available_to_npc = true, available_to_followers = false,
    available_to_narrator = false,
    description = 'Only a city steward or jarl, for the city house #PLAYER_NAME# already owns: buy ALL its furnishings and upgrades at once (kitchen, living room, dining room, loft, alchemy or enchanting corner - whatever that house has). The game takes each room''s price in gold itself and puts the furniture in place at once; rooms already bought are skipped. Use it when #PLAYER_NAME# asks to furnish, decorate, upgrade or improve the house; the result comes back to you.',
    updated_at = now()
WHERE code_name = 'FurnishHouse';
