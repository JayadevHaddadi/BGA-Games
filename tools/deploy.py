#!/usr/bin/env python3
"""
Board Game Arena (BGA) Automatic Deployment Tool
Inspects game versions on BGA Studio, compares with local git changes,
builds new release versions, and deploys them to Alpha / Beta / Production.

Usage:
    python3 tools/deploy.py status                     # check status of all games
    python3 tools/deploy.py status <slug>              # check status of one game
    python3 tools/deploy.py auto                       # auto-deploy all games with unreleased changes
    python3 tools/deploy.py <slug> [--comment "..."]   # sync, build and deploy a single game
    python3 tools/deploy.py build <slug> [--comment]   # build only (no deploy)
    python3 tools/deploy.py deploy <slug> <version>    # deploy an existing build to production
"""

import os
import sys
import json
import time
import argparse
import subprocess
from pathlib import Path
from datetime import datetime
from playwright.sync_api import sync_playwright

ROOT = Path(__file__).resolve().parent.parent

# Games that should not be auto-deployed (e.g. sandboxes or declined)
SKIP_SLUGS = {
    'pushfight',       # User requested skip (declined)
    'mandala',         # Shared repository on studio
    'omegatest',
    'yavalathtest',
    'sugargliderstest',
    'gardensofuranustest',
    'nestorgamesgptest',
    'microforgetest',
    'gardensofmarstest',
    'pushfighttest',
    'lordsofscotlandtest',
}


def load_cookies():
    cookie_file = ROOT / 'tools' / 'bga_session.txt'
    if not cookie_file.exists():
        raise FileNotFoundError(f"{cookie_file} not found. Studio session required.")
    cookie_str = cookie_file.read_text().strip()
    cookies = []
    for part in cookie_str.split(';'):
        part = part.strip()
        if '=' in part:
            name, val = part.split('=', 1)
            cookies.append({'name': name.strip(), 'value': val.strip(), 'domain': '.boardgamearena.com', 'path': '/'})
            cookies.append({'name': name.strip(), 'value': val.strip(), 'domain': 'studio.boardgamearena.com', 'path': '/'})
    return cookies


def get_target_map():
    sys.path.insert(0, str(ROOT / 'tools'))
    from sync import TARGET_MAP
    return TARGET_MAP


def ver_to_dt(ver_str):
    """Converts BGA version string (YYMMDD-HHMM in Paris CET/CEST time) to unix epoch timestamp."""
    if not ver_str or len(ver_str) != 11 or '-' not in ver_str:
        return None
    try:
        from zoneinfo import ZoneInfo
        dt = datetime.strptime('20' + ver_str, '%Y%m%d-%H%M').replace(tzinfo=ZoneInfo('Europe/Paris'))
        return int(dt.timestamp())
    except Exception:
        return None


def get_git_commits(rel_path, since_epoch=None):
    """Returns commits touching rel_path since unix epoch timestamp."""
    cmd = ['git', 'log', '--format=%h|%cd|%s', '--date=format:%Y-%m-%d %H:%M:%S']
    if since_epoch:
        cmd.append(f'--since={since_epoch}')
    cmd.extend(['--', rel_path])
    res = subprocess.run(cmd, cwd=str(ROOT), capture_output=True, text=True)
    if res.returncode != 0:
        return []
    commits = []
    for line in res.stdout.strip().splitlines():
        if not line.strip():
            continue
        parts = line.split('|', 2)
        if len(parts) == 3:
            commits.append({
                'hash': parts[0].strip(),
                'date': parts[1].strip(),
                'subject': parts[2].strip()
            })
    return commits


def sync_sftp(slug):
    """Runs tools/sync.py for the given slug to ensure SFTP mirrors local."""
    print(f"  [SFTP] Syncing {slug} to BGA Studio...")
    res = subprocess.run([sys.executable, str(ROOT / 'tools' / 'sync.py'), slug], cwd=str(ROOT), capture_output=True, text=True)
    if res.returncode != 0:
        print(f"  [!] Sync failed for {slug}:\n{res.stderr}")
        return False
    print(f"  [x] SFTP sync completed for {slug}.")
    return True


def get_game_data(page, slug):
    """Queries Studio for game page data and version history."""
    return page.evaluate('''async (slug) => {
        const token = window.bgaConfig?.requestToken;
        const body = new URLSearchParams({game: slug});
        try {
            const rPage = await fetch('/studiogame/studiogame/getStudioGamePageData.html', {
                method: 'POST',
                headers: {'Accept': 'application/json', 'X-Request-Token': token},
                body: body
            });
            const dPage = await rPage.json();
            
            const rVers = await fetch('/studiogame/studiogame/getStudioGameVersions.html', {
                method: 'POST',
                headers: {'Accept': 'application/json', 'X-Request-Token': token},
                body: body
            });
            const dVers = await rVers.json();
            
            return {
                success: true,
                page: dPage?.data?.game || null,
                versions: dVers?.data || null
            };
        } catch (err) {
            return {success: false, error: String(err)};
        }
    }''', slug)


def build_version(page, slug, comment):
    """Triggers build.html on BGA Studio."""
    print(f"  [BUILD] Building release version on Studio for {slug}...")
    print(f"          Comment: \"{comment}\"")
    res = page.evaluate('''async ({slug, comment}) => {
        const token = window.bgaConfig?.requestToken;
        const body = new URLSearchParams({gamename: slug, comment: comment});
        const r = await fetch('/admin/studio/build.html', {
            method: 'POST',
            headers: {'Accept': 'application/json', 'X-Request-Token': token},
            body: body
        });
        const status = r.status;
        const text = await r.text();
        let data = null;
        try { data = JSON.parse(text); } catch(e) { data = text; }
        return {status, data};
    }''', {'slug': slug, 'comment': comment})

    if res['status'] != 200:
        print(f"  [!] Build HTTP error {res['status']}: {res['data']}")
        return None

    data = res.get('data') or {}
    if not isinstance(data, dict):
        print(f"  [!] Build returned a non-JSON reply (Studio busy or logged out?): {str(data)[:300]}")
        return None
    blocks = data.get('blocks', [])
    has_failure = False
    for b in blocks:
        title = b.get('title', 'Unknown step')
        statuses = b.get('statuses', [])
        if any(s != 1 for s in statuses):
            print(f"  [!] Build step failed: {title} (statuses: {statuses})")
            # Show what BGA reported for this step (messages are HTML snippets)
            import re as _re
            for key in ('content', 'lines', 'messages', 'html', 'logs'):
                if b.get(key):
                    txt = _re.sub(r'<[^>]+>', ' ', json.dumps(b[key]) if not isinstance(b[key], str) else b[key])
                    print('      ' + _re.sub(r'\s+', ' ', txt)[:1500])
            if not any(b.get(k) for k in ('content', 'lines', 'messages', 'html', 'logs')):
                print('      ' + json.dumps(b)[:1500])
            has_failure = True

    if has_failure:
        print("  [!] Build reported errors. Aborting.")
        return None

    # Retrieve new versions to find the built version string
    time.sleep(2)
    info = get_game_data(page, slug)
    vers = info.get('versions', {}).get('versions', [])
    if vers:
        new_ver = vers[0].get('version')
        print(f"  [x] Successfully built release version: {new_ver}")
        return new_ver

    print("  [!] Could not determine new built version.")
    return None


def deploy_version(page, slug, version):
    """Deploys a built version to production/alpha via proddeploy.html."""
    print(f"  [DEPLOY] Deploying version {version} to production for {slug}...")
    res = page.evaluate('''async ({slug, version}) => {
        const token = window.bgaConfig?.requestToken;
        const body = new URLSearchParams({game: slug, version: version});
        const r = await fetch('/admin/studio/proddeploy.html', {
            method: 'POST',
            headers: {'Accept': 'application/json', 'X-Request-Token': token},
            body: body
        });
        const status = r.status;
        let data = null;
        try { data = await r.json(); } catch(e) { data = await r.text(); }
        return {status, data};
    }''', {'slug': slug, 'version': version})

    if res['status'] != 200:
        print(f"  [!] Deploy HTTP error {res['status']}: {res['data']}")
        return False

    data = res.get('data') or {}
    if not isinstance(data, dict):
        print(f"  [!] Build returned a non-JSON reply (Studio busy or logged out?): {str(data)[:300]}")
        return None
    blocks = data.get('blocks', [])
    for b in blocks:
        title = b.get('title', 'Deploy step')
        statuses = b.get('statuses', [])
        if any(s != 1 for s in statuses):
            print(f"  [!] Deploy step failed: {title} (statuses: {statuses})")
            return False

    print(f"  [x] Successfully deployed {slug} version {version} to production!")
    return True


def generate_comment(commits):
    """Generates a concise, informative release note comment from git commits."""
    if not commits:
        return "Maintenance release and bug fixes"
    
    # Filter out test/chore/doc commits to find core features & fixes
    meaningful = [c['subject'] for c in commits if not c['subject'].startswith(('test', 'chore', 'doc', 'Merge'))]
    if not meaningful:
        meaningful = [c['subject'] for c in commits if not c['subject'].startswith('Merge')]

    if not meaningful:
        return "Maintenance update and engine sync"

    # Clean prefixes like "fix(slug): " or "feat(slug): "
    cleaned = []
    for s in meaningful:
        s_clean = s
        if ':' in s:
            s_clean = s.split(':', 1)[1].strip()
        cleaned.append(s_clean)

    # Join top 1-2 distinct items up to 240 chars (BGA limit is 250)
    distinct = []
    for c in cleaned:
        if c not in distinct:
            distinct.append(c)
    
    comment = "; ".join(distinct[:2])
    if len(comment) > 240:
        comment = comment[:237] + "..."
    return comment


def audit_game(page, slug, rel_path):
    """Audits one game, returning detailed status dictionary."""
    data = get_game_data(page, slug)
    page_data = data.get('page') or {}
    versions_data = data.get('versions') or {}

    prod = page_data.get('prod')
    stage = prod.get('status') if prod else None
    prod_version = versions_data.get('production-version')
    versions_list = versions_data.get('versions') or []
    last_build = versions_list[0].get('version') if versions_list else None

    # Check git commits
    prod_dt = ver_to_dt(prod_version) if prod_version else None
    unreleased = get_git_commits(rel_path, prod_dt) if prod_dt else get_git_commits(rel_path)
    latest_commit = get_git_commits(rel_path)
    latest_commit_info = latest_commit[0] if latest_commit else None

    # Determine status
    if slug in SKIP_SLUGS:
        status = 'SKIPPED'
    elif stage is None:
        status = 'NOT_IN_ALPHA'
    elif unreleased:
        status = 'NEEDS_DEPLOY'
    else:
        status = 'UP_TO_DATE'

    return {
        'slug': slug,
        'rel_path': rel_path,
        'stage': stage,
        'prod_version': prod_version,
        'last_build': last_build,
        'unreleased': unreleased,
        'latest_commit': latest_commit_info,
        'status': status
    }


def check_and_add_nestor_admin(page, slug):
    """Ensures Nestor is invited and promoted to admin of the game's group on BGA."""
    try:
        from add_nestor_to_groups import find_group_for_slug, ensure_nestor_admin
        gid = find_group_for_slug(page, slug)
        if gid:
            print(f"Ensuring Nestor is Admin on group {gid} for {slug}...")
            res = ensure_nestor_admin(page, gid)
            print(f"  Nestor group admin status: {res.get('finalRole', 'unknown')} (actions: {res.get('actions', [])})")
        else:
            print(f"  [Notice] No BGA group found for {slug} yet.")
    except Exception as e:
        print(f"  [Warning] Failed to ensure Nestor admin for {slug}: {e}")


def main():
    parser = argparse.ArgumentParser(description="BGA Automatic Deployment Tool")
    parser.add_argument('action', nargs='?', default='status',
                        help="Action (status, auto, release, build, deploy) or game slug")
    parser.add_argument('target', nargs='?', default=None,
                        help="Game slug (e.g. kiln, omega, or 'all')")
    parser.add_argument('--comment', help="Release note comment for the build")
    args = parser.parse_args()

    # Normalize action if user passed a slug as action: e.g. "deploy.py kiln"
    target_map = get_target_map()
    if args.action in target_map and args.target is None:
        args.target = args.action
        args.action = 'release'

    cookies = load_cookies()

    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path='/usr/bin/google-chrome', headless=True)
        page = browser.new_page()
        page.context.add_cookies(cookies)

        # Authenticate on studio
        page.goto('https://studio.boardgamearena.com/studio', wait_until='domcontentloaded')
        page.wait_for_timeout(2000)

        token = page.evaluate('() => window.bgaConfig?.requestToken')
        if not token:
            print("Error: Could not retrieve BGA request token. Please update tools/bga_session.txt.")
            sys.exit(1)

        # ----------------------------------------------------
        # 1. STATUS
        # ----------------------------------------------------
        if args.action == 'status':
            games_to_check = []
            if args.target and args.target in target_map:
                games_to_check.append((args.target, target_map[args.target][0]))
            else:
                seen = set()
                for s, (p_path, remote) in target_map.items():
                    if remote not in seen:
                        seen.add(remote)
                        games_to_check.append((remote, p_path))

            print("\n" + "=" * 90)
            print(f"{'Game':18} | {'Stage':14} | {'Prod Version':12} | {'Unreleased':10} | {'Status':14}")
            print("=" * 90)

            for slug, rel_path in games_to_check:
                audit = audit_game(page, slug, rel_path)
                stage_str = audit['stage'] or 'None'
                prod_ver = audit['prod_version'] or 'None'
                unrel_cnt = f"{len(audit['unreleased'])} commits" if audit['unreleased'] else 'None'
                print(f"{slug:18} | {stage_str:14} | {prod_ver:12} | {unrel_cnt:10} | {audit['status']:14}")

            print("=" * 90)

        # ----------------------------------------------------
        # 2. AUTO DEPLOY
        # ----------------------------------------------------
        elif args.action == 'auto':
            seen = set()
            games_to_check = []
            for s, (p_path, remote) in target_map.items():
                if remote not in seen:
                    seen.add(remote)
                    games_to_check.append((remote, p_path))

            print("\n" + "=" * 80)
            print("BGA AUTO-DEPLOYMENT: Auditing active projects for unreleased changes...")
            print("=" * 80)

            to_deploy = []
            for slug, rel_path in games_to_check:
                audit = audit_game(page, slug, rel_path)
                if audit['status'] == 'NEEDS_DEPLOY':
                    to_deploy.append(audit)

            if not to_deploy:
                print("All Alpha / Production games are already up to date! Nothing to deploy.")
                return

            print(f"\nFound {len(to_deploy)} game(s) requiring redeployment:")
            for item in to_deploy:
                print(f" - {item['slug']} (Stage: {item['stage']}, ProdVer: {item['prod_version']}, Unreleased: {len(item['unreleased'])} commits)")

            results = []
            for item in to_deploy:
                slug = item['slug']
                comment = args.comment or generate_comment(item['unreleased'])
                print(f"\n>>> Starting Deployment for: {slug} <<<")
                
                # Step 1: SFTP Sync
                sync_ok = sync_sftp(slug)
                if not sync_ok:
                    results.append({'slug': slug, 'success': False, 'error': 'SFTP sync failed'})
                    continue

                # Step 2: Build release on Studio
                new_ver = build_version(page, slug, comment)
                if not new_ver:
                    results.append({'slug': slug, 'success': False, 'error': 'Studio build failed'})
                    continue

                # Step 3: Deploy to production
                deploy_ok = deploy_version(page, slug, new_ver)
                if not deploy_ok:
                    results.append({'slug': slug, 'success': False, 'error': 'Prod deploy failed'})
                    continue

                check_and_add_nestor_admin(page, slug)

                results.append({
                    'slug': slug,
                    'success': True,
                    'stage': item['stage'],
                    'old_version': item['prod_version'],
                    'new_version': new_ver,
                    'comment': comment
                })

            print("\n" + "=" * 80)
            print("AUTO-DEPLOYMENT SUMMARY:")
            print("=" * 80)
            for r in results:
                if r['success']:
                    print(f" [SUCCESS] {r['slug']:18} : {r['old_version']} -> {r['new_version']} ({r['stage']})")
                    print(f"           Comment: \"{r['comment']}\"")
                else:
                    print(f" [FAILED]  {r['slug']:18} : {r['error']}")
            print("=" * 80)

        # ----------------------------------------------------
        # 3. SINGLE GAME RELEASE (SYNC + BUILD + DEPLOY)
        # ----------------------------------------------------
        elif args.action == 'release':
            if not args.target or args.target not in target_map:
                print(f"Error: Target '{args.target}' not found in TARGET_MAP.")
                sys.exit(1)

            slug = target_map[args.target][1]
            rel_path = target_map[args.target][0]

            audit = audit_game(page, slug, rel_path)
            comment = args.comment or generate_comment(audit['unreleased'])

            print(f"\nDeploying single game: {slug} (Stage: {audit['stage']})...")
            if not sync_sftp(slug):
                print("SFTP sync failed: not building (the build would release the old Studio files).")
                sys.exit(1)
            new_ver = build_version(page, slug, comment)
            if not new_ver:
                print("Build failed.")
                sys.exit(1)

            if audit['stage'] in ['alpha', 'private_alpha', 'beta', 'public']:
                deploy_ok = deploy_version(page, slug, new_ver)
                if not deploy_ok:
                    print("Deploy failed.")
                    sys.exit(1)
                print(f"\n[DONE] {slug} deployed version {new_ver} to {audit['stage']}!")
                check_and_add_nestor_admin(page, slug)
            else:
                print(f"\n[NOTE] {slug} is not in Alpha ({audit['stage']}). Version {new_ver} was built on Studio but cannot be deployed to production.")


if __name__ == '__main__':
    main()
