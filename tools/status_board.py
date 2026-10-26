#!/usr/bin/env python3
"""
One status board for every game: BGA stage and release, help wiki, licence, live tests.

    python3 tools/status_board.py            # BGA stage via tools/deploy.py status (needs tools/bga_session.txt)
    python3 tools/status_board.py --offline  # skip the Studio check, keep the last known stage

Writes reports/GAMES_STATUS.md. Sources:
  - BGA stage, production version, unreleased commits: tools/deploy.py status (logged-in Studio session)
  - Help wiki in sync: local games/<game>/bga/GAMEHELP.wiki vs the public wiki page Gamehelp<slug> (no login)
  - Licence and owner notes: tools/games_registry.json (edit by hand)
  - Live Studio tests: reports/e2e_status.md
"""
import json
import re
import subprocess
import sys
import urllib.parse
import urllib.request
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
REGISTRY = ROOT / 'tools' / 'games_registry.json'
OUT = ROOT / 'reports' / 'GAMES_STATUS.md'
CACHE = ROOT / 'reports' / '.stage_cache.json'


def studio_status():
    """Parse the table printed by tools/deploy.py status: slug -> (stage, prod version, unreleased, status)."""
    out = subprocess.run([sys.executable, str(ROOT / 'tools' / 'deploy.py'), 'status'],
                         capture_output=True, text=True, timeout=900).stdout
    rows = {}
    for line in out.splitlines():
        cells = [c.strip() for c in line.split('|')]
        if len(cells) == 5 and cells[0] and cells[0] != 'Game':
            rows[cells[0]] = {'stage': cells[1], 'prod': cells[2], 'unreleased': cells[3], 'status': cells[4]}
    return rows


def wiki_state(slug, local_file):
    """'in sync', 'differs', 'missing online' or 'no local file' for the help page Gamehelp<slug>."""
    if not local_file.exists():
        return 'no local file'
    title = urllib.parse.quote(f'Gamehelp{slug}')
    url = ('https://en.doc.boardgamearena.com/api.php?action=query&prop=revisions'
           f'&titles={title}&rvprop=content&rvslots=main&format=json')
    try:
        req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
        data = json.load(urllib.request.urlopen(req, timeout=15))
    except Exception:
        return 'unknown (offline)'
    page = next(iter(data.get('query', {}).get('pages', {}).values()), {})
    if 'missing' in page:
        return 'missing online'
    rev = (page.get('revisions') or [{}])[0]
    online = rev.get('slots', {}).get('main', {}).get('*', rev.get('*', ''))
    norm = lambda t: re.sub(r'\s+', ' ', t).strip()
    return 'in sync' if norm(online) == norm(local_file.read_text(encoding='utf-8')) else 'differs'


def e2e_results():
    path = ROOT / 'reports' / 'e2e_status.md'
    res = {}
    if path.exists():
        for line in path.read_text(encoding='utf-8').splitlines():
            cells = [c.strip() for c in line.strip().strip('|').split('|')]
            if len(cells) >= 6 and cells[0] and not cells[0].startswith((':', 'Game', 'all other')):
                res[cells[0]] = f'{cells[4]} ({cells[5]})' if cells[5] else cells[4]
    return res


def main():
    offline = '--offline' in sys.argv
    registry = json.loads(REGISTRY.read_text(encoding='utf-8'))
    cache = json.loads(CACHE.read_text(encoding='utf-8')) if CACHE.exists() else {}
    if not offline:
        try:
            cache.update(studio_status())
            CACHE.write_text(json.dumps(cache, indent=1), encoding='utf-8')
        except Exception as e:  # expired session cookie, network...
            print(f'Studio status unavailable ({e}); using the last known values')
    e2e = e2e_results()

    lines = [
        '# Games status board',
        '',
        f'Generated {datetime.now():%Y-%m-%d %H:%M} by `python3 tools/status_board.py`. Do not edit by hand:',
        'licence and notes come from `tools/games_registry.json`, everything else is read live.',
        '',
        '| Game | Studio slug | BGA stage | Production release | Unreleased | Help wiki | Licence | Live tests | Next step / notes |',
        '| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |',
    ]
    for g in registry['games']:
        slug = g['slug']
        st = cache.get(slug, {})
        wiki = wiki_state(slug, ROOT / g['folder'] / 'GAMEHELP.wiki')
        stage = st.get('stage') or '-'
        stage = 'studio only' if stage == 'None' else stage
        lines.append('| {name} | `{slug}` | {stage} | {prod} | {unrel} | {wiki} | {lic} | {e2e} | {notes} |'.format(
            name=g['name'], slug=slug, stage=stage,
            prod=(st.get('prod') if st.get('prod') not in (None, 'None') else '-'),
            unrel=(st.get('unreleased') if st.get('unreleased') not in (None, 'None') else 'none'),
            wiki=wiki, lic=g.get('licence', '?'), e2e=e2e.get(slug, '-'), notes=g.get('notes', '')))
    lines += ['', 'Stages: studio only → private_alpha → alpha (public) → beta → public. "Unreleased" = commits since the',
              'production build; run `python3 tools/deploy.py auto` to build and deploy them (one game at a time).',
              'Help wiki "differs": run `python3 tools/upload_gamehelp.py <slug>`.', '']
    OUT.write_text('\n'.join(lines), encoding='utf-8')
    print(f'wrote {OUT.relative_to(ROOT)}')


if __name__ == '__main__':
    main()
