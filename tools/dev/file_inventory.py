#!/usr/bin/env python3
"""
File-change inventory of the integrated system against its two sources (written for docs/FILE_CHANGE_INVENTORY.md).

    python3 tools/dev/file_inventory.py <extracted cooperative_system folder> <extracted WaterBillingSystemImport folder> > docs/FILE_CHANGE_INVENTORY.md

Every file the project ships (git ls-files) is classed as: unchanged from the cooperative system, changed from it,
taken from the water system (under water/) unchanged or adapted, or new. Files of the two sources that are not
shipped are listed too. Compares file contents byte for byte.
"""
import hashlib
import os
import subprocess
import sys
from collections import defaultdict

root = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
coop_dir, water_dir = sys.argv[1], sys.argv[2]


def digest(path):
    h = hashlib.sha1()
    with open(path, 'rb') as fh:
        h.update(fh.read())
    return h.hexdigest()


def files_of(base, skip=('.git/',)):
    out = {}
    for d, _, fs in os.walk(base):
        for f in fs:
            p = os.path.relpath(os.path.join(d, f), base).replace(os.sep, '/')
            if any(p.startswith(s) for s in skip):
                continue
            out[p] = os.path.join(d, f)
    return out


shipped = subprocess.run(['git', 'ls-files'], cwd=root, capture_output=True, text=True, check=True).stdout.split('\n')
shipped = [s for s in shipped if s]
coop = files_of(coop_dir)
water = files_of(water_dir)

cls = defaultdict(list)
for p in shipped:
    full = os.path.join(root, p)
    if p.startswith('water/'):
        src = p[len('water/'):]
        if src in water:
            cls['water_same' if digest(full) == digest(water[src]) else 'water_changed'].append(p)
        else:
            cls['new'].append(p)
    elif p in coop:
        cls['coop_same' if digest(full) == digest(coop[p]) else 'coop_changed'].append(p)
    else:
        cls['new'].append(p)

shipped_set = set(shipped)
coop_dropped = sorted(p for p in coop if p not in shipped_set)
water_dropped = sorted(p for p in water if 'water/' + p not in shipped_set)


def group(paths, depth=2):
    g = defaultdict(int)
    for p in paths:
        parts = p.split('/')
        g['/'.join(parts[:depth]) + ('/' if len(parts) > depth else '')] += 1
    return g


print('# File-change inventory\n')
print('Computed by `tools/dev/file_inventory.py` from the shipped files (`git ls-files`) against the two supplied ZIP files,')
print('byte for byte. The water system lives under `water/` (its former root folder); everything else is the cooperative system.\n')
print('| Class | Files |\n|---|---:|')
labels = [('coop_same', 'Cooperative system, unchanged'), ('coop_changed', 'Cooperative system, changed'),
          ('water_same', 'Water system, unchanged (under water/)'), ('water_changed', 'Water system, adapted (under water/)'),
          ('new', 'New')]
for k, label in labels:
    print(f'| {label} | {len(cls[k])} |')
print(f'| Cooperative files not shipped | {len(coop_dropped)} |')
print(f'| Water files not shipped | {len(water_dropped)} |')
print(f'| **Shipped in total** | **{len(shipped)}** |\n')

print('## New files\n')
for p in sorted(cls['new']):
    print(f'- `{p}`')
print('\n## Cooperative system files changed\n')
for p in sorted(cls['coop_changed']):
    print(f'- `{p}`')
print('\n## Water system files adapted\n')
print('Most of these carry the same mechanical changes: the session/config bootstrap of the integrated system instead of the')
print('former `session_start()`, the integrated title and fonts, and (for the accounting and inventory parts) the posting')
print('bridge `app/WaterPosting.php` instead of the former journal tables. By folder:\n')
for d, n in sorted(group(cls['water_changed']).items()):
    print(f'- `{d}`: {n}')
print('\n<details><summary>Every adapted water file</summary>\n')
for p in sorted(cls['water_changed']):
    print(f'- `{p}`')
print('\n</details>\n')
print('## Water system files shipped unchanged\n')
for d, n in sorted(group(cls['water_same']).items()):
    print(f'- `{d}`: {n}')
print('\n## Not shipped\n')
print('Cooperative system (`storage/platform/multi_company.json` is the switch the program writes when several companies are')
print('set up; the copy in the ZIP named the build machine\'s own databases, so a new installation starts without it):\n')
for p in coop_dropped:
    print(f'- `{p}`')
print('\nWater system (by folder; `.git` excluded). These are the endpoints of the retired water accounting, inventory,')
print('supplier and user pages (their jobs are done by the cooperative pages: `docs/RETAINED_REPLACED_MATRIX.md`), the')
print('former login/landing `index.php`, database dumps and reset scripts (`database/*.sql`, `seeders/truncate.sql`: the')
print('schema is in `database/migrations/2026_12_01_water_billing.sql` and the data comes through `tools/migrate_water.php`),')
print('a CSV export of customer data (`files/`), sample import files (`storage/imports/`), the former manuals (replaced by')
print('generated ones) and the former project notes and test evidence (`docs/`, `README.md`, `scenarios.txt`,')
print('`recommendations.txt`).\n')
for d, n in sorted(group(water_dropped).items()):
    print(f'- `{d}`: {n}')
