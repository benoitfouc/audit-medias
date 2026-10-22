"""Create the reproducible WordPress ZIP. No WordPress installation required."""
import argparse
import hashlib
from pathlib import Path
import re
import zipfile

def build(tag=None, output='dist'):
    root = Path(__file__).resolve().parents[1]
    source = (root / 'audit-medias.php').read_text(encoding='utf-8')
    header = re.search(r'^\s*\*\s*Version:\s*(\d+\.\d+\.\d+)\s*$', source, re.M)
    constant = re.search(r"define\(\s*'AMF_VERSION'\s*,\s*'(\d+\.\d+\.\d+)'\s*\)", source)
    if not header or not constant or header[1] != constant[1]:
        raise SystemExit('Version header and AMF_VERSION must match.')
    version = header[1]
    if tag and tag != 'v' + version:
        raise SystemExit('Release tag must be v' + version)
    files = [root / 'audit-medias.php', root / 'README.txt']
    for folder in ('assets', 'includes'):
        files += sorted((root / folder).rglob('*'))
    files = sorted(p for p in files if p.is_file())
    dest = root / output
    dest.mkdir(parents=True, exist_ok=True)
    archive = dest / ('audit-medias-v' + version + '.zip')
    with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as z:
        for path in files:
            if path.is_symlink() or root not in path.resolve().parents:
                raise SystemExit('Unsafe package path: ' + str(path))
            name = 'audit-medias/' + path.relative_to(root).as_posix()
            item = zipfile.ZipInfo(name, date_time=(2020, 1, 1, 0, 0, 0))
            item.create_system = 3
            item.external_attr = 0o100644 << 16
            item.compress_type = zipfile.ZIP_DEFLATED
            z.writestr(item, path.read_bytes(), compresslevel=9)
    with zipfile.ZipFile(archive) as z:
        if z.testzip() is not None:
            raise SystemExit('ZIP integrity check failed.')
        assert all(x.startswith('audit-medias/') for x in z.namelist())
        assert 'audit-medias/audit-medias.php' in z.namelist()
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    print(str(archive))
    print('SHA256 ' + digest)
    print(str(len(files)) + ' files, ' + str(archive.stat().st_size) + ' bytes')
    return archive

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--tag')
    parser.add_argument('--output', default='dist')
    args = parser.parse_args()
    build(args.tag, args.output)
