#!/usr/bin/env python3
"""
Fills BGA Studio Game Metadata Manager (GMM) from games/<slug>/metadata.json.
Uses Playwright with session cookies in tools/bga_session.txt.
"""
import sys
import os
import json
import time
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parent.parent

def load_cookies():
    cookie_file = ROOT / 'tools' / 'bga_session.txt'
    if not cookie_file.exists():
        raise FileNotFoundError(f'{cookie_file} not found')
    cookie_str = cookie_file.read_text().strip()
    cookies = []
    for part in cookie_str.split(';'):
        part = part.strip()
        if '=' in part:
            name, val = part.split('=', 1)
            name = name.strip()
            val = val.strip()
            cookies.append({'name': name, 'value': val, 'domain': '.boardgamearena.com', 'path': '/'})
            cookies.append({'name': name, 'value': val, 'domain': 'studio.boardgamearena.com', 'path': '/'})
    return cookies

def fill_gmm(page, slug):
    meta_file = ROOT / 'games' / slug / 'metadata.json'
    if not meta_file.exists():
        print(f"Skipping {slug}: no metadata.json found")
        return False

    with open(meta_file, encoding='utf-8') as f:
        meta = json.load(f)

    project_slug = meta.get('studio_project') or slug

    print(f"\n=======================================================")
    print(f"Updating GMM for: {slug} (project: {project_slug}, {meta.get('game_name')})")
    print(f"=======================================================")

    url = f'https://studio.boardgamearena.com/controlpanelgames?game={project_slug}'
    for attempt in range(3):
        try:
            page.goto(url, wait_until='domcontentloaded', timeout=30000)
            break
        except Exception as e:
            if attempt == 2: raise
            page.wait_for_timeout(3000)
    page.wait_for_timeout(2000)

    # 1. Switch to Metadata tab
    tabs = [t for t in page.locator('div.cursor-pointer').all() if t.is_visible() and t.inner_text().strip() == 'Metadata']
    if not tabs:
        print(f"Error: Metadata tab not found for {slug}")
        return False
    tabs[0].click()
    page.wait_for_timeout(1000)

    # Helper to set text input in a row
    def set_row_field(row_selector, val, label_name):
        row = page.locator(row_selector).first
        if row.count() > 0:
            btn = row.locator('a.bga-button').first
            btn.click()
            page.wait_for_timeout(500)
            inps = [i for i in page.locator('input').all() if i.is_visible() and not i.is_disabled() and i.get_attribute('readonly') is None and 'search' not in (i.get_attribute('placeholder') or '').lower()]
            if inps:
                inps[-1].fill(str(val))
                save = [b for b in page.locator('a.bga-button--blue, button:has-text("Save")').all() if b.is_visible() and 'Save' in b.inner_text()][0]
                save.click()
                page.wait_for_timeout(1000)
                print(f"  [x] Set {label_name} = {val}")
            else:
                print(f"  [!] No editable input for {label_name}")

    # Designer
    designers = meta.get('designers', [])
    designer_str = designers[0] if designers and isinstance(designers[0], str) else (designers[0].get('name') if designers and isinstance(designers[0], dict) else '')
    if designer_str:
        # Check if already set
        d_row = page.locator('div.tabmetadata__row-2:has-text("Designer")').first
        if 'NOT SET' in d_row.inner_text() or 'John Doe' in d_row.inner_text() or designer_str not in d_row.inner_text():
            set_row_field('div.tabmetadata__row-2:has-text("Designer")', designer_str, 'Designer')

    # Publication year
    year = meta.get('year_published')
    if year:
        y_row = page.locator('div.tabmetadata__row-1:has-text("Publication year")').first
        if 'NOT SET' in y_row.inner_text():
            set_row_field('div.tabmetadata__row-1:has-text("Publication year")', year, 'Publication year')

    # Characteristics
    chars = meta.get('characteristics', {})
    char_map = {
        'Complexity': chars.get('complexity', 2),
        'Luck': chars.get('luck', 1),
        'Strategy': chars.get('strategy', 3),
        'Diplomacy': chars.get('diplomacy', 1),
    }
    for label, val in char_map.items():
        set_row_field(f'div.tabmetadata__row-4:has-text("{label}")', val, label)

    # Zombie Mode level
    zombie_level = meta.get('zombie_mode_level', 1)
    set_row_field('div.tabmetadata__row-1:has-text("Zombie Mode level")', zombie_level, 'Zombie Mode level')

    # Description
    desc = meta.get('description', '')
    if desc:
        d_row = page.locator('div.tabmetadata__row-1:has-text("Description")').first
        if d_row.count() > 0:
            btn = d_row.locator('a.bga-button').first
            btn.click()
            page.wait_for_timeout(500)
            visible_tas = [t for t in page.locator('textarea').all() if t.is_visible()]
            paragraphs = [p.strip() for p in desc.split('\n\n') if p.strip()]
            if len(visible_tas) >= 2 and len(paragraphs) >= 2:
                visible_tas[0].fill(paragraphs[0])
                visible_tas[1].fill(paragraphs[1])
            elif visible_tas:
                visible_tas[0].fill(desc)
            save_btn = [b for b in page.locator('button:has-text("Save"), a.bga-button--blue:has-text("Save")').all() if b.is_visible()][0]
            save_btn.click()
            page.wait_for_timeout(1500)
            print(f"  [x] Set Description ({len(paragraphs)} paragraphs)")

    # 2. Switch to Tags tab
    tags_tab = [t for t in page.locator('div.cursor-pointer').all() if t.is_visible() and t.inner_text().strip() == 'Tags'][0]
    tags_tab.click()
    page.wait_for_timeout(1000)

    # Check attached tags accurately from .bga-game-tag-list__item
    existing_items = page.locator('.bga-game-tag-list__item').all()
    clean_tags = []
    for it in existing_items:
        txt = it.inner_text().strip().split('\n')[0]
        if txt and txt not in clean_tags:
            clean_tags.append(txt)
    print(f"  Current tags: {clean_tags}")

    if len(clean_tags) < 2:
        tag_to_add = "Family"
        print(f"  Adding tag '{tag_to_add}'...")
        plus = page.locator('.tag-add-button').first
        plus.click()
        page.wait_for_timeout(500)
        find_tag = page.locator('input[placeholder="Find a tag"]').first
        find_tag.fill(tag_to_add)
        page.wait_for_timeout(1000)

        family_badge = page.locator('.badge:has-text("Family"), [class*="tag"]:has-text("Family"), button:has-text("Family")')
        for i in range(family_badge.count()):
            el = family_badge.nth(i)
            if el.is_visible() and el.inner_text().strip() == tag_to_add:
                el.click()
                page.wait_for_timeout(1500)
                print(f"  [x] Added tag '{tag_to_add}'")
                break
        # Press Escape or click tab to close tag modal
        page.keyboard.press("Escape")
        page.wait_for_timeout(500)

    # Take verification screenshots
    page.screenshot(path=f'/tmp/{slug}_gmm_tags.png')
    tabs[0].click()
    page.wait_for_timeout(1000)
    page.screenshot(path=f'/tmp/{slug}_gmm_metadata.png')
    print(f"  Saved screenshots /tmp/{slug}_gmm_*.png")
    return True

def main():
    if len(sys.argv) < 2:
        print("Usage: python3 tools/fill_gmm.py <slug> [slug2 ...]")
        sys.exit(1)

    cookies = load_cookies()
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path='/usr/bin/google-chrome', headless=True)
        context = browser.new_context(viewport={'width': 1280, 'height': 1200})
        context.add_cookies(cookies)
        page = context.new_page()

        for slug in sys.argv[1:]:
            fill_gmm(page, slug)

        browser.close()
    print("\nAll done!")

if __name__ == '__main__':
    main()
