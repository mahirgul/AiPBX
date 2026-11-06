# python3 fixup.py <out dir> <lang>...: small hand edits after the export, where the test server
# cannot show a busy office: extensions 1000-1007 online (1008 stays offline) on the Extensions
# page and the dashboard.
import os, re, sys
out, langs = sys.argv[1], sys.argv[2:]
for lang in langs:
    p = os.path.join(out, lang, 'extensions', 'index.html')
    s = open(p, encoding='utf-8').read()
    online = re.search(r'<span class="badge badge-success">[^<]+</span>', s).group(0)
    n = 0
    def swap(m):
        global n
        n += 1
        return online if n <= 7 else m.group(0)
    s = re.sub(r'<span class="badge badge-warning">[^<]+</span>', swap, s)
    open(p, 'w', encoding='utf-8').write(s)
    d = os.path.join(out, lang, 'dashboard', 'index.html')
    s = open(d, encoding='utf-8').read()
    s = s.replace('u-primary">0 <span class="u-fs-13 u-fw-600 u-muted">/ 10', 'u-primary">8 <span class="u-fs-13 u-fw-600 u-muted">/ 10', 1)
    open(d, 'w', encoding='utf-8').write(s)
    print(lang, 'fixed')
