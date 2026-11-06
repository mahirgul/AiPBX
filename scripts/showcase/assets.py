# python3 assets.py <assets.tgz> <out dir> <lang>...: the portal's assets under each language, with
# the portal paths in CSS and JS rewritten under /demo/<lang> exactly as export.mjs does for pages.
import json, os, re, shutil, sys, tarfile, tempfile
tgz, out, langs = sys.argv[1], sys.argv[2], sys.argv[3:]
src = tempfile.mkdtemp()
with tarfile.open(tgz) as t:
    t.extractall(src, filter='data')
for lang in langs:
    segs = json.load(open(os.path.join(out, lang, 'segments.json')))
    rx = re.compile(r'''(["'`(=,]\s*)/(%s)(?=[/"'`?#)\s,]|$)''' % '|'.join(map(re.escape, segs)))
    dst = os.path.join(out, lang, 'assets')
    shutil.rmtree(dst, ignore_errors=True)
    shutil.copytree(os.path.join(src, 'assets'), dst)
    for root, _, files in os.walk(dst):
        for f in files:
            if f.endswith(('.css', '.js')):
                p = os.path.join(root, f)
                s = open(p, encoding='utf-8', errors='surrogateescape').read()
                n = rx.sub(lambda m: m.group(1) + '/demo/' + lang + '/' + m.group(2), s)
                if n != s:
                    open(p, 'w', encoding='utf-8', errors='surrogateescape').write(n)
    print(lang, 'assets ok')
