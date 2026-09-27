# CHIM settings

`chim_settings.sql` is applied by `install.sh` on every run (idempotent fixes).

`prompt_head_ru.txt` is the tuned Russian `PROMPT_HEAD`. It is **not** applied
automatically, so edits made in the CHIM UI are kept. To apply it:

```bash
psql -U dwemer -d dwemer -c "\set head \`cat prompt_head_ru.txt\`" \
  -c "UPDATE general_settings SET value = :'head' WHERE id = 'PROMPT_HEAD';"
```

Profile flags used with it (Default Profile metadata): `DYNAMIC_PROFILE_ENABLED`,
`AUTO_DIARY_ENABLED`, `LATEST_DIARY_CONTEXT_ENABLED`, `SHORT_TERM_MEMORY_ENABLED`,
`MIDDLE_TERM_MEMORY_ENABLED`, `TIME_AWARENESS`, `LLM_FALLBACK_ENABLED` = true,
and a Russian `DIARY_PROMPT`.

Other preferences set on this install (CHIM UI / `general_settings`, not
auto-applied): `CHIM_AI_QUEST_PROGRESSION`, `POWER_AWARENESS_ENABLED`,
`PROMPT_TIMESTAMP`, `SHORTER_NEARBY_ITEM_LIST` = true,
`RELATIONSHIP_UPDATE_CHANCE` = 100, `CORE_CONNECTOR_BGL` = the cheap background
connector; profile `QUEST_COMMENT` = true (30%), `MAX_WORDS_LIMIT` = 50.

`narrator_ru.sql` enables the narrator as a Russian game master (not auto-applied):
`psql -U dwemer -d dwemer -f narrator_ru.sql`.
