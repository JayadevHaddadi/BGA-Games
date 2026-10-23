#!/usr/bin/env python3
"""
Uploads metadata images to BGA Studio Game Metadata Manager (GMM).
Handles:
  - box_280x280.png
  - icon_50x50.png
  - banner_1386x400.jpg
  - publisher_280x280.png
  - display_*.jpg (all displays found)
  - title_2000x2000.png
"""
import sys
import os
import json
import time
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parent.parent

PROJECT_MAP = {
    'pushfight': 'pushfight',
    'lordsofscotland': 'lordsofscotland',
    'omega': 'omega',
    'yavalath': 'yavalath',
    'kiln': 'kiln',
    'sugargliders': 'sugargliders',
    'gardensofuranus': 'gardensofuranus',
    'nestorgamesgp': 'nestorgamesgp',
    'microforge': 'microforge',
    'gardensofmars': 'gardensofmars',
    'gardensofio': 'gardensofio',
    'gardensofenceladus': 'gardensofenceladus',
    'red': 'red',
    'microchip': 'microchip',
    'people': 'people',
    'hipposandcrocodiles': 'hipposandcrocodiles',
    'pentup': 'pentup',
}

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

def upload_single_asset(page, trigger_finder, file_path, label, project_slug):
    if not file_path.exists():
        print(f"    [!] File not found: {file_path}")
        return False

    print(f"    Uploading {label} ({file_path.name})...")
    
    # Locate trigger
    trigger = trigger_finder()
    if not trigger or trigger.count() == 0 or not trigger.first.is_visible():
        print(f"    [!] Trigger not found or not visible for {label}")
        return False

    trigger.first.click()
    page.wait_for_timeout(1000)

    modals = [m for m in page.locator('.bga-modal, [role="dialog"]').all() if m.is_visible()]
    if not modals:
        print(f"    [!] Modal not found for {label}")
        return False
    modal = modals[-1]

    file_input = modal.locator('input[type="file"]')
    if file_input.count() == 0:
        print(f"    [!] File input not found in modal for {label}")
        return False

    file_input.first.set_input_files(str(file_path))
    page.wait_for_timeout(500)

    submit_btn = modal.locator('a.bga-button--blue:has-text("Submit"), button:has-text("Submit")')
    if submit_btn.count() == 0:
        print(f"    [!] Submit button not found in modal for {label}")
        return False

    try:
        with page.expect_response(lambda r: 'uploadMedia' in r.url, timeout=25000) as resp_info:
            submit_btn.first.click()
        resp = resp_info.value
        if resp.status == 200:
            print(f"    [x] Successfully uploaded {label} (HTTP 200)")
        else:
            print(f"    [!] Upload {label} returned HTTP {resp.status}")
    except Exception as e:
        print(f"    [!] Exception waiting for upload response: {e}")

    page.wait_for_timeout(2500)
    navigate_to_media(page, project_slug)
    return True

def delete_all_display_images(page, project_slug):
    print("    Clearing old display images...")
    navigate_to_media(page, project_slug)
    if "has been deployed" in page.content():
        print(f"    [!] {project_slug} has been deployed, media cannot be modified in Studio.")
        return

    max_deletes = 10
    attempts = 0
    while attempts < max_deletes:
        attempts += 1
        h = page.get_by_text('Display images', exact=True).first
        parent = h.locator('xpath=../..')
        thumbs = parent.locator('div.cursor-pointer')
        if thumbs.count() == 0:
            break
        print(f"      Deleting an old display image (remaining before delete: {thumbs.count()})...")
        thumbs.first.click()
        page.wait_for_timeout(1000)
        modals = [m for m in page.locator('.bga-modal, [role="dialog"]').all() if m.is_visible()]
        if not modals:
            break
        del_btn = modals[-1].locator('button:has-text("Delete"), a:has-text("Delete"), .bga-button--red')
        if del_btn.count() == 0:
            break
        del_btn.first.click()
        page.wait_for_timeout(1000)

        # Confirm modal
        confirm_modals = [m for m in page.locator('.bga-modal, [role="dialog"]').all() if m.is_visible() and 'Are you sure?' in m.inner_text()]
        if confirm_modals:
            confirm_btn = confirm_modals[-1].locator('a:has-text("Confirm"), button:has-text("Confirm")')
            if confirm_btn.count() > 0:
                try:
                    with page.expect_response(lambda r: 'removeMedia' in r.url, timeout=12000):
                        confirm_btn.first.click()
                except Exception as e:
                    print(f"      [!] Delete response note: {e}")
        page.wait_for_timeout(2000)
        navigate_to_media(page, project_slug)

def navigate_to_media(page, project_slug):
    url = f'https://studio.boardgamearena.com/controlpanelgames?game={project_slug}'
    page.goto(url, wait_until='domcontentloaded')
    page.wait_for_timeout(2500)

    tabs = [t for t in page.locator('div.cursor-pointer').all() if t.is_visible() and t.inner_text().strip() == 'Media']
    if tabs:
        tabs[0].click()
        page.wait_for_timeout(1500)
    else:
        print("    [!] Could not locate Media tab")

def upload_game_media(page, slug, replace_displays=False, displays_only=False):
    project_slug = PROJECT_MAP.get(slug, slug)
    game_folder = 'KILN' if slug.lower() == 'kiln' else slug
    meta_dir = ROOT / 'games' / game_folder / 'bga' / 'metadata_assets'

    print(f"\n=======================================================")
    print(f"Processing media upload for: {slug} (project: {project_slug})")
    print(f"=======================================================")

    navigate_to_media(page, project_slug)
    if "has been deployed" in page.content():
        print(f"    [!] {project_slug} has been deployed, media cannot be modified in Studio.")
        return

    if not displays_only:
        # 1. Box image
        def find_box_trigger():
            h = page.get_by_text('Box', exact=True).first
            parent = h.locator('xpath=../..')
            btns = parent.locator('a.bga-button:has-text("+")')
            if btns.count() > 0:
                return btns
            return parent.locator('div.cursor-pointer')

        upload_single_asset(page, find_box_trigger, meta_dir / 'box_280x280.png', 'Box', project_slug)

        # 2. Icon image
        def find_icon_trigger():
            h = page.get_by_text('Icon', exact=True).first
            parent = h.locator('xpath=../..')
            return parent.locator('div.cursor-pointer')

        upload_single_asset(page, find_icon_trigger, meta_dir / 'icon_50x50.png', 'Icon', project_slug)

        # 3. Banner image
        def find_banner_trigger():
            h = page.get_by_text('Banner', exact=True).first
            parent = h.locator('xpath=../..')
            return parent.locator('div.cursor-pointer')

        upload_single_asset(page, find_banner_trigger, meta_dir / 'banner_1386x400.jpg', 'Banner', project_slug)

        # 4. Publisher image
        def find_publisher_trigger():
            h = page.get_by_text('Publisher images', exact=True).first
            parent = h.locator('xpath=../..')
            return parent.locator('a.bga-button:has-text("+")')

        upload_single_asset(page, find_publisher_trigger, meta_dir / 'publisher_280x280.png', 'Publisher', project_slug)

    # Display images
    if replace_displays:
        delete_all_display_images(page, project_slug)

    def find_display_trigger():
        h = page.get_by_text('Display images', exact=True).first
        parent = h.locator('xpath=../..')
        return parent.locator('a.bga-button:has-text("+")')

    display_files = sorted(meta_dir.glob('display_*.jpg'))
    for d_file in display_files:
        upload_single_asset(page, find_display_trigger, d_file, f'Display ({d_file.name})', project_slug)

    if not displays_only:
        # 7. Title image
        def find_title_trigger():
            h = page.get_by_text('Title images', exact=True).first
            parent = h.locator('xpath=../..')
            return parent.locator('a.bga-button:has-text("+")')

        upload_single_asset(page, find_title_trigger, meta_dir / 'title_2000x2000.png', 'Title', project_slug)

    # Final screenshot of Media tab
    page.screenshot(path=f'/tmp/{slug}_media_final.png')
    print(f"  [x] Saved final screenshot: /tmp/{slug}_media_final.png")

def dry_run_check(page, slug):
    project_slug = PROJECT_MAP.get(slug, slug)
    print(f"\nChecking Studio Dry Run Build for: {slug} ({project_slug})...")
    url = f'https://studio.boardgamearena.com/studiogame?game={project_slug}'
    page.goto(url, wait_until='domcontentloaded')
    page.wait_for_timeout(3000)

    btn = page.get_by_text('Dry run build (to check code warnings)', exact=False).first
    if btn.count() > 0:
        btn.click()
        page.wait_for_timeout(5000)
        page.screenshot(path=f'/tmp/{slug}_dry_run.png')
        print(f"  [x] Dry run complete, screenshot: /tmp/{slug}_dry_run.png")
    else:
        print("  [!] Dry run button not found")

def main():
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('slugs', nargs='*', default=['pentup'])
    parser.add_argument('--replace-displays', action='store_true', help='Delete old displays before uploading')
    parser.add_argument('--displays-only', action='store_true', help='Upload displays only')
    args = parser.parse_args()

    cookies = load_cookies()

    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path='/usr/bin/google-chrome', headless=True)
        context = browser.new_context(viewport={'width': 1280, 'height': 1200})
        context.add_cookies(cookies)
        page = context.new_page()

        for slug in args.slugs:
            upload_game_media(page, slug, replace_displays=args.replace_displays, displays_only=args.displays_only)
            dry_run_check(page, slug)

        browser.close()
    print("\nAll requested media uploads and dry-run checks finished successfully!")

if __name__ == '__main__':
    main()
