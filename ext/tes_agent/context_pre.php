<?php
/*
 * tes_agent: one instruction for the Narrator - delegate multi-step goals to the agent -
 * plus the state of the running task, so it does not start the same goal twice.
 * Loaded inside a function by main.php: $GLOBALS only, never break the dialogue.
 */

try {
    $tesAgentIsNarrator = in_array(strval($GLOBALS['gameRequest'][0] ?? ''), ['narrator_inputtext', 'narration', 'narrator_welcome', 'narrator_quest_comment'], true)
        || strval($_GET['profile'] ?? '') === md5('The Narrator')
        || strval($GLOBALS['HERIKA_NAME'] ?? '') === 'The Narrator';
    if ($tesAgentIsNarrator && isset($GLOBALS['db']) && function_exists('chimRegisterPromptInjection')) {
        require_once __DIR__ . '/lib.php';
        $line = "БОЛЬШИЕ ЦЕЛИ: если игрок просит то, что требует нескольких шагов или поиска (\"сделай меня богом воровства\", "
            . "\"подготовь Лидию к бою с драконом\", \"хочу этот дом и всё в нём\"), НЕ собирай команды сам. Дай GodCommand с target "
            . "\"goal: <цель словами игрока, с уточнениями>\" и скажи коротко, что берёшься. Помощник сам исследует мир, выполнит, "
            . "проверит, и потом ты сообщишь итог. Одиночные простые команды делай как раньше. "
            . "ВОПРОСЫ о мире, на которые ты не знаешь точного ответа (\"кто меня ненавидит в городе?\", \"что у меня по заданиям?\", "
            . "\"какой у Лидии уровень?\", \"какая броня лучше?\") — GodCommand с target \"ask: <вопрос>\": помощник только выяснит и ничего не изменит.";
        $running = tesAgentRunningTask();
        if ($running) {
            $line .= " Сейчас уже идёт задача: «{$running['goal']}» (шаг {$running['steps']}) — не запускай её снова.";
        }
        chimRegisterPromptInjection('prompt_bottom', 'tes_agent', $line, 55);
    }
} catch (Throwable $e) {
    error_log('[tes_agent context_pre] ' . $e->getMessage());
}
