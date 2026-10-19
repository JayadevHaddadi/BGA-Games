#!/usr/bin/env python3
"""Automated BGA Studio live table tester using verified session cookies."""
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

def get_token():
    req = urllib.request.Request('https://studio.boardgamearena.com/studio', headers={
        'Cookie': COOKIE,
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
    })
    with urllib.request.urlopen(req) as resp:
        html = resp.read().decode('utf-8', errors='ignore')
        m = re.search(r'TournoiEnLigneStudioidt=([^;]+)', COOKIE)
        if m:
            return m.group(1)
        m2 = re.search(r'request_token[\"\'\:\s=]+([a-zA-Z0-9_-]+)', html)
        return m2.group(1) if m2 else ''

def get_game_id(game_name):
    url = f'https://studio.boardgamearena.com/gamepanel?game={game_name}'
    req = urllib.request.Request(url, headers={
        'Cookie': COOKIE,
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
    })
    try:
        with urllib.request.urlopen(req) as resp:
            html = resp.read().decode('utf-8', errors='ignore')
            m = re.search(r'game_id[\"\'\:\s=]+(\d+)', html)
            if m:
                return int(m.group(1))
            m2 = re.search(r'data-game-id=[\"\'](\d+)[\"\']', html)
            if m2:
                return int(m2.group(1))
    except Exception as e:
        print(f"Failed to fetch game ID for {game_name}: {e}")
    return None

if __name__ == '__main__':
    token = get_token()
    print(f"Session token: {token}")
    for g in ['people', 'microchip', 'gardensofenceladus', 'redd']:
        gid = get_game_id(g)
        print(f"{g}: ID = {gid}")
