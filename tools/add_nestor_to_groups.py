#!/usr/bin/env python3
"""
Adds Nestor (nestorgames, player ID 84297980) to each game's group on Board Game Arena
and promotes him to group admin.

Usage:
    python3 tools/add_nestor_to_groups.py              # all known game groups
    python3 tools/add_nestor_to_groups.py <slug>       # single game (e.g. feedtheducks)
"""
import sys
import re
import json
import time
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path('/home/jayadevhaddadi/GitHub/Mandala-helper')
sys.path.insert(0, str(ROOT / 'tools'))
from upload_gamehelp import load_cookies

NESTOR_ID = '84297980'

# Pre-indexed game groups
KNOWN_GROUPS = {
    'feedtheducks': '21756426',
    'kiln': '21571336',
    'omega': '21576441',
    'sugargliders': '21571308',
    'yavalath': '21571589',
    'gardensofio': '21661156',
    'gardensofenceladus': '21708208',
    'gardensofmars': '21757472',
    'people': '21710168',
    'pentup': '21721321',
    'microchip': '21745123',
    'red': '21743948',
    'vaultnestorgames': '21745100',
    'vault': '21745100',
    'hipposcrocodiles': '21743924',
    'pushfight': '21472369',
}

def find_group_for_slug(page, slug):
    """Finds group ID from known dictionary or by scraping gamepanel."""
    slug_lower = slug.lower()
    if slug_lower in KNOWN_GROUPS:
        return KNOWN_GROUPS[slug_lower]

    try:
        page.goto(f'https://boardgamearena.com/gamepanel?game={slug}', wait_until='domcontentloaded', timeout=12000)
        page.wait_for_timeout(1500)
        link = page.locator('a[href*="/group?id="]').first
        if link.count() > 0:
            href = link.get_attribute('href')
            m = re.search(r'id=(\d+)', href)
            if m:
                gid = m.group(1)
                KNOWN_GROUPS[slug_lower] = gid
                return gid
    except Exception as e:
        print(f"  [!] Could not locate group on gamepanel for {slug}: {e}")
    return None

def ensure_nestor_admin(page, gid):
    """Navigates to group and ensures Nestor is invited and promoted to admin."""
    page.goto(f'https://boardgamearena.com/group?id={gid}', wait_until='domcontentloaded')
    page.wait_for_timeout(2000)

    return page.evaluate('''async ({gid, nestorId}) => {
        const token = window.bgaConfig?.requestToken;
        const headers = {'X-Request-Token': token};

        // 1. Get current members
        let memResp = await fetch('/group/group/getMembersData.html', {
            method: 'POST',
            headers: headers,
            body: new URLSearchParams({id: gid, limit: 100, offset: 0, search: '', role_filter: 'members'})
        });
        let memData = await memResp.json();
        
        if (memData.status != 1) {
            return {success: false, error: 'Could not fetch members: ' + JSON.stringify(memData)};
        }

        let members = memData.data?.members || [];
        let isAdmin = memData.data?.isAdmin;
        if (!isAdmin) {
            return {success: false, error: 'Current user is not admin of this group'};
        }

        let nestor = members.find(m => m.id == nestorId);
        let actionsTaken = [];

        // 2. If not a member, check invited or invite
        if (!nestor) {
            let invResp = await fetch('/group/group/invitePlayer.html', {
                method: 'POST',
                headers: headers,
                body: new URLSearchParams({id: gid, playerId: nestorId})
            });
            let invData = await invResp.json();
            actionsTaken.push('invited');
            
            // Promote can work directly once invited
            let promResp = await fetch('/community/community/promoteGroupAdmin.html', {
                method: 'POST',
                headers: headers,
                body: new URLSearchParams({id: gid, player: nestorId})
            });
            let promData = await promResp.json();
            actionsTaken.push('promoted_to_admin');

            // Refresh members
            await new Promise(r => setTimeout(r, 1000));
            memResp = await fetch('/group/group/getMembersData.html', {
                method: 'POST',
                headers: headers,
                body: new URLSearchParams({id: gid, limit: 100, offset: 0, search: '', role_filter: 'members'})
            });
            memData = await memResp.json();
            members = memData.data?.members || [];
            nestor = members.find(m => m.id == nestorId);
        } else {
            actionsTaken.push('already_member');
        }

        // 3. Promote to admin if not already
        if (nestor && nestor.role !== 'admin') {
            let promResp = await fetch('/community/community/promoteGroupAdmin.html', {
                method: 'POST',
                headers: headers,
                body: new URLSearchParams({id: gid, player: nestorId})
            });
            let promData = await promResp.json();
            actionsTaken.push('promoted_to_admin');

            // Verify
            await new Promise(r => setTimeout(r, 1000));
            memResp = await fetch('/group/group/getMembersData.html', {
                method: 'POST',
                headers: headers,
                body: new URLSearchParams({id: gid, limit: 100, offset: 0, search: '', role_filter: 'members'})
            });
            memData = await memResp.json();
            members = memData.data?.members || [];
            nestor = members.find(m => m.id == nestorId);
        } else if (nestor && nestor.role === 'admin') {
            actionsTaken.push('already_admin');
        }

        return {
            success: nestor?.role === 'admin',
            actions: actionsTaken,
            finalRole: nestor?.role,
            membersCount: memData.data?.membersCount
        };
    }''', {'gid': gid, 'nestorId': NESTOR_ID})

def main():
    targets = sys.argv[1:] if len(sys.argv) > 1 else list(KNOWN_GROUPS.keys())
    # Deduplicate while preserving order
    slugs = list(dict.fromkeys(targets))

    cookies = load_cookies()
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path='/usr/bin/google-chrome', headless=True)
        context = browser.new_context(viewport={'width': 1280, 'height': 1000})
        context.add_cookies(cookies)
        page = context.new_page()

        page.goto('https://boardgamearena.com', wait_until='domcontentloaded')
        page.wait_for_timeout(2000)

        results = {}

        for slug in slugs:
            gid = find_group_for_slug(page, slug)
            if not gid:
                print(f"\n[SKIP] {slug}: No BGA group found on gamepanel yet.")
                results[slug] = {'success': False, 'error': 'No group found'}
                continue

            print(f"\nProcessing group for '{slug}' (ID: {gid})...")
            res = ensure_nestor_admin(page, gid)
            print(f"  Result: {res}")
            results[slug] = res
            time.sleep(1)

        browser.close()

    print("\n" + "="*80)
    print("SUMMARY:")
    print("="*80)
    for slug, r in results.items():
        status = "OK (Admin)" if r.get('finalRole') == 'admin' else f"FAILED: {r.get('error', r)}"
        print(f" - {slug:20} : {status} (actions: {', '.join(r.get('actions', []))})")

if __name__ == '__main__':
    main()
