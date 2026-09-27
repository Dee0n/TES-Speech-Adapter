"""Generate Russian aliases for CHIM's Oghma lore catalog.

Oghma matches lore topics by name/alias in the player's line. The catalog is
English ("thalmor"), so a Russian player ("Талмор", "о Талморе") never hits a
single article. This asks a cheap LLM for the official Russian-localization
name of each topic plus its common case forms and writes them to a TSV:
    topic <TAB> alias1, alias2, ...
Generic concepts (war, food) are skipped so ordinary speech does not pull in
articles on every line.

Usage: oghma_ru_aliases.py <out.tsv>   (resumable; reads the API key from CHIM)
"""
import json
import os
import subprocess
import sys
import time
import urllib.request
from concurrent.futures import ThreadPoolExecutor

MODEL = "deepseek/deepseek-v4-flash"
BATCH = 25
WORKERS = 6

PROMPT = """Ты — эксперт по русской локализации The Elder Scrolls V: Skyrim.
Для каждой темы из лор-каталога (ключ + начало описания) дай русские названия, \
которыми игрок назовёт эту тему в речи: официальное название из русской локализации Skyrim \
(Thalmor -> Талмор, Whiterun -> Вайтран, Ulfric Stormcloak -> Ульфрик Буревестник, \
Sovngarde -> Совнгард), короткие формы (Ульфрик), и падежные формы (родительный, дательный, \
творительный, предложный): Талмора, Талмору, Талмором, Талморе.
Правила:
- Только имена собственные и специфичные термины лора (персонажи, места, фракции, боги, расы, \
существа, артефакты, книги, события).
- Если тема — общее понятие, которое обычно звучит в обычной речи (war, food, love, money, \
horse, weather и т.п.), верни для неё пустой список.
- Не выдумывай: если не знаешь официального русского названия, дай точную транслитерацию.
- Каждое название — не короче 4 букв.
Ответ — только JSON-объект {"ключ": ["название", ...], ...} для всех ключей."""


def psql(sql):
    return subprocess.run(["psql", "--no-password", "-U", "dwemer", "-d", "dwemer", "-t", "-A",
                           "-F", "\x1f", "-R", "\x1e", "-c", sql],
                          capture_output=True, text=True, check=True).stdout


def ask(key, items):
    body = {"model": MODEL, "temperature": 0.2, "max_tokens": 6000,
            "response_format": {"type": "json_object"},
            "reasoning": {"enabled": False},
            "messages": [{"role": "system", "content": PROMPT},
                         {"role": "user", "content": json.dumps(items, ensure_ascii=False)}]}
    req = urllib.request.Request("https://openrouter.ai/api/v1/chat/completions",
                                 data=json.dumps(body).encode(), method="POST",
                                 headers={"Authorization": f"Bearer {key}", "Content-Type": "application/json"})
    text = json.load(urllib.request.urlopen(req, timeout=180))["choices"][0]["message"]["content"]
    text = text.strip().removeprefix("```json").removeprefix("```").removesuffix("```")
    return json.loads(text)


def main(out_path):
    key = psql("SELECT api_key FROM core_api_badge WHERE id=1").strip("\x1e\n ")
    rows = [r.split("\x1f") for r in psql("SELECT topic, left(regexp_replace(coalesce(topic_desc,''),'\\s+',' ','g'),160) FROM oghma ORDER BY topic").split("\x1e") if r.strip()]
    done = set()
    if os.path.exists(out_path):
        done = {line.split("\t", 1)[0] for line in open(out_path, encoding="utf-8")}
    todo = [r for r in rows if r[0] not in done]
    print(f"topics: {len(rows)}, to do: {len(todo)}", flush=True)
    def run(batch):
        items = {t: d for t, d in batch}
        for attempt in range(3):
            try:
                return batch, ask(key, items)
            except Exception as e:
                print(f"  retry {attempt + 1}: {e}", flush=True)
                time.sleep(3)
        return batch, None

    batches = [todo[i:i + BATCH] for i in range(0, len(todo), BATCH)]
    n = 0
    with open(out_path, "a", encoding="utf-8") as fh, ThreadPoolExecutor(WORKERS) as pool:
        for batch, res in pool.map(run, batches):
            n += len(batch)
            if res is None:
                continue  # resumable: missing topics are retried on the next run
            for t, _ in batch:
                aliases = [a.strip() for a in res.get(t, []) if isinstance(a, str) and len(a.strip()) >= 4]
                aliases = list(dict.fromkeys(a for a in aliases if any("а" <= c.lower() <= "я" or c.lower() == "ё" for c in a)))
                fh.write(f"{t}\t{', '.join(aliases)}\n")
            fh.flush()
            print(f"  {n}/{len(todo)}", flush=True)

if __name__ == "__main__":
    main(sys.argv[1])
