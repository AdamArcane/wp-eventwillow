#!/usr/bin/env python3
"""Build a deterministic installable ZIP and a self-hosted update manifest."""
import argparse, hashlib, json, re, zipfile
from pathlib import Path
root = Path(__file__).resolve().parents[1]
args = argparse.ArgumentParser()
args.add_argument('--version', help='Expected tag version; rejects mismatches')
args.add_argument('--base-url', default='https://files.arcanetechct.com/eventwillow-sync')
opts = args.parse_args()
source = (root / 'eventwillow-sync.php').read_text()
header = re.search(r'^ \* Version: (\S+)$', source, re.M)
constant = re.search(r"define\('EVENTWILLOW_SYNC_VERSION','([^']+)'\)", source)
if not header or not constant or header[1] != constant[1] or not re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+', header[1]):
    raise SystemExit('Header and constant must have matching X.Y.Z versions')
version = header[1]
if opts.version and opts.version != version:
    raise SystemExit('Release tag and plugin version differ')
if not opts.base_url.startswith('https://'):
    raise SystemExit('Release base URL must use HTTPS')
files = ['eventwillow-sync.php', 'dynamic-fields.php', 'atomic-backgrounds.php', 'readme.txt', 'LICENSE', 'NOTICE.md']
files += [str(f.relative_to(root)) for f in sorted((root / 'includes').rglob('*.php'))]
output = root / 'dist'
output.mkdir(exist_ok=True)
archive = output / f'eventwillow-sync-{version}.zip'
with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as package:
    for name in sorted(files):
        file = root / name
        if not file.is_file() or file.is_symlink():
            raise SystemExit('Missing or unsafe release file: ' + name)
        info = zipfile.ZipInfo('eventwillow-sync/' + name, (1980, 1, 1, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        package.writestr(info, file.read_bytes())
manifest = dict(name='EventWillow Sync', slug='eventwillow-sync', version=version,
    download_url=opts.base_url.rstrip('/') + '/' + archive.name,
    homepage='https://eventwillow.com', author='Arcane Tech Solutions', requires='6.5', requires_php='8.0',
    sections={'description':'Native WordPress events and channels for one-way synchronization.',
              'changelog':'0.1.8: Processes sync continuations in a bounded batch for faster progress with infrequent cron triggers.'},
    sha256=hashlib.sha256(archive.read_bytes()).hexdigest())
(output / 'info.json').write_text(json.dumps(manifest, indent=2) + '\n')
print(archive)
print(output / 'info.json')
