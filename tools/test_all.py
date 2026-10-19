#!/usr/bin/env python3
"""Every offline test for every game, in one command (CI runs this on each push; see .github/workflows/tests.yml).

  python3 tools/test_all.py            # all games
  python3 tools/test_all.py microchip  # one game

Per game: `bga.py check` (PHP lint, JS syntax, reviewer rules, rules tests in tests/*_test.php), then the browser
harness (tests/harness/frame.html) in headless Chrome at 375 px and 1280 px. A harness fails on any JS error or any
check with "ok": false.

Known, accepted FAILs live in tools/known_fails.json ({"game": ["check name", ...]}); they are reported, not fatal.
Needs: php, node, google-chrome (or chromium) on PATH.
"""
import argparse
import functools
import html
import http.server
import json
import re
import shutil
import subprocess
import sys
import threading
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
GAMES = REPO / 'games'
KNOWN = json.loads((REPO / 'tools' / 'known_fails.json').read_text(encoding='utf-8'))


def chrome():
    for name in ('google-chrome', 'chromium', 'chromium-browser', 'chrome'):
        if shutil.which(name):
            return shutil.which(name)
    win = Path('C:/Program Files/Google/Chrome/Application/chrome.exe')
    return str(win) if win.exists() else None


def game_dirs(only):
    for d in sorted(GAMES.iterdir()):
        if d.is_dir() and any((d / b / 'modules').exists() for b in ('bga', 'bga-prod')):
            if not only or d.name.lower() == only.lower():
                yield d


def run_check(game):
    out = subprocess.run([sys.executable, str(REPO / 'tools' / 'bga.py'), 'check', game.name],
                         capture_output=True, text=True, encoding='utf-8', errors='replace')
    fails = [line[6:].split(' - ')[0].strip() for line in out.stdout.splitlines() if line.startswith('FAIL')]
    accepted = KNOWN.get(game.name.lower(), [])
    new = [f for f in fails if f not in accepted and '*' not in accepted]
    return new, [f for f in fails if f not in new]


def serve(folder):
    class Quiet(http.server.SimpleHTTPRequestHandler):
        def log_message(self, *args):
            pass
    handler = functools.partial(Quiet, directory=str(folder))
    server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    return server


def walk_fails(obj, path=''):
    if isinstance(obj, dict):
        if obj.get('ok') is False:
            yield path
        for k, v in obj.items():
            if k in ('errors', 'errors_after_setup') and isinstance(v, list) and v:
                yield f'{path}.{k}: {v[:2]}'
            if isinstance(v, dict) and k in ('errors_after_setup',) and v.get('value'):
                yield f'{path}.{k}: {v["value"][:2]}'
            yield from walk_fails(v, f'{path}.{k}')
    elif isinstance(obj, list):
        for i, v in enumerate(obj):
            yield from walk_fails(v, f'{path}[{i}]')


def run_harness(game, browser):
    frame = game / 'tests' / 'harness' / 'frame.html'
    if not frame.exists():
        return None
    server = serve(game)
    port = server.server_address[1]
    problems = []
    try:
        for width in (375, 1280):
            url = f'http://127.0.0.1:{port}/tests/harness/frame.html?w={width}'
            out = subprocess.run([browser, '--headless=new', '--no-sandbox', '--disable-gpu', '--virtual-time-budget=8000',
                                  '--dump-dom', url], capture_output=True, text=True, encoding='utf-8', errors='replace', timeout=120)
            m = re.search(r'<pre id="out"[^>]*>(.*?)</pre>', out.stdout, re.S)
            if not m:
                problems.append(f'{width}px: no result')
                continue
            text = html.unescape(m.group(1))
            try:
                report = json.loads(text)
            except ValueError:
                problems.append(f'{width}px: {text[:160]}')
                continue
            problems += [f'{width}px {p}' for p in walk_fails(report)]
    finally:
        server.shutdown()
    return problems


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('game', nargs='?')
    args = parser.parse_args()
    browser = chrome()
    failed = False
    for game in game_dirs(args.game):
        new, accepted = run_check(game)
        harness = run_harness(game, browser) if browser else None
        status = 'FAIL' if new or harness else 'ok'
        failed |= status == 'FAIL'
        extra = f' (accepted: {", ".join(accepted)})' if accepted else ''
        hz = 'no harness' if harness is None else ('harness ok' if not harness else 'harness: ' + '; '.join(harness[:3]))
        print(f'{status:<4} {game.name:<22} check: {"; ".join(new) or "ok"}{extra} | {hz}')
    if not browser:
        print('note: no Chrome found, harnesses skipped')
    sys.exit(1 if failed else 0)


if __name__ == '__main__':
    main()
