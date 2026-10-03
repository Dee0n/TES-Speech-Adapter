<?php
/*
 * tes_estate: the five vanilla city houses. Facts from the game data (2026-10-03):
 * QF_HousePurchase_000A7B33.psc fragments (PurchaseHouse + SetObjectiveDisplayed per city),
 * HP* globals in Skyrim.esm (prices overridden by Requiem.esp), keys and NPC names from
 * tes_game_index. Stage = objective number; the bridge verifies GetStageDone after SetStage.
 */

if (!function_exists('tesEstateHouses')) {
    function tesEstateHouses(): array
    {
        return [
            ['names' => ['дом теплых ветров', 'дом тёплых ветров', 'breezehome'], 'title' => 'Дом теплых ветров', 'stage' => 10,
                'price_global' => 0x000F728B, 'sellers' => ['Провентус Авениччи', 'Балгруф Старший']],
            ['names' => ['высокий шпиль', 'поместье высокий шпиль', 'proudspire'], 'title' => 'Высокий шпиль', 'stage' => 20,
                'price_global' => 0x000F728C, 'sellers' => ['Фолк Огнебород']],
            ['names' => ['медовик', 'honeyside'], 'title' => 'Медовик', 'stage' => 30,
                'price_global' => 0x000F728D, 'sellers' => ['Ануриэль', 'Мавен Черный Вереск']],
            ['names' => ['влиндрел-холл', 'влиндрел холл', 'vlindrel hall'], 'title' => 'Влиндрел-холл', 'stage' => 40,
                'price_global' => 0x000F728E, 'sellers' => ['Рерик', 'Игмунд', 'Тонгвор Серебряная Кровь']],
            ['names' => ['хьерим', 'hjerim'], 'title' => 'Хьерим', 'stage' => 50,
                'price_global' => 0x000F728A, 'sellers' => ['Йорлейф', 'Ульфрик Буревестник', 'Брунвульф Зимний Простор']],
        ];
    }

    /** House by (fuzzy) name, or the one this seller sells when the name is empty/unknown. */
    function tesEstateFind(string $houseName, string $seller): ?array
    {
        $needle = mb_strtolower(trim(str_replace('ё', 'е', $houseName)));
        $sellerLc = mb_strtolower(trim(preg_replace('/\s*\[[^\]]*\]\s*$/u', '', $seller) ?? $seller));
        $bySeller = null;
        foreach (tesEstateHouses() as $h) {
            foreach ($h['names'] as $n) {
                $n = str_replace('ё', 'е', $n);
                if ($needle !== '' && (mb_strpos($needle, $n) !== false || mb_strpos($n, $needle) !== false)) {
                    return $h;
                }
            }
            foreach ($h['sellers'] as $s) {
                if (mb_strtolower($s) === $sellerLc) {
                    $bySeller = $h;
                }
            }
        }
        return $bySeller;
    }

    function tesEstateMaySell(array $house, string $seller): bool
    {
        $sellerLc = mb_strtolower(trim(preg_replace('/\s*\[[^\]]*\]\s*$/u', '', $seller) ?? $seller));
        foreach ($house['sellers'] as $s) {
            if (mb_strtolower($s) === $sellerLc) {
                return true;
            }
        }
        return false;
    }

    function tesEstateEnsureTable(): void
    {
        $GLOBALS['db']->execQuery("
            CREATE TABLE IF NOT EXISTS public.tes_estate_sales (
                id bigserial PRIMARY KEY,
                created_at timestamptz NOT NULL DEFAULT now(),
                seller text NOT NULL,
                house text NOT NULL,
                command text NOT NULL,
                result text NOT NULL DEFAULT ''
            )
        ");
    }

    /** Make an NPC react to a result (game sends an "instruction" request back). */
    function tesEstateTell(string $npc, string $instruction): void
    {
        $instruction = trim(str_replace(['@', '|', "\n", "\r"], [' at ', '/', ' ', ' '], $instruction));
        $GLOBALS['db']->insert('responselog', [
            'localts' => time(), 'sent' => 0, 'actor' => 'rolemaster', 'text' => '',
            'action' => 'rolecommand|Instruction@' . $npc . '@' . mb_substr($instruction, 0, 600) . '@0',
            'tag' => '',
        ]);
    }

    function tesEstateNotify(string $text): void
    {
        $text = trim(str_replace(['@', '|', "\n", "\r"], [' at ', '/', ' ', ' '], $text));
        $GLOBALS['db']->insert('responselog', [
            'localts' => time(), 'sent' => 0, 'actor' => 'rolemaster', 'text' => '',
            'action' => 'rolecommand|DebugNotification@' . mb_substr($text, 0, 200), 'tag' => '',
        ]);
    }
}
