"""Publish a verified release and its static channel; runs in GitHub Actions only.
The ephemeral GITHUB_TOKEN is used on the runner, never distributed to WordPress.
Existing ZIPs are immutable: a mismatch requires a new version, never --clobber.
"""
import argparse
import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
REPO = 'benoitfouc/audit-medias'
API = 'repos/' + REPO


def command(args, data=None, missing=False):
    p = subprocess.run(args, input=data, text=True, capture_output=True, cwd=ROOT)
    if p.returncode:
        if missing and ('HTTP 404' in p.stderr or 'Not Found' in p.stderr):
            return None
        raise RuntimeError('Command failed: ' + ' '.join(args[:4]) + '\n' + p.stderr[:1000])
    return p.stdout


def api(path, method='GET', data=None, missing=False):
    args = ['gh', 'api', path, '--method', method]
    payload = None
    if data is not None:
        args += ['--input', '-']
        payload = json.dumps(data)
    result = command(args, payload, missing)
    return json.loads(result) if result else None


def numeric(tag):
    if not isinstance(tag, str) or not re.fullmatch(r'v(?:0|[1-9]\d{0,4})\.(?:0|[1-9]\d{0,4})\.(?:0|[1-9]\d{0,4})', tag):
        return None
    return tuple(map(int, tag[1:].split('.')))


def fetch_public(url, expected):
    last = None
    for pause in (0, 2, 5, 10):
        if pause:
            time.sleep(pause)
        try:
            with urllib.request.urlopen(urllib.request.Request(url, headers={'User-Agent': 'Benfou-Release-Verification/1'}), timeout=25) as r:
                data = r.read(len(expected) + 1)
            if data == expected:
                return
            last = RuntimeError('Public content differs: ' + url)
        except Exception as e:
            last = e
    raise RuntimeError('Public verification failed: ' + str(last))


def publish(tag, create=False):
    if not numeric(tag):
        raise ValueError('Stable tag required')
    if os.environ.get('GITHUB_REPOSITORY') != REPO or os.environ.get('GITHUB_ACTIONS') != 'true':
        raise RuntimeError('Publication is restricted to the official repository Actions runner.')
    spec = importlib.util.spec_from_file_location('manifest', ROOT / 'tools/build-manifest.py')
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    package, manifest, bridge = module.build(tag)
    data = json.loads(manifest.read_text(encoding='utf-8'))
    head = command(['git', 'rev-parse', 'HEAD']).strip()
    rel = api(API + '/releases/tags/' + tag, missing=True)
    if rel is None:
        if not create:
            raise RuntimeError('Release absent; explicit --create required.')
        rel = api(API + '/releases', 'POST', dict(tag_name=tag, target_commitish=head,
            name='Audit Medias ' + tag, body=data['description'], draft=True, prerelease=False))
    if rel.get('prerelease'):
        raise RuntimeError('Refusing to distribute a prerelease.')
    # No overwrite of a previously published binary, even on workflow rerun.
    existing = {a['name']: a for a in rel.get('assets', [])}
    for path in (package, manifest, bridge):
        if path.name in existing:
            with tempfile.TemporaryDirectory() as folder:
                command(['gh','release','download',tag,'--repo',REPO,'--pattern',path.name,'--dir',folder])
                if (Path(folder)/path.name).read_bytes() != path.read_bytes():
                    raise RuntimeError('Published asset differs; increment version: ' + path.name)
        else:
            command(['gh','release','upload',tag,str(path),'--repo',REPO])
    pages = command(['gh','api',API+'/releases?per_page=100','--paginate','--slurp'])
    versions = [numeric(r['tag_name']) for page in json.loads(pages) for r in page
                if not r['draft'] and not r['prerelease'] and numeric(r['tag_name'])]
    is_latest = not versions or numeric(tag) >= max(versions)
    if rel.get('draft'):
        rel = api(API + '/releases/' + str(rel['id']), 'PATCH', dict(draft=False, make_latest='true' if is_latest else 'false'))
    elif is_latest:
        api(API + '/releases/' + str(rel['id']), 'PATCH', dict(make_latest='true'))
    # Verify exact bytes anonymously, as a WordPress site will download them.
    fetch_public(data['package'], package.read_bytes())
    fetch_public('https://github.com/' + REPO + '/releases/download/' + tag + '/' + manifest.name, manifest.read_bytes())
    if not is_latest:
        print('Older release verified; stable channel left unchanged.')
        return
    ref = api(API + '/git/ref/heads/updates', missing=True)
    parent = ref['object']['sha'] if ref else None
    if parent:
        previous = api(API + '/contents/update.json?ref=updates', missing=True)
        if previous:
            old = json.loads(base64.b64decode(previous['content']))
            if numeric(old.get('tag')) and numeric(old['tag']) > numeric(tag):
                print('Newer static manifest exists; refusing a downgrade.')
                return
    index = ('<!doctype html><html lang="fr"><meta charset="utf-8"><title>Audit Medias - mises a jour</title>'
             '<h1>Audit Medias - canal stable</h1><p>Version ' + tag + '</p>'
             '<p><a href="update.json">Manifeste JSON</a> | <a href="' + data['package'] + '">ZIP WordPress</a></p>'
             '<p>Ne pas installer les archives Source code. Aucun jeton requis.</p></html>')
    tree = api(API+'/git/trees','POST',dict(tree=[
        dict(path='update.json', mode='100644', type='blob', content=manifest.read_text(encoding='utf-8')),
        dict(path='index.html', mode='100644', type='blob', content=index),
        dict(path='.nojekyll', mode='100644', type='blob', content='')]))
    commit_data = dict(message='publish: static update manifest ' + tag, tree=tree['sha'])
    if parent:
        commit_data['parents'] = [parent]
    commit = api(API+'/git/commits','POST',commit_data)
    if parent:
        api(API+'/git/refs/heads/updates','PATCH',dict(sha=commit['sha'],force=False))
    else:
        api(API+'/git/refs','POST',dict(ref='refs/heads/updates',sha=commit['sha']))
    fetch_public('https://raw.githubusercontent.com/' + REPO + '/updates/update.json', manifest.read_bytes())
    print('PUBLISHED ' + tag + ' | static metadata and package verified')
    print('SHA256 ' + data['sha256'])

if __name__ == '__main__':
    p = argparse.ArgumentParser()
    p.add_argument('--tag', required=True)
    p.add_argument('--create', action='store_true')
    args = p.parse_args()
    publish(args.tag, args.create)
