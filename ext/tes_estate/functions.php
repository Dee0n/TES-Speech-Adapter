<?php
/*
 * tes_estate: SellHouse (core_action, settings/estate.sql) from a steward/jarl ->
 * bridge "tesbuyhouse <stage> <price global>" (vanilla purchase, gold checked in game).
 * The bridge's report comes back through preprocessing.php and the seller reacts to it.
 */

require_once __DIR__ . '/lib.php';

$GLOBALS['action_post_process_fnct_ex'][] = function ($actions) {
    if (!is_array($actions) || !isset($GLOBALS['db'])) {
        return $actions;
    }
    foreach ($actions as $n => $action) {
        try {
            $parts = explode('|', strval($action));
            $call = explode('@', strval($parts[2] ?? ''));
            $code = function_exists('getFunctionCodeName') ? getFunctionCodeName($call[0]) : false;
            if (($code ?: $call[0]) !== 'SellHouse') {
                continue;
            }
            unset($actions[$n]);
            $seller = trim(strval($parts[0] ?? ''));
            $raw = implode('@', array_slice($call, 1));
            $payload = function_exists('decodeFunctionExecutionParameterPayload')
                ? decodeFunctionExecutionParameterPayload($raw) : json_decode($raw, true);
            $houseName = is_array($payload) ? trim(strval($payload['target'] ?? '')) : trim($raw);
            $house = tesEstateFind($houseName, $seller);
            if (!$house) {
                tesEstateTell($seller, "(Продажа не оформлена: такого дома на продажу нет. Продаются: Дом теплых ветров, Высокий шпиль, Медовик, Влиндрел-холл, Хьерим. Скажи это одной фразой.)");
                continue;
            }
            if (!tesEstateMaySell($house, $seller)) {
                tesEstateTell($seller, "(Ты не можешь продать «{$house['title']}» — это не твой город. Скажи, к кому обратиться, одной фразой.)");
                continue;
            }
            tesEstateEnsureTable();
            $db = $GLOBALS['db'];
            // one sale attempt per house per minute: the model repeats actions in rechat
            $recent = $db->fetchOne("SELECT 1 AS x FROM public.tes_estate_sales WHERE house = '" . $db->escape($house['title']) . "' AND created_at > now() - interval '1 minute'");
            if (!empty($recent)) {
                continue;
            }
            $prepaid = tesEstatePrepaid($seller) >= tesEstatePrice($house);
            $command = 'tesbuyhouse ' . $house['stage'] . ' ' . $house['price_global'] . ($prepaid ? ' prepaid' : '');
            // a seller who once "followed" the player keeps trailing them (CHIM follow flag)
            tesEstateQueueFor($seller, 'tesunfollow', 'tes_unfollow');
            $db->insert('tes_estate_sales', ['seller' => $seller, 'house' => $house['title'], 'command' => $command]);
            $queued = function_exists('herikaQueueGodCommands') ? herikaQueueGodCommands($command) : 0;
            error_log("[tes_estate] {$seller} sells {$house['title']}: {$command} (queued {$queued})");
            if ($queued === 0) {
                tesEstateTell($seller, '(Оформить продажу не вышло — канал игры недоступен. Извинись одной фразой.)');
            }
        } catch (Throwable $e) {
            error_log('[tes_estate] ' . $e->getMessage());
        }
    }
    return $actions;
};
