<?php
/*
 * tes_estate: the Narrator's one line about giving houses (verb handled by tes_god_guard).
 */

try {
    $tesEstateNarrator = in_array(strval($GLOBALS['gameRequest'][0] ?? ''), ['narrator_inputtext', 'narration', 'narrator_welcome', 'narrator_quest_comment'], true)
        || strval($_GET['profile'] ?? '') === md5('The Narrator')
        || strval($GLOBALS['HERIKA_NAME'] ?? '') === 'The Narrator';
    if ($tesEstateNarrator && function_exists('chimRegisterPromptInjection')) {
        chimRegisterPromptInjection('prompt_bottom', 'tes_estate',
            'ДОМА: отдать игроку любой дом — GodCommand «player.house <название дома, как в игре>» (напр. player.house Дом Олавы Немощной): '
            . 'сервер сам найдёт дом и ключ, дом станет собственностью игрока; если игрок стоит внутри — и всё в доме. '
            . 'Не выдумывай setowner и номера ключей.', 56);
    }
} catch (Throwable $e) {
    error_log('[tes_estate context_pre] ' . $e->getMessage());
}
