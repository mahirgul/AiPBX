#!/usr/bin/env python3
"""Translation workflow for the AiPBX portal (web/lang/*.php).
See docs/translating.md.

    scripts/i18n.py export SIZE                 # en.php -> chunks: <dir>/en_NN.txt  ("idx<TAB>key<TAB>json-string"; import also takes "idx<TAB>json-string")
    scripts/i18n.py export-missing CODE SIZE   # only the strings lang/<code>.php lacks: <dir>/<code>_missing_NN.txt
    scripts/i18n.py import CODE FILE            # translated chunk (same format) -> <dir>/<code>.json, validated
    scripts/i18n.py status CODE                 # how many strings are done, which chunks are missing
    scripts/i18n.py build CODE "Language name"  # <dir>/<code>.json -> web/lang/<code>.php (English key order)
    scripts/i18n.py check [CODE ...]            # web/lang/<code>.php against English (CI; no code = every language)

Validation on import: the index must exist, the placeholders (%s, %1$s, %d)
and the HTML tags must be the same as in English. "check" applies the same rules
to the language files themselves, e.g. to translations coming from Weblate
(docs/translating.md), and also reports keys English does not have.
"""
import json, os, re, subprocess, sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
WEB = os.path.join(ROOT, 'web')
# Work files (chunks, progress); not part of the repository.
DIR = os.environ.get('I18N_WORK_DIR', os.path.join(ROOT, '.i18n-work'))


def load_en():
    out = subprocess.run(['php', '-r', f'echo json_encode(require "{WEB}/lang/en.php", JSON_UNESCAPED_UNICODE);'],
                         capture_output=True, text=True, check=True).stdout
    return list(json.loads(out).items())


def load_db(code):
    """Progress of a language: the work file, else the existing lang/<code>.php."""
    path = f'{DIR}/{code}.json'
    if os.path.exists(path):
        return json.load(open(path, encoding='utf-8'))
    php = f'{WEB}/lang/{code}.php'
    if os.path.exists(php):
        out = subprocess.run(['php', '-r', f'echo json_encode(require "{php}", JSON_UNESCAPED_UNICODE);'],
                             capture_output=True, text=True, check=True).stdout
        return json.loads(out) or {}
    return {}


def placeholders(v):
    return sorted(re.sub(r'%\d+\$', '%', m) for m in re.findall(r'%(?:\d+\$)?[sd]', v))


def tags(v):
    return sorted(re.findall(r'</?([a-zA-Z][a-zA-Z0-9]*)\b', v))


def php_str(v):
    return "'" + v.replace('\\', '\\\\').replace("'", "\\'") + "'"


def check(en, codes):
    """Problems of web/lang/<code>.php against English; exit code 1 when any."""
    en_map = dict(en)
    if not codes:
        codes = sorted(f[:-4] for f in os.listdir(f'{WEB}/lang') if f.endswith('.php') and f != 'en.php')
    problems = 0
    for code in codes:
        php = f'{WEB}/lang/{code}.php'
        out = subprocess.run(['php', '-r', f'echo json_encode(require "{php}", JSON_UNESCAPED_UNICODE);'],
                             capture_output=True, text=True)
        try:
            db = json.loads(out.stdout)
            assert isinstance(db, dict)
        except Exception:
            print(f'{code}: lang/{code}.php does not return an array of texts {out.stderr.strip()[:200]}')
            problems += 1
            continue
        errors = []
        for key, val in db.items():
            if key not in en_map:
                errors.append(f'{key}: not in en.php')
            elif not isinstance(val, str):
                errors.append(f'{key}: not a text')
            elif placeholders(val) != placeholders(en_map[key]):
                errors.append(f'{key}: placeholders {placeholders(val)} != {placeholders(en_map[key])}')
            elif tags(val) != tags(en_map[key]):
                errors.append(f'{key}: HTML tags {tags(val)} != {tags(en_map[key])}')
        print(f'{code}: {len(db)}/{len(en)} strings, {len(errors)} problem(s)')
        for e in errors:
            print('  ' + e)
        problems += len(errors)
    sys.exit(1 if problems else 0)


def main():
    cmd = sys.argv[1] if len(sys.argv) > 1 else ''
    en = load_en()
    if cmd == 'check':
        check(en, sys.argv[2:])
    os.makedirs(DIR, exist_ok=True)
    if cmd == 'export':
        size = int(sys.argv[2])
        for c in range(0, len(en), size):
            with open(f'{DIR}/en_{c // size:02d}.txt', 'w', encoding='utf-8') as f:
                for i in range(c, min(c + size, len(en))):
                    f.write(f'{i}\t{en[i][0]}\t{json.dumps(en[i][1], ensure_ascii=False)}\n')
        print(f'{len(en)} strings in {(len(en) + size - 1) // size} chunks of {size} -> {DIR}')
    elif cmd == 'export-missing':
        code, size = sys.argv[2], int(sys.argv[3])
        db = load_db(code)
        todo = [i for i, (k, _) in enumerate(en) if k not in db]
        for c in range(0, len(todo), size):
            with open(f'{DIR}/{code}_missing_{c // size:02d}.txt', 'w', encoding='utf-8') as f:
                for i in todo[c:c + size]:
                    f.write(f'{i}\t{en[i][0]}\t{json.dumps(en[i][1], ensure_ascii=False)}\n')
        print(f'{code}: {len(todo)} missing strings in {(len(todo) + size - 1) // size} chunk(s) -> {DIR}')
    elif cmd == 'import':
        code, src = sys.argv[2], sys.argv[3]
        db_path = f'{DIR}/{code}.json'
        db = load_db(code)
        errors, n = [], 0
        for ln, line in enumerate(open(src, encoding='utf-8'), 1):
            line = line.rstrip('\n')
            if not line.strip():
                continue
            try:
                parts = line.split('\t')
                idx_s, val_s = parts[0], parts[-1]
                idx, val = int(idx_s), json.loads(val_s)
            except Exception as e:
                errors.append(f'line {ln}: unreadable ({e}): {line[:80]}')
                continue
            if not (0 <= idx < len(en)):
                errors.append(f'line {ln}: no string #{idx}')
                continue
            key, orig = en[idx]
            if placeholders(val) != placeholders(orig):
                errors.append(f'#{idx} {key}: placeholders {placeholders(val)} != {placeholders(orig)}')
                continue
            if tags(val) != tags(orig):
                errors.append(f'#{idx} {key}: HTML tags differ')
                continue
            db[key] = val
            n += 1
        json.dump(db, open(db_path, 'w', encoding='utf-8'), ensure_ascii=False, indent=0)
        print(f'{code}: imported {n}, rejected {len(errors)}, total {len(db)}/{len(en)}')
        for e in errors[:40]:
            print('  ' + e)
    elif cmd == 'status':
        code = sys.argv[2]
        db = load_db(code)
        missing = [i for i, (k, _) in enumerate(en) if k not in db]
        print(f'{code}: {len(db)}/{len(en)} done, {len(missing)} missing')
        if missing:
            print('missing indexes (first 30):', missing[:30])
    elif cmd == 'build':
        code, name = sys.argv[2], sys.argv[3]
        db = load_db(code)
        lines = ['<?php', '/**',
                 f' * {name} translation strings — same keys as en.php.',
                 ' * A key missing here shows the English text (config.php::t()).',
                 ' * Corrections are welcome: see docs/translating.md.', ' */', 'return [']
        for key, _ in en:
            if key in db:
                lines.append(f'    {php_str(key)} => {php_str(db[key])},')
        lines.append('];')
        path = f'{WEB}/lang/{code}.php'
        open(path, 'w', encoding='utf-8').write('\n'.join(lines) + '\n')
        r = subprocess.run(['php', '-l', path], capture_output=True, text=True)
        print(r.stdout.strip(), f'({len(db)} strings)')
    else:
        sys.exit(__doc__)


main()
