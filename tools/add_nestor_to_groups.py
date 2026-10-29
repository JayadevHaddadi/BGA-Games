#!/usr/bin/env python3
"""
Adds Nestor (nestorgames, player ID 84297980) to each game's group on Board Game Arena
and promotes him to group admin.
"""
import sys
import json
import time
from pathlib import Path
from playwright.sync_api import sync_playwright

ROOT = Path('/home/jayadevhaddadi/GitHub/Mandala-helper')
sys.path.insert(0, str(ROOT / 'tools'))
from upload_gamehelp import load_cookies

NESTOR_ID = '84297980'

# All game groups identified from gamepanels and player profile
GROUPS = {
    'Feed the Ducks': '21756426',
    'Kiln': '21571336',
    'Omega': '21576441',
    'Sugar Gliders': '21571308',
    'Yavalath': '21571589',
    'Gardens of Io': '21661156',
    'Gardens of Enceladus': '21708208',
    'Gardens of Mars': '21757472',
    'People': '21710168',
    'Pent-Up': '21721321',
    'Microchip': '21745123',
    'Red': '21743948',
    'Vault': '21745100',
    'Hippos & Crocodiles': '21743924',
    'Push Fight': '21472369',
}

def main():
    cookies = load_cookies()
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path='/usr/bin/google-chrome', headless=True)
        context = browser.new_context(viewport={'width': 1280, 'height': 1000})
        context.add_cookies(cookies)
        page = context.new_page()

        # Load BGA session
        page.goto('https://boardgamearena.com', wait_until='domcontentloaded')
        page.wait_for_timeout(2000)

        results = {}

        for name, gid in GROUPS.items():
            print(f"\nProcessing group: {name} (ID: {gid})...")
            
            # Navigate to group page to establish context and CSRF token
            page.goto(f'https://boardgamearena.com/group?id={gid}', wait_until='domcontentloaded')
            page.wait_for_timeout(2000)

            # Process group via page evaluate
            res = page.evaluate('''async ({gid, nestorId}) => {
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

                // 2. If not a member, invite him
                if (!nestor) {
                    let invResp = await fetch('/group/group/invitePlayer.html', {
                        method: 'POST',
                        headers: headers,
                        body: new URLSearchParams({id: gid, playerId: nestorId})
                    });
                    let invData = await invResp.json();
                    if (invData.status != 1) {
                        return {success: false, error: 'Invite failed: ' + JSON.stringify(invData)};
                    }
                    actionsTaken.push('invited');
                    
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

                if (!nestor) {
                    return {success: false, actions: actionsTaken, error: 'Nestor invited but not in members list (may be pending invite)'};
                }

                // 3. Promote to admin if not already
                if (nestor.role !== 'admin') {
                    let promResp = await fetch('/community/community/promoteGroupAdmin.html', {
                        method: 'POST',
                        headers: headers,
                        body: new URLSearchParams({id: gid, player: nestorId})
                    });
                    let promData = await promResp.json();
                    if (promData.status != 1) {
                        return {success: false, actions: actionsTaken, error: 'Promote failed: ' + JSON.stringify(promData)};
                    }
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
                } else {
                    actionsTaken.push('already_admin');
                }

                return {
                    success: true,
                    actions: actionsTaken,
                    finalRole: nestor?.role,
                    membersCount: memData.data?.membersCount
                };
            }''', {'gid': gid, 'nestorId': NESTOR_ID})

            print(f"Result for {name}: {res}")
            results[name] = res
            time.sleep(1)

        browser.close()

    print("\n" + "="*80)
    print("SUMMARY:")
    print("="*80)
    all_ok = True
    for name, r in results.items():
        status = "OK (Admin)" if r.get('finalRole') == 'admin' else f"FAILED: {r.get('error', r)}"
        if r.get('finalRole') != 'admin':
            all_ok = False
        print(f" - {name:22} : {status} (actions: {', '.join(r.get('actions', []))})")
    
    return all_ok

if __name__ == '__main__':
    main()
