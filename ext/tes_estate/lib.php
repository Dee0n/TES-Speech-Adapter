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

    /** The house this NPC may sell (stewards and jarls), or null. */
    function tesEstateHouseOfSeller(string $seller): ?array
    {
        foreach (tesEstateHouses() as $h) {
            if (tesEstateMaySell($h, $seller)) {
                return $h;
            }
        }
        return null;
    }

    /** Requiem prices of the HP* globals (hp dump 2026-10-03); the bridge reads the real global. */
    function tesEstatePrice(array $house): int
    {
        return [10 => 3000, 20 => 10000, 30 => 4000, 40 => 5000, 50 => 6000][$house['stage']] ?? 0;
    }

    /**
     * Gold this seller already took from the player through CHIM's TakeGoldFromPlayer and
     * has not been "spent" on an earlier sale. Live 2026-10-03: Proventus took 500 000 at
     * 03:07 and gave nothing; the real sale must not charge the player a second time.
     */
    function tesEstatePrepaid(string $seller): int
    {
        $db = $GLOBALS['db'];
        $rows = $db->fetchAll("SELECT fullcall FROM actions_issued WHERE actorname = '" . $db->escape($seller) . "' AND action ILIKE 'TakeGoldFromPlayer%'");
        $taken = 0;
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (preg_match('/TakeGoldFromPlayer@\D*(\d+)/', strval($r['fullcall']), $m)) {
                $taken += intval($m[1]);
            }
        }
        tesEstateEnsureTable();
        $sold = $db->fetchOne("SELECT count(*) AS n FROM public.tes_estate_sales WHERE seller = '" . $db->escape($seller) . "' AND result LIKE 'sold%' AND command LIKE '% prepaid'");
        return intval($sold['n'] ?? 0) > 0 ? 0 : $taken;
    }

    /** Queue a bridge command for an NPC outside the god journal (own beat_id). */
    function tesEstateQueueFor(string $npcName, string $command, string $beat): bool
    {
        $db = $GLOBALS['db'];
        $row = function_exists('tesGodGuardResolveNpcLoose') ? tesGodGuardResolveNpcLoose($npcName) : null;
        $ref = strtoupper(trim(strval($row['refid'] ?? '')));
        $quest = $db->fetchOne("SELECT quest_key FROM public.skyrim_quest_instances ORDER BY quest_key LIMIT 1");
        if (!preg_match('/^[0-9A-F]{8}$/', $ref) || empty($quest['quest_key'])) {
            return false;
        }
        $db->insert('skyrim_quest_action_outbox', [
            'quest_key' => $quest['quest_key'], 'beat_id' => $beat, 'action_type' => 'console_command_sequence',
            'payload_json' => json_encode(['type' => 'console_command_sequence', 'commands' => ['prid ' . $ref, $command]]),
        ]);
        return true;
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
