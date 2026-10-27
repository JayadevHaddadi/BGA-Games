#!/usr/bin/env python3
"""
Uploads GAMEHELP.wiki from games/<slug>/bga/GAMEHELP.wiki to Board Game Arena's doc wiki
(https://en.doc.boardgamearena.com/Gamehelp<slug>).

Uses Playwright with session cookies loaded from tools/bga_session.txt (or env BGA_SESSION_COOKIES).
"""
import sys
import os
import json
import time
import urllib.request
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parent.parent

# Game slug -> bga directory
TARGETS = {
    'microchip': 'games/microchip/bga',
    'vaultnestorgames': 'games/vaultnestorgames/bga',
    'people': 'games/people/bga',
    'hipposcrocodiles': 'games/hipposcrocodiles/bga',
    'pentup': 'games/pentup/bga',
    'red': 'games/red/bga',
    'gardensofmars': 'games/gardensofmars/bga',
    'microforge': 'games/microforge/bga',
    'gardensofenceladus': 'games/gardensofenceladus/bga',
    'gardensofio': 'games/gardensofio/bga',
    'gardensofuranus': 'games/gardensofuranus/bga',
    'kiln': 'games/KILN/bga',
    'omega': 'games/omega/bga',
    'sugargliders': 'games/sugargliders/bga',
    'yavalath': 'games/yavalath/bga',
    'lordsofscotland': 'games/lordsofscotland/bga',
    'nestorgamesgp': 'games/nestorgamesgp/bga',
    'pushfight': 'games/pushfight/bga',
    'mandala': 'games/mandala/bga-prod',
    'feedtheducks': 'games/feedtheducks/bga',
}

def load_cookies():
    cookie_str = os.environ.get('BGA_SESSION_COOKIES')
    if not cookie_str:
        cookie_file = ROOT / 'tools' / 'bga_session.txt'
        if not cookie_file.exists():
            raise FileNotFoundError(f'{cookie_file} not found and BGA_SESSION_COOKIES env var not set')
        cookie_str = cookie_file.read_text().strip()

    cookies = []
    for part in cookie_str.split(';'):
        part = part.strip()
        if '=' in part:
            name, val = part.split('=', 1)
            name = name.strip()
            val = val.strip()
            cookies.append({'name': name, 'value': val, 'domain': '.boardgamearena.com', 'path': '/'})
            cookies.append({'name': name, 'value': val, 'domain': 'en.doc.boardgamearena.com', 'path': '/'})
    return cookies

def get_online_wiki_content(slug):
    title = f'Gamehelp{slug}'
    url = f'https://en.doc.boardgamearena.com/api.php?action=query&prop=revisions&titles={title}&rvprop=content&format=json'
    req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
    try:
        with urllib.request.urlopen(req, timeout=10) as resp:
            data = json.loads(resp.read().decode())
            pages = data.get('query', {}).get('pages', {})
            for pid, pinfo in pages.items():
                if 'missing' in pinfo:
                    return None
                revs = pinfo.get('revisions', [])
                if revs:
                    return revs[0].get('*', '')
    except Exception as e:
        print(f"  [!] Failed to query online content for {title}: {e}")
    return None

def upload_gamehelp(page, slug, bga_subpath):
    local_wiki_file = ROOT / bga_subpath / 'GAMEHELP.wiki'
    if not local_wiki_file.exists():
        print(f"Skipping {slug}: local {local_wiki_file} not found")
        return False

    local_text = local_wiki_file.read_text(encoding='utf-8').strip()
    online_text = get_online_wiki_content(slug)

    title = f'Gamehelp{slug}'
    if online_text is not None and online_text.strip() == local_text:
        print(f"  [=] {title}: already up to date ({len(local_text)} chars)")
        return True

    action_label = "Creating" if online_text is None else "Updating"
    print(f"  [>] {action_label} {title} ({len(local_text)} chars)...")

    edit_url = f'https://en.doc.boardgamearena.com/index.php?title={title}&action=edit'
    for attempt in range(3):
        try:
            page.goto(edit_url, wait_until='domcontentloaded', timeout=30000)
            break
        except Exception as e:
            if attempt == 2: raise
            page.wait_for_timeout(3000)

    page.wait_for_timeout(1500)
    textbox = page.locator('#wpTextbox1')
    if textbox.count() == 0:
        print(f"  [!] Failed: #wpTextbox1 not found for {title}. Are cookies logged in?")
        return False

    textbox.fill(local_text)
    summary_box = page.locator('#wpSummary')
    if summary_box.count() > 0:
        summary_box.fill(f"Publish {slug} rules from repository GAMEHELP.wiki")

    page.click('#wpSave')
    page.wait_for_load_state('domcontentloaded')
    page.wait_for_timeout(2000)

    # Check that save succeeded
    if 'action=edit' not in page.url:
        print(f"  [x] Successfully saved {title} -> {page.url}")
        return True
    else:
        print(f"  [!] Save may have failed for {title}, still at {page.url}")
        return False

def main():
    slugs = sys.argv[1:] if len(sys.argv) > 1 else list(TARGETS.keys())
    print(f"Starting Gamehelp upload for: {', '.join(slugs)}")

    cookies = load_cookies()
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path='/usr/bin/google-chrome', headless=True)
        context = browser.new_context(viewport={'width': 1280, 'height': 1000})
        context.add_cookies(cookies)
        page = context.new_page()

        success = 0
        total = 0
        for slug in slugs:
            if slug not in TARGETS:
                print(f"Unknown target slug: {slug}")
                continue
            total += 1
            if upload_gamehelp(page, slug, TARGETS[slug]):
                success += 1
            time.sleep(1)

        browser.close()

    print(f"\nFinished: {success}/{total} Gamehelp pages verified/uploaded.")

if __name__ == '__main__':
    main()
