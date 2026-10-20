#!/usr/bin/env python3
"""Automated BGA Studio live table tester: creates tables and launches games."""
import json
import re
import sys
import urllib.request
import urllib.parse
from pathlib import Path

COOKIE_FILE = Path(__file__).parent / 'bga_session.txt'
if not COOKIE_FILE.exists():
    print(f"Error: {COOKIE_FILE} not found.")
    sys.exit(1)

COOKIE = COOKIE_FILE.read_text().strip()

def get_session_token():
    m = re.search(r'TournoiEnLigneStudioidt=([^;]+)', COOKIE)
    return m.group(1) if m else ''

def get_game_id(game_name):
    url = f'https://studio.boardgamearena.com/gamepanel?game={game_name}'
    req = urllib.request.Request(url, headers={
        'Cookie': COOKIE,
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
    })
    with urllib.request.urlopen(req) as resp:
        html = resp.read().decode('utf-8', errors='ignore')
        m = re.search(r'<span[^>]*id=\"game_id\"[^>]*>(\d+)</span>', html)
        if m:
            return int(m.group(1))
    return None

def create_table(game_id):
    token = get_session_token()
    data = f'game={game_id}&mode=1'.encode('utf-8')
    req = urllib.request.Request('https://studio.boardgamearena.com/table/table/createnew.html', data=data, headers={
        'Cookie': COOKIE,
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Request-Token': token
    })
    with urllib.request.urlopen(req) as resp:
        res = json.loads(resp.read().decode('utf-8'))
        if res.get('status') == 1:
            return res['data']['table']
        else:
            raise RuntimeError(f"Failed to create table: {res}")

def start_game(table_id):
    token = get_session_token()
    data = f'table={table_id}'.encode('utf-8')
    req = urllib.request.Request('https://studio.boardgamearena.com/table/table/startgame.html', data=data, headers={
        'Cookie': COOKIE,
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Request-Token': token
    })
    try:
        with urllib.request.urlopen(req) as resp:
            return json.loads(resp.read().decode('utf-8'))
    except Exception as e:
        return {'error': str(e)}

if __name__ == '__main__':
    games = sys.argv[1:] if len(sys.argv) > 1 else ['people', 'microchip', 'gardensofenceladus', 'red']
    for g in games:
        print(f"\n--- Testing Live Studio Table for {g} ---")
        gid = get_game_id(g)
        if not gid:
            print(f"Could not find game ID for {g}")
            continue
        print(f"Game ID: {gid}")
        try:
            tid = create_table(gid)
            print(f"Created table #{tid}: https://studio.boardgamearena.com/table?table={tid}")
            res = start_game(tid)
            print(f"Start game result: {res}")
        except Exception as e:
            print(f"Error: {e}")
