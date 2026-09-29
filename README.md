# FuturePresent

[Читать по-русски →](README.ru.md)

This is my Skyrim setup: Skyrim SE 1.5.97, heavily modded (Requiem, RFAD_SE
profile), running [CHIM](https://www.nexusmods.com/skyrimspecialedition/mods/126330)
on DwemerDistro so NPCs talk with an LLM instead of vanilla dialogue trees.
This repo is everything I built on top of it, in Russian, so I can talk to
the game out loud and have a Narrator who can actually change the world, not
just describe it.

It grew from "fix the speech recognition" into two separate things living in
one repo. Both only make sense together with CHIM installed; neither is a
general-purpose product.

## Part 1: hearing Russian properly

Stock speech-to-text doesn't know Tamriel. Say *«Алвор»* and it hears
*«Алла»*; say *«Аванчнзел»* and you get *«аванчный зел»*. This part swaps in
a Russian model, feeds it the names of whoever is actually near you right
now (pulled live from CHIM's own event log), and cleans up the result
afterwards — case endings, split names, near-misses against a 14k-name
dictionary built straight from your load order's BSA archives.

The service is still called `remote_faster_whisper.py` and sits in CHIM's
"Local Whisper" slot, but it doesn't have to run Whisper: set
`engine: gigaam` in its config and it proxies to Sber's GigaAM v3 instead
(that's what the Russian config this repo installs actually uses). Same
hotwords, same lexicon, same cleanup, whichever engine produced the raw
text.

There's also a small TTS fix for XTTS mispronouncing trailing punctuation in
Russian.

Example, from real synthesized audio through the whole pipeline:

> Скажи **Балгруфу**, что мы нашли откос **Крегвеллоу** возле **Ривервуда**. **Фендал** и **Оргнар** уже там.
> Мы спускались в **Аванчнзел**, а потом навестили **Авентуса Аретино** в **Виндхельме**.

## Part 2: a Narrator that can actually do things

CHIM's Narrator normally just talks. The `ext/` plugins here (loaded by
CHIM without touching its own code) let it run real console commands
through the SKSE plugin, and — this is the part that matters — let it know
whether they worked, instead of confidently describing a result it never
checked.

What that adds up to, roughly:

- A journal of what the Narrator actually did last, with real success or
  failure, shown back to it before its next line — no more "he's alive
  again" when the resurrect silently failed.
- A validator in front of the console channel: an allow-list of commands
  instead of a block-list, ID lookups against an index built from your own
  load order (161k records — NPCs, items, cells, spells, quests — not
  guessed FormIDs), spawn caps, repeat suppression.
- Commands for the things that come up in an actual playthrough: heal
  someone properly, marry two NPCs (with both of them actually remembering
  it), give someone a lasting memory, change what they do all day, give
  away a house or a horse for real, spread a rumor through a hold, rewrite
  a character's personality and have their relationships update to match.
- An autosave before anything hard to undo.

None of this was designed up front — it's a log of fixing whatever broke
each session, in `docs/applied-log.md`, including the things I got wrong the
first time and the dead ends (a couple of god-mode ideas turned out to need
Papyrus source that just doesn't exist for some mods; that's written down
too, not swept under the rug).

## Installing

```
wsl -d DwemerAI4Skyrim3 -- bash /mnt/<drive>/path/to/this/repo/install.sh "/mnt/<drive>/path/to/Skyrim/Data"
```

The Skyrim `Data` path is optional; it builds the full lexicon from your
actual load order. Re-run `install.sh` after every CHIM update — CHIM
updates reset the patched core files, and the installer re-applies them and
copies the `ext/` plugins back in.

After install, in the CHIM web UI: **Configuration → STT → Local Whisper**,
URL `http://127.0.0.1:9876/api/v0/transcribe`.

```bash
# quick check the speech side is alive
wsl -d DwemerAI4Skyrim3 -- curl -s -X POST \
  -F "audio_file=@/path/to/any.wav" http://127.0.0.1:9876/api/v0/transcribe
```

`php tools/test_ext.php --write` runs a real regression check on the god
plugins against a throwaway test NPC — worth running after any change here
or after a CHIM update, before trusting it in a real playthrough.

## Extending the dictionary

- `HerikaServer/stt/tes_lexicon_ru.txt` — one lore term per line, for
  anything the automatic extraction missed.
- Names from NPCs you've actually met, or locations you've found, need no
  maintenance — they come live from CHIM's own database.
- Stubborn mispronunciations can be patched with regex `transformations` in
  the active `config-*.yaml`.

## What's not done

- ESP-only mod content (plugins that don't ship `.strings`) isn't in the
  lexicon yet.
- Real morphology (pymorphy3) instead of the current case-ending heuristic.
- The god console still can't move a quest stage without the player
  confirming — on purpose, for now.
- No real answer yet for the Narrator learning a command's result within
  the same reply instead of the next one; it would mean blocking the
  request on the game, which isn't safe to do casually.

## Credits

- [Dwemer Dynamics](https://dwemerdynamics.hostwiki.io/) — CHIM / HerikaServer / DwemerDistro
- [Joshua M. Boniface](https://github.com/joshuaboniface/remote-faster-whisper) — Remote Faster Whisper (GPLv3), the base this speech service is built on
- [bzikst](https://huggingface.co/bzikst) — Russian faster-whisper large-v3 conversion (based on [antony66](https://huggingface.co/antony66/whisper-large-v3-russian)'s fine-tune)
- [SYSTRAN faster-whisper](https://github.com/SYSTRAN/faster-whisper) and [RapidFuzz](https://github.com/rapidfuzz/RapidFuzz)

## License

GPLv3 — this repository contains a modified version of Remote Faster Whisper
(GPLv3). See [LICENSE](LICENSE).
