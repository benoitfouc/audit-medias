"""Static/client-rendered regressions for the 2.3.8 admin-only changes."""
from pathlib import Path
import re
import subprocess

root = Path(__file__).resolve().parents[1]
assertions = 0


def check(condition, label):
    global assertions
    if not condition:
        raise AssertionError(label)
    assertions += 1
    print('PASS ' + label)


def fixture(query):
    return subprocess.check_output(
        ['php', str(root / 'tests/render-fixture.php'), query],
        text=True,
        encoding='utf-8',
    )


brand = (root / 'assets/admin-brand.css').read_text(encoding='utf-8')
client = (root / 'assets/admin-client.css').read_text(encoding='utf-8')
admin = (root / 'includes/class-amf-admin.php').read_text(encoding='utf-8')
main = (root / 'audit-medias.php').read_text(encoding='utf-8')

check('Version: 2.3.8' in main and "define( 'AMF_VERSION', '2.3.8' );" in main,
      'header and constant use version 2.3.8')
check(re.search(r'\.amf-wrap \.amf-client-header\{[^}]*background:#ebebeb', brand) is not None,
      'brand header has the requested grey background')
check(re.search(r'\.amf-wrap \.amf-client-header h1\{color:#171717!important', brand) is not None,
      'brand title uses high-contrast text')
check(re.search(r'\.amf-wrap \.amf-client-header \.amf-byline\{[^}]*color:#555!important', brand) is not None,
      'brand subtitle uses high-contrast text')
check('background:#181818;border:1px solid #181818' not in brand,
      'former dark header is removed')
check('amf-advanced-filters' not in admin,
      'unnecessary advanced-filter section removed')

for mode, expected in (
    ('tab=files', ('usage', 'assoc')),
    ('tab=files&usage=unused&assoc=unassociated', ('usage', 'assoc')),
    ('tab=files&no_woo=1', ('usage', 'assoc')),
    ('tab=media', ('usage', 'state')),
    ('tab=errors', ('severity',)),
):
    page = fixture(mode)
    match = re.search(r'<form\b[^>]*\bamf-client-filters\b[^>]*>.*?</form>', page, flags=re.S)
    check(match is not None, 'filter form present in ' + mode)
    form = match.group()
    selects = tuple(re.findall(r'<select name="([^"]+)"', form))
    check(selects == expected, f'{mode}: expected inline controls {expected}')
    check('<details' not in form and 'Filtres avancés' not in form,
          mode + ': no separate disclosure for filters')
    check(form.index('name="' + expected[0] + '"') < form.index('>Filtrer</button>'),
          mode + ': select controls precede filter action')
    if len(expected) == 2:
        check(form.index('name="' + expected[0] + '"') < form.index('name="' + expected[1] + '"'),
              mode + ': controls keep order')

page = fixture('tab=files&usage=unused&assoc=unassociated')
check(re.search(r'<option value="unused"\s+selected=', page) is not None,
      'active usage remains selected')
check(re.search(r'<option value="unassociated"\s+selected=', page) is not None,
      'active association remains selected')
check(page.count('class="amf-bulk-actions"') == 2,
      'both bulk-action areas retained')
print(f'UI 2.3.8: {assertions} assertions passed')
