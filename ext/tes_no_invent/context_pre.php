<?php
/*
 * tes_no_invent: a small, constant anti-hallucination instruction added to EVERY
 * character's prompt (Narrator and regular NPCs alike), not just the Narrator's own
 * turns like ext/tes_god_journal's hook.
 *
 * Why this exists (see docs/applied-log.md, 2026-09-29): Лилит Ткачиха confidently
 * invented a whole shared backstory ("мы предложили десять миллионов", "старушка
 * Фрида у фонтана") that never happened, and insisted it was real when challenged.
 * This is a known trait of fast/cheap ("flash"-tier) models trading groundedness for
 * speed - not something a code fix removes, but a short, explicit instruction is the
 * standard mitigation and costs only a couple dozen tokens per turn.
 *
 * Deliberately NOT gated on tesGodJournalIsNarratorTurn() (or any narrator check) -
 * this must apply to ordinary NPC dialogue too, which is where the actual incident
 * happened.
 */

if (isset($GLOBALS["db"]) && function_exists('chimRegisterPromptInjection')) {
    chimRegisterPromptInjection(
        'prompt_bottom',
        'tes_no_invent',
        'Не выдумывай события, которых не было. Если ты не уверен, что что-то действительно произошло между тобой и собеседником, не утверждай это как факт и не ссылайся на несуществующие общие воспоминания - опирайся только на реальную историю разговора и память, которые тебе действительно предоставлены. Реплики под «Happened Recently» и «Moments Ago» прозвучали минуты назад, в этой же сцене - не называй их вчерашними или давними.',
        60
    );
}
