<?php
/*
 * tes_estate: the bridge reports "tesbuyhouse <args>@@<result>" as a tes_god_console
 * request. ext/ is scanned alphabetically, so this runs BEFORE tes_god_console (which stores
 * the line and terminates): here the seller hears the result and the player sees it.
 */

if (strtolower(strval($GLOBALS['gameRequest'][0] ?? '')) === 'tes_god_console') {
    try {
        $message = implode('|', array_slice($GLOBALS['gameRequest'], 3));
        if (str_starts_with($message, 'tesfurnish@@') && isset($GLOBALS['db'])) {
            require_once __DIR__ . '/lib.php';
            $result = substr($message, 12);
            tesEstateEnsureTable();
            $db = $GLOBALS['db'];
            $sale = $db->fetchOne("SELECT id, seller, house FROM public.tes_estate_sales WHERE command = 'tesfurnish' AND result = '' ORDER BY id DESC LIMIT 1");
            if (!empty($sale['id']) && preg_match('/furnished (\d+) rooms for (\d+) gold, already had (\d+), could not afford (\d+)/', $result, $m)) {
                $db->execQuery("UPDATE public.tes_estate_sales SET result = '" . $db->escape($result) . "' WHERE id = " . intval($sale['id']));
                [$bought, $spent, $had, $poor] = [intval($m[1]), intval($m[2]), intval($m[3]), intval($m[4])];
                if ($bought > 0) {
                    tesEstateNotify("Обстановка «{$sale['house']}»: комнат {$bought}, потрачено {$spent}");
                }
                $text = $bought > 0
                    ? "Обстановка для «{$sale['house']}» заказана и уже на месте: комнат {$bought}, игра взяла {$spent} септимов."
                    : ($had > 0 && $poor === 0 ? "В «{$sale['house']}» всё, что ты продаёшь, уже куплено." : "Обстановку купить не вышло.");
                if ($poor > 0) {
                    $text .= " На {$poor} комнат(ы) у игрока не хватило золота.";
                }
                tesEstateTell($sale['seller'], "({$text} Скажи это коротко, 1-2 фразы, без выдумок.)");
            }
        }
        if (str_starts_with($message, 'tesbuyhouse ') && isset($GLOBALS['db'])) {
            require_once __DIR__ . '/lib.php';
            [$cmd, $result] = array_pad(explode('@@', $message, 2), 2, '');
            tesEstateEnsureTable();
            $db = $GLOBALS['db'];
            $sale = $db->fetchOne("SELECT id, seller, house FROM public.tes_estate_sales WHERE command = '" . $db->escape(trim($cmd))
                . "' AND result = '' ORDER BY id DESC LIMIT 1");
            if (!empty($sale['id'])) {
                $db->execQuery("UPDATE public.tes_estate_sales SET result = '" . $db->escape($result) . "' WHERE id = " . intval($sale['id']));
                if (preg_match('/^sold for (\d+), gold left (\d+)/', $result, $m)) {
                    tesEstateNotify("Куплен дом: {$sale['house']} за {$m[1]} септимов");
                    $prepaidNote = '';
                    if (str_ends_with(trim($cmd), ' prepaid')) {
                        $taken = 0;
                        foreach ((array)$db->fetchAll("SELECT fullcall FROM actions_issued WHERE actorname = '" . $db->escape($sale['seller']) . "' AND action ILIKE 'TakeGoldFromPlayer%'") as $r) {
                            if (preg_match('/TakeGoldFromPlayer@\D*(\d+)/', strval($r['fullcall']), $tm)) {
                                $taken += intval($tm[1]);
                            }
                        }
                        $change = $taken - intval($m[1]);
                        $prepaidNote = " Плата засчитана из тех денег, что ты взял раньше ({$taken})."
                            . ($change > 0 ? " Ты должен игроку сдачу {$change} септимов — верни её сейчас действием Give_Gold_To (target: игрок, item: {$change})." : '');
                    }
                    tesEstateTell($sale['seller'], "(Сделка состоялась: «{$sale['house']}» теперь принадлежит игроку, игра выдала ему ключ, книгу обустройства и права на дом за {$m[1]} септимов.{$prepaidNote} Скажи об этом коротко, 1-2 фразы, не повторяй сказанное раньше, никуда не веди.)");
                } elseif (preg_match('/not enough gold: has (\d+), price (\d+)/', $result, $m)) {
                    tesEstateTell($sale['seller'], "(Сделка не состоялась: у игрока {$m[1]} септимов, а «{$sale['house']}» стоит {$m[2]}. Скажи это одной фразой, без скидок по своей воле.)");
                } elseif (str_contains($result, 'already owns')) {
                    tesEstateTell($sale['seller'], "(«{$sale['house']}» уже принадлежит игроку. Скажи это одной фразой.)");
                } else {
                    tesEstateTell($sale['seller'], "(Оформить продажу «{$sale['house']}» не вышло: {$result}. Признай это одной фразой.)");
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[tes_estate preprocessing] ' . $e->getMessage());
    }
}
