<?php
/*
 * tes_estate prompt lines.
 *  - Narrator: how to give houses and move people (verbs handled by tes_god_guard).
 *  - A steward/jarl the player is asking about a house: use Sell_House NOW. Live 2026-10-03:
 *    Proventus had the action in his list for an hour and kept saying "follow me to my
 *    office" - the 80k prompt full of his own earlier lines outweighed the action text.
 */

try {
    if (function_exists('chimRegisterPromptInjection')) {
        $tesEstateSpeaker = strval($GLOBALS['HERIKA_NAME'] ?? '');
        $tesEstateNarrator = in_array(strval($GLOBALS['gameRequest'][0] ?? ''), ['narrator_inputtext', 'narration', 'narrator_welcome', 'narrator_quest_comment'], true)
            || strval($_GET['profile'] ?? '') === md5('The Narrator')
            || $tesEstateSpeaker === 'The Narrator';
        if ($tesEstateNarrator) {
            chimRegisterPromptInjection('prompt_bottom', 'tes_estate',
                'ДОМА: отдать игроку любой дом — GodCommand «player.house <название дома, как в игре>» (напр. player.house Дом Олавы Немощной): '
                . 'сервер сам найдёт дом и ключ. Не выдумывай setowner и номера ключей. '
                . 'Обставить городской дом игрока всеми улучшениями сразу (бесплатно) — «player.furnish <название дома>». '
                . 'ПЕРЕНОС: игрока в место — coc {cell:Место}; игрока к персонажу («перенеси МЕНЯ к нему») — player.moveto {npc:Имя}; '
                . 'персонажа к игроку («его ко мне») — {npc:Имя}.moveto player; игрока вместе с кем-то в место — coc {cell:Место}; {npc:Имя}.moveto player. '
                . 'Персонаж ходит за игроком и не отстаёт — {npc:Имя}.unfollow.', 56);
        } elseif ($tesEstateSpeaker !== '' && isset($GLOBALS['db'])) {
            require_once __DIR__ . '/lib.php';
            $tesEstateHouse = tesEstateHouseOfSeller($tesEstateSpeaker);
            $tesEstateSaid = mb_strtolower(strval($GLOBALS['gameRequest'][3] ?? ''));
            if ($tesEstateHouse && preg_match('/дом|хат|жиль|ключ|купи|купл|покуп|оформ|прода|недвиж|обстав|обстанов|мебел|улучш|комнат|ремонт/u', $tesEstateSaid)) {
                $tesEstatePrice = tesEstatePrice($tesEstateHouse);
                $tesEstatePaid = tesEstatePrepaid($tesEstateSpeaker);
                $tesEstateMoney = $tesEstatePaid >= $tesEstatePrice
                    ? "Игрок УЖЕ заплатил тебе {$tesEstatePaid} септимов — денег больше не проси, оплата будет засчитана."
                    : "Цена {$tesEstatePrice} септимов, игра возьмёт её сама — сам золото не бери.";
                chimRegisterPromptInjection('prompt_bottom', 'tes_estate_sale',
                    "ВАЖНО, ПРЯМО СЕЙЧАС: игрок говорит о покупке дома. Дом продаётся ОДНИМ действием Sell_House (target: {$tesEstateHouse['title']}) — "
                    . "оно мгновенно выдаёт настоящий ключ и права на дом. {$tesEstateMoney} Никакого кабинета, бумаг, ожидания и «следуйте за мной»: "
                    . "не используй Travel_To, Follow, Move_To, Give_Item_To. Если игрок согласен или уже платил — в ЭТОМ ответе действие Sell_House и одна короткая фраза. "
                    . "Если дом уже продан, а игрок просит обстановку, мебель, улучшения, комнаты — действие Furnish_House (target: {$tesEstateHouse['title']}): "
                    . "оно сразу покупает ВСЕ улучшения, цену каждой комнаты игра берёт сама.", 99);
            }
        }
    }
} catch (Throwable $e) {
    error_log('[tes_estate context_pre] ' . $e->getMessage());
}
