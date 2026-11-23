#!/usr/bin/env python3
"""Check that a built ZIP is an installable Joomla component package.

Usage: build/verify-package.py <package.zip> [expected-version]

Checks: manifest at the root, type="component" method="upgrade", every file and folder the
manifest names exists, SQL install/update files exist (including one for this version), the
installer script and service provider exist, and the version matches when one is given.
"""
import json
import os
import sys
import tempfile
import xml.etree.ElementTree as ET
import zipfile


def main() -> int:
    if len(sys.argv) < 2:
        print(__doc__)
        return 2

    package, expected = sys.argv[1], (sys.argv[2] if len(sys.argv) > 2 else None)
    errors = []

    with tempfile.TemporaryDirectory() as d:
        with zipfile.ZipFile(package) as z:
            for name in z.namelist():
                if name.startswith('/') or '..' in name.split('/'):
                    print(f'Unsafe path in package: {name}')
                    return 1
            z.extractall(d)

        manifest_path = os.path.join(d, 'servicedeskplus.xml')
        if not os.path.isfile(manifest_path):
            print('servicedeskplus.xml is not at the root of the package')
            return 1

        m = ET.parse(manifest_path).getroot()
        if m.tag != 'extension' or m.get('type') != 'component' or m.get('method') != 'upgrade':
            errors.append('manifest must be <extension type="component" method="upgrade">')

        version = (m.findtext('version') or '').strip()
        if expected and version != expected:
            errors.append(f'manifest version {version} does not match {expected}')

        def check(node, base):
            if node is None:
                errors.append('missing <files>, <media> or <administration><files> section')
                return
            for f in node.findall('filename'):
                if not os.path.isfile(os.path.join(d, base, f.text)):
                    errors.append(f'missing file {base}/{f.text}')
            for f in node.findall('folder'):
                if not os.path.isdir(os.path.join(d, base, f.text)):
                    errors.append(f'missing folder {base}/{f.text}')

        site = m.find('files')
        check(site, site.get('folder', '') if site is not None else '')
        media = m.find('media')
        check(media, media.get('folder', '') if media is not None else '')
        admin = m.find('administration/files')
        check(admin, admin.get('folder', '') if admin is not None else '')

        for f in m.iter('file'):
            if not os.path.isfile(os.path.join(d, 'administrator', f.text)):
                errors.append(f'missing SQL file administrator/{f.text}')

        for s in m.iter('schemapath'):
            path = os.path.join(d, 'administrator', s.text)
            if not os.path.isfile(os.path.join(path, version + '.sql')):
                errors.append(f'missing update file administrator/{s.text}/{version}.sql')

        script = m.findtext('scriptfile')
        if script and not os.path.isfile(os.path.join(d, script)):
            errors.append(f'missing installer script {script}')

        if not os.path.isfile(os.path.join(d, 'administrator/services/provider.php')):
            errors.append('missing administrator/services/provider.php')

        # The web asset file carries the version too (browsers fetch new CSS/JS after an update).
        assets = os.path.join(d, 'media', 'joomla.asset.json')
        if os.path.isfile(assets):
            with open(assets, encoding='utf-8') as fh:
                asset_version = json.load(fh).get('version', '')
            if asset_version != version:
                errors.append(f'media/joomla.asset.json version {asset_version} does not match {version}')
        else:
            errors.append('missing media/joomla.asset.json')

    if errors:
        print('Package check FAILED:')
        for e in errors:
            print(' -', e)
        return 1

    print(f'Package OK: com_servicedeskplus {version}')
    return 0


if __name__ == '__main__':
    sys.exit(main())
