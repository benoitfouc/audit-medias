"""Local packaging regressions; no network or environment modifications."""
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import zipfile

root = Path(__file__).resolve().parents[1]
version = re.search(r'^ \* Version: ([0-9.]+)$', (root/'audit-medias.php').read_text(), re.M)[1]
archive = root/'dist'/f'audit-medias-v{version}.zip'
manifest = json.loads((root/'dist/audit-medias-update.json').read_text())
assert manifest['sha256'] == hashlib.sha256(archive.read_bytes()).hexdigest()
assert manifest['size'] == archive.stat().st_size
assert manifest['version'] == version and manifest['tag'] == 'v'+version
with zipfile.ZipFile(archive) as z:
    assert z.testzip() is None
    for n in z.namelist():
        assert n.startswith('audit-medias/') and '..' not in n.split('/')
        rel = n[len('audit-medias/'):]
        assert z.read(n) == (root/rel).read_bytes()
        assert not rel.startswith(('tests/', 'tools/', '.github/'))
        assert not rel.endswith(('.ttf','.woff','.woff2','.otf'))
    assert z.read('audit-medias/includes/class-amf-static-manifest.php') == (root/'includes/class-amf-static-manifest.php').read_bytes()
for rel, digest in json.loads((root/'tests/protected-files.json').read_text()).items():
    assert hashlib.sha256((root/rel).read_bytes()).hexdigest() == digest, 'Regression: '+rel
with zipfile.ZipFile(root/'dist/benfou-transition-audit-medias-v1.0.0.zip') as z:
    assert z.testzip() is None
    assert len(z.namelist()) == 2
    assert z.read('benfou-amf-transition/class-amf-static-manifest.php') == (root/'includes/class-amf-static-manifest.php').read_bytes()
    assert z.read('benfou-amf-transition/benfou-amf-transition.php') == (root/'tools/update-transition/benfou-amf-transition.php').read_bytes()
before = archive.read_bytes()
spec=importlib.util.spec_from_file_location('b',root/'tools/build-package.py')
b=importlib.util.module_from_spec(spec);spec.loader.exec_module(b);b.build('v'+version)
assert archive.read_bytes() == before, 'Nonreproducible ZIP'
print('PASS manifest, root folder, source identity, no fonts, engine preservation, identical bridge client and reproducible ZIP')
