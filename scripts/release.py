#!/usr/bin/env python3
"""Validate versions and build an installable plugin without development files."""
import argparse
import datetime
import hashlib
import json
import re
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / 'eventwillow-public.php'
SLUG = 'eventwillow-public'
SEMVER = r'(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)'


def version():
    source = PLUGIN.read_text()
    header = re.search(r'^ \* Version:\s*(\S+)\s*$', source, re.M)
    constant = re.search(r"define\('EWP_VERSION', '([^']+)'\);", source)
    if not header or not constant or header[1] != constant[1] or not re.fullmatch(SEMVER, header[1]):
        raise SystemExit('Plugin version header and EWP_VERSION must match a stable X.Y.Z version.')
    return header[1]


def bump(kind):
    current = version()
    numbers = list(map(int, current.split('.')))
    index = ['major', 'minor', 'patch'].index(kind)
    numbers[index] += 1
    for i in range(index + 1, 3):
        numbers[i] = 0
    new = '.'.join(map(str, numbers))
    source = re.sub(r'^ \* Version:.*$', ' * Version: ' + new, PLUGIN.read_text(), flags=re.M)
    source = source.replace("define('EWP_VERSION', '" + current + "');", "define('EWP_VERSION', '" + new + "');")
    PLUGIN.write_text(source)
    return version()


def build(expected=None):
    current = version()
    if expected and current != expected:
        raise SystemExit('Release tag does not match plugin version: ' + current)
    output = ROOT / 'build'
    output.mkdir(exist_ok=True)
    archive = output / (SLUG + '.zip')
    files = [PLUGIN]
    for directory in ['assets', 'includes', 'templates']:
        files += sorted((ROOT / directory).rglob('*'))
    for name in ['readme.txt', 'LICENSE']:
        if (ROOT / name).is_file():
            files.append(ROOT / name)
    with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as package:
        for file in sorted(files):
            if file.is_symlink():
                raise SystemExit('Refusing symlink in plugin package: ' + str(file))
            if file.is_file():
                info = zipfile.ZipInfo(SLUG + '/' + file.relative_to(ROOT).as_posix(), (1980, 1, 1, 0, 0, 0))
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                package.writestr(info, file.read_bytes())
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    (output / 'SHA256SUMS').write_text(digest + '  ' + archive.name + '\n')
    manifest = {
        'name': 'EventWillow Public Views', 'slug': SLUG, 'version': current,
        'download_url': f'https://files.arcanetechct.com/{SLUG}/{SLUG}-{current}.zip',
        'homepage': 'https://github.com/AdamArcane/wp-eventwillow', 'requires_php': '8.0',
        'last_updated': datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%d %H:%M:%S'),
        'sections': {
            'description': 'API-backed EventWillow directories, calendars and Elementor templates.',
            'changelog': 'See https://github.com/AdamArcane/wp-eventwillow/releases',
        },
    }
    (output / 'info.json').write_text(json.dumps(manifest, indent=2) + '\n')
    print(f'Built {archive.name} version {current}; SHA256 {digest}')


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('command', choices=['version', 'bump', 'build'])
    parser.add_argument('value', nargs='?')
    args = parser.parse_args()
    if args.command == 'bump':
        if args.value not in ['major', 'minor', 'patch']:
            parser.error('Bump requires major, minor or patch.')
        print(bump(args.value))
    elif args.command == 'build':
        build(args.value)
    else:
        current = version()
        if args.value and args.value != current:
            raise SystemExit('Tag version does not match plugin version.')
        print(current)
