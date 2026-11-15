# python3 fixup.py <out dir> <lang>...: small hand edits after the export, where the test server
# cannot show a busy office: extensions 1000-1007 online (1008 stays offline) on the Extensions
# page and the dashboard, and active trunks/registered devices on Registrations.
import os, re, sys

REG_LABELS = {
    'en': ('Registered', 'Answering'),
    'tr': ('Kayıtlı', 'Yanıt veriyor'),
    'de': ('Registriert', 'Antwortet'),
}
TRUNK_RTT = {
    'carrier_main': '6.4 ms',
    'carrier_backup': '4.1 ms',
    'sbc_tls': '11.2 ms',
}
DEV_RTT = ['2.1 ms', '4.8 ms', '1.9 ms', '5.3 ms', '2.7 ms', '3.4 ms', '4.5 ms', '2.2 ms', '3.9 ms', '5.1 ms', '3.0 ms']


def fix_registrations(path, lang):
    if not os.path.exists(path):
        return
    s = open(path, encoding='utf-8').read()
    lbl_reg, lbl_ans = REG_LABELS.get(lang, REG_LABELS['en'])
    dev_i = 0

    def fix_row(m):
        nonlocal dev_i
        row = m.group(0)
        for tname, rtt in TRUNK_RTT.items():
            if f'<code class="u-fs-11">{tname}</code>' in row:
                row = re.sub(
                    r'<td><span class="badge badge-danger" title="[^"]*">[^<]+</span></td>(\s*<td><span class="badge badge-(?:danger|info)" title="[^"]*">[^<]+</span></td>)',
                    f'<td><span class="badge badge-success" title="Registered">{lbl_reg}</span></td>\\g<1>',
                    row,
                    count=1,
                )
                row = re.sub(
                    r'<td><span class="badge badge-(?:danger|info)" title="(?:Unavail|)">[^<]+</span></td>(\s*<td class="col-hide-mobile u-fs-12">)—(</td>)',
                    f'<td><span class="badge badge-success" title="Avail">{lbl_ans}</span></td>\\g<1>{rtt}\\g<2>',
                    row,
                    count=1,
                )
                return row
        if ('title="NonQual"' in row or '<span class="badge badge-info" title="">—</span>' in row) and 'badge-secondary' not in row:
            rtt = DEV_RTT[dev_i % len(DEV_RTT)]
            dev_i += 1
            row = re.sub(
                r'<span class="badge badge-info" title="(?:NonQual|)">[^<]+</span>',
                f'<span class="badge badge-success" title="Avail">{lbl_ans}</span>',
                row,
                count=1,
            )
            row = re.sub(
                r'(<td class="col-hide-mobile u-fs-12">)—(</td>\s*<td class="col-hide-mobile u-fs-12">[^—<]+</td>)',
                f'\\g<1>{rtt}\\g<2>',
                row,
                count=1,
            )
        return row

    s = re.sub(r'<tr>.*?</tr>', fix_row, s, flags=re.S)
    open(path, 'w', encoding='utf-8').write(s)


def fix_sip_traffic(lang_dir):
    # Upstream BaseController/layout shadows $file with the template path on sip-calls / sip-call.
    for sub in ('sip-calls', 'sip-call'):
        p = os.path.join(lang_dir, sub, 'index.html')
        if not os.path.exists(p):
            continue
        s = open(p, encoding='utf-8').read()
        for bad in (
            '/opt/aipbx/web/templates/views/sip_traffic/calls.php',
            '/opt/aipbx/web/templates/views/sip_traffic/call.php',
        ):
            s = s.replace(bad, 'sip-20261114-120000.pcap')
            s = s.replace(bad.replace('/', '%2F'), 'sip-20261114-120000.pcap')
        open(p, 'w', encoding='utf-8').write(s)


def main():
    out, langs = sys.argv[1], sys.argv[2:]
    for lang in langs:
        p = os.path.join(out, lang, 'extensions', 'index.html')
        s = open(p, encoding='utf-8').read()
        online = re.search(r'<span class="badge badge-success">[^<]+</span>', s).group(0)
        warnings = re.findall(r'<span class="badge badge-warning">[^<]+</span>', s)
        limit = max(0, len(warnings) - 1) if len(warnings) > 1 else 0
        n = 0

        def swap(m):
            nonlocal n
            n += 1
            return online if n <= limit else m.group(0)

        s = re.sub(r'<span class="badge badge-warning">[^<]+</span>', swap, s)
        open(p, 'w', encoding='utf-8').write(s)
        d = os.path.join(out, lang, 'dashboard', 'index.html')
        s = open(d, encoding='utf-8').read()
        s = s.replace('u-primary">0 <span class="u-fs-13 u-fw-600 u-muted">/ 10', 'u-primary">8 <span class="u-fs-13 u-fw-600 u-muted">/ 10', 1)
        open(d, 'w', encoding='utf-8').write(s)
        fix_registrations(os.path.join(out, lang, 'registrations', 'index.html'), lang)
        fix_sip_traffic(os.path.join(out, lang))
        print(lang, 'fixed')


if __name__ == '__main__':
    main()
