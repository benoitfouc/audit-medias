"""Build static metadata and the temporary bridge. Network-free and deterministic."""
import argparse
import hashlib
import importlib.util
import json
import re
from pathlib import Path
import zipfile

ROOT = Path(__file__).resolve().parents[1]
NAME = 'audit-medias-update.json'


def build(tag):
    if not re.fullmatch(r'v(?:0|[1-9]\d{0,4})\.(?:0|[1-9]\d{0,4})\.(?:0|[1-9]\d{0,4})', tag):
        raise ValueError('Invalid stable tag')
    spec = importlib.util.spec_from_file_location('builder', ROOT / 'tools/build-package.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    package = module.build(tag)
    php = (ROOT / 'audit-medias.php').read_text(encoding='utf-8')
    requires = re.search(r'^ \* Requires at least: ([0-9.]+)$', php, re.M)[1]
    requires_php = re.search(r'^ \* Requires PHP: ([0-9.]+)$', php, re.M)[1]
    notes = ROOT / 'docs' / ('RELEASE-' + tag[1:] + '.md')
    description = notes.read_text(encoding='utf-8') if notes.exists() else 'Audit Medias ' + tag
    data = dict(schema=1, slug='audit-medias', channel='stable', version=tag[1:], tag=tag,
                package=f'https://github.com/benoitfouc/audit-medias/releases/download/{tag}/audit-medias-{tag}.zip',
                sha256=hashlib.sha256(package.read_bytes()).hexdigest(), size=package.stat().st_size,
                requires=requires, requires_php=requires_php, description=description)
    manifest = ROOT / 'dist' / NAME
    manifest.write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    bridge = ROOT / 'dist/benfou-transition-audit-medias-v1.0.0.zip'
    with zipfile.ZipFile(bridge, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for name, path in [('benfou-amf-transition.php', ROOT / 'tools/update-transition/benfou-amf-transition.php'),
                           ('class-amf-static-manifest.php', ROOT / 'includes/class-amf-static-manifest.php')]:
            info = zipfile.ZipInfo('benfou-amf-transition/' + name, (2020, 1, 1, 0, 0, 0))
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            info.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(info, path.read_bytes(), compresslevel=9)
    assert len(manifest.read_bytes()) < 131072
    print('Manifest:', manifest)
    print('Transition:', bridge)
    return package, manifest, bridge

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--tag', required=True)
    args = parser.parse_args()
    build(args.tag)
