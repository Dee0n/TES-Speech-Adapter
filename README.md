# TES Speech Adapter

**Lore-aware Russian speech recognition for Skyrim AI mods (CHIM / DwemerDistro).**

[Читать по-русски →](README.ru.md)

Stock speech-to-text does not know Tamriel. Say *«Алвор»* and the recognizer hears
*«Алла»*; say *«Аванчнзел»* and you get *«аванчный зел»*. This project turns the
DwemerDistro LocalWhisper service into a TES-aware recognizer that knows every
NPC, city, dungeon, spell and artifact of your actual playthrough — in Russian.

**The name is historical.** `server/remote_faster_whisper.py` can run either
engine: real faster-whisper, or `engine: gigaam` (Sber's GigaAM v3, over HTTP,
port 8026) — the config this repo installs for Russian (`config-Large-GPU-RU*.yaml`)
selects GigaAM. Either way, the recognizer sits behind CHIM's existing "Local
Whisper" STT slot, and every feature below (hotwords, lexicon, fuzzy
correction) applies to whichever engine is producing the raw transcript.

## What it does

| Layer | What happens |
|---|---|
| **Russian model** | Whisper engine: swaps the English base model for [`bzikst/faster-whisper-large-v3-russian`](https://huggingface.co/bzikst/faster-whisper-large-v3-russian-int8) — large-v3 fine-tuned on Russian speech. GigaAM engine: Sber's own Russian model, run as a separate service this installer also sets up (`[8/10]` in `install.sh`) |
| **Contextual hotwords** | The CHIM server sends the NPCs *physically around you right now* (from the game event log) plus your recently met NPCs to the decoder on every request (Whisper: real hotwords; GigaAM: post-recognition nearby-name matching) — your dialogue partner is always in the dictionary |
| **Full game lexicon** | A bundled extractor parses the `*_russian.strings` inside the game's BSA archives (no xEdit needed) into a dictionary of **14 000+ proper names** — NPCs, locations, items, spells, books, quests |
| **Fuzzy post-correction** | RapidFuzz-backed Levenshtein matching snaps near-misses to real names (*«Финдал» → «Фендал»*), understands Russian case endings so *«Лидию»* is **not** flattened to *«Лидия»*, and merges names the ASR split in two (*«аванчный зел» → «Аванчнзел»*) |
| **XTTS punctuation fix** | XTTS v2 sometimes vocalizes stray trailing dots as a foreign word ("ponte") when speaking Russian — the TTS connector patch normalizes punctuation before synthesis |
| **Watchdog** | Restarts the recognizer service (Whisper or GigaAM, whichever `engine:` selects) if it silently dies |

The result on real audio (synthesized with the game's own XTTS voices and fed
through the full game pipeline):

> Скажи **Балгруфу**, что мы нашли откос **Крегвеллоу** возле **Ривервуда**. **Фендал** и **Оргнар** уже там.
> Мы спускались в **Аванчнзел**, а потом навестили **Авентуса Аретино** в **Виндхельме**.

Warm-request latency: **~0.4 s** for a 3.5 s utterance on an RTX 5070 Ti
(int8 model), including all post-processing.

## Requirements

- [CHIM](https://www.nexusmods.com/skyrimspecialedition/mods/126330) with DwemerDistro installed (WSL2), **LocalWhisper** and **CUDA** components
- NVIDIA GPU with ~2 GB free VRAM for the int8 model (float16 config included too)
- Russian Skyrim localization (for the BSA lexicon extraction)

RTX 50xx (Blackwell) owners: the distro ships ctranslate2 4.4 which **hangs
forever** on sm_120 GPUs. The installer upgrades it to ≥4.6 and adds the
missing CUDA 12 cuBLAS — this alone fixes LocalWhisper on 50-series cards.

## Install

```
wsl -d DwemerAI4Skyrim3 -- bash /mnt/<drive>/path/to/TES-Speech-Adapter/install.sh "/mnt/<drive>/path/to/Skyrim/Data"
```

The second argument (your Skyrim `Data` folder, as seen from WSL) is optional —
it builds the full 14k-name lexicon from your actual load order's BSA archives.

Then in the CHIM web UI: **Configuration → STT → Local Whisper**
(URL `http://127.0.0.1:9876/api/v0/transcribe`).

**Re-run `install.sh` after every CHIM update** — updates git-reset the patched
files. Your configs, lexicons and the watchdog survive updates untouched.

### Verify

```bash
wsl -d DwemerAI4Skyrim3 -- curl -s -X POST \
  -F "audio_file=@/path/to/any.wav" http://127.0.0.1:9876/api/v0/transcribe
```

## Extending the dictionary

- `HerikaServer/stt/tes_lexicon_ru.txt` — curated lore terms, one line per
  entry (comma-separated allowed). Add mod NPCs, custom locations, anything.
- Per-playthrough names need no maintenance: they are read live from the CHIM
  database (met NPCs, discovered locations, factions).
- Point-fix stubborn words with regex `transformations` in the active
  `config-*.yaml`.

## Roadmap

- ESP record parser: names from mod plugins that don't ship `.strings`
- pymorphy3-based morphology instead of the case-ending heuristic
- Phonetic normalization tables (acoustic confusions beyond edit distance)
- Standalone adapter service usable with any STT backend (Parakeet, Qwen-ASR, …)

## Credits

- [Dwemer Dynamics](https://dwemerdynamics.hostwiki.io/) — CHIM / HerikaServer / DwemerDistro
- [Joshua M. Boniface](https://github.com/joshuaboniface/remote-faster-whisper) — Remote Faster Whisper (GPLv3)
- [bzikst](https://huggingface.co/bzikst) — Russian faster-whisper large-v3 conversion (based on [antony66](https://huggingface.co/antony66/whisper-large-v3-russian)'s fine-tune)
- [SYSTRAN faster-whisper](https://github.com/SYSTRAN/faster-whisper) and [RapidFuzz](https://github.com/rapidfuzz/RapidFuzz)

## License

GPLv3 — this repository contains a modified version of Remote Faster Whisper
(GPLv3). See [LICENSE](LICENSE).
