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
