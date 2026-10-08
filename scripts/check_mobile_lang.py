#!/usr/bin/env python3
"""Check the mobile app translations against English.

usage: python3 scripts/check_mobile_lang.py [CODE ...]   (no code = every language)

Android: values-<code>/strings.xml must have every translatable <string> of
values/strings.xml (same names, same %s / %1$s / %d placeholders), valid XML,
and apostrophes / double quotes escaped (\\' and \\").
iOS: the English text is the key (App/Localization.swift L()). Every L("...")
key in the Swift code must be in <code>.lproj/Localizable.strings with the
same %@ / %d placeholders; InfoPlist.strings must have the keys of
en.lproj/InfoPlist.strings.
A language that has only one of the Android / iOS folders is an error too.
Exit code 1 when anything is wrong.
"""
import glob, os, re, sys
import xml.etree.ElementTree as ET

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
RES = os.path.join(ROOT, 'android/app/src/main/res')
IOS = os.path.join(ROOT, 'ios/AiPBX/Resources')
# Android resource folders that are not languages.
NOT_LANGS = {'night', 'land', 'port'}


def placeholders(v):
    return sorted(re.sub(r'%\d+\$', '%', m) for m in re.findall(r'%(?:\d+\$)?[sdf@]', v))


def android_strings(path):
    out = {}
    for el in ET.parse(path).getroot().iter('string'):
        if el.get('translatable') != 'false':
            out[el.get('name')] = ''.join(el.itertext())
    return out


def ios_strings(path):
    txt = re.sub(r'/\*.*?\*/', '', open(path, encoding='utf-8').read(), flags=re.S)
    out = {}
    for line in txt.splitlines():
        line = line.strip()
        if not line:
            continue
        m = re.fullmatch(r'"((?:[^"\\]|\\.)*)"\s*=\s*"((?:[^"\\]|\\.)*)";', line)
        if not m:
            raise ValueError(f'unreadable line: {line[:80]}')
        out[m.group(1)] = m.group(2)
    return out


def swift_keys():
    keys = set()
    for f in glob.glob(os.path.join(ROOT, 'ios/AiPBX/**/*.swift'), recursive=True):
        keys |= set(re.findall(r'\bL\("((?:[^"\\]|\\.)*)"', open(f, encoding='utf-8').read()))
    return keys


def check_android(code, en, errors):
    path = os.path.join(RES, f'values-{code}/strings.xml')
    if not os.path.exists(path):
        errors.append(f'android: {os.path.relpath(path, ROOT)} is missing')
        return
    try:
        tr = android_strings(path)
    except ET.ParseError as e:
        errors.append(f'android: invalid XML: {e}')
        return
    for name, v in en.items():
        if name not in tr:
            errors.append(f'android: missing <string name="{name}">')
        elif placeholders(v) != placeholders(tr[name]):
            errors.append(f'android: {name}: placeholders {placeholders(tr[name])}, English has {placeholders(v)}')
    for name in tr:
        if name not in en:
            errors.append(f'android: {name} is not in values/strings.xml (or is translatable="false")')
    raw = open(path, encoding='utf-8').read()
    for m in re.finditer(r'<string name="([^"]+)"[^>]*>(.*?)</string>', raw, re.S):
        body = m.group(2)
        quoted = body.startswith('"') and body.endswith('"')
        if not quoted and re.search(r"(?<!\\)'", body):
            errors.append(f"android: {m.group(1)}: unescaped apostrophe (write \\')")
        if not quoted and re.search(r'(?<!\\)"', body):
            errors.append(f'android: {m.group(1)}: unescaped double quote (write \\")')


def check_ios(code, keys, plist_keys, errors):
    for fname, ref in (('Localizable.strings', keys), ('InfoPlist.strings', plist_keys)):
        path = os.path.join(IOS, f'{code}.lproj', fname)
        if not os.path.exists(path):
            errors.append(f'ios: {os.path.relpath(path, ROOT)} is missing')
            continue
        try:
            tr = ios_strings(path)
        except ValueError as e:
            errors.append(f'ios {fname}: {e}')
            continue
        for k in ref:
            if k not in tr:
                errors.append(f'ios {fname}: missing "{k[:60]}"')
            elif placeholders(k) != placeholders(tr[k]):
                errors.append(f'ios {fname}: "{k[:60]}": placeholders differ')
        for k in tr:
            if k not in ref:
                errors.append(f'ios {fname}: "{k[:60]}" is not used in the code')


def main():
    android_langs = {os.path.basename(d)[len('values-'):] for d in glob.glob(os.path.join(RES, 'values-*'))}
    android_langs -= NOT_LANGS
    ios_langs = {os.path.basename(d)[:-len('.lproj')] for d in glob.glob(os.path.join(IOS, '*.lproj'))} - {'en', 'Base'}
    codes = sys.argv[1:] or sorted(android_langs | ios_langs)

    en = android_strings(os.path.join(RES, 'values/strings.xml'))
    keys = swift_keys()
    plist_keys = set(ios_strings(os.path.join(IOS, 'en.lproj/InfoPlist.strings')))

    failed = 0
    for code in codes:
        errors = []
        check_android(code, en, errors)
        check_ios(code, keys, plist_keys, errors)
        print(f'{code}: ' + ('OK' if not errors else f'{len(errors)} problem(s)'))
        for e in errors[:40]:
            print('  ' + e)
        if len(errors) > 40:
            print(f'  ... and {len(errors) - 40} more')
        failed += bool(errors)
    print(f'{len(codes)} language(s) checked, {failed} with problems '
          f'(Android {len(en)} strings, iOS {len(keys)} keys).')
    sys.exit(1 if failed else 0)


main()
