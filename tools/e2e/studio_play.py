#!/usr/bin/env python3
"""End-to-end test: play a real BGA Studio table with a headless browser.

  python3 tools/e2e/studio_play.py gardensofenceladus --players 2
  python3 tools/e2e/studio_play.py gardensofenceladus --players max
  python3 tools/e2e/studio_play.py gardensofenceladus --probe     # log in, dump page structure, no assertions
  python3 tools/e2e/studio_play.py --selftest                     # check the runner itself, no login needed

Scenarios (default: all): load, refresh, autoplay, zombie, replay.
Output (default tools/e2e/out/<game>-<players>p/): report.json, report.md, numbered screenshots, DOM dumps on failure.
Exit code: 0 all passed, 1 a scenario failed, 2 no credentials / setup problem.

Credentials: env BGA_STUDIO_PASSWORD only (+ optional BGA_STUDIO_USERNAME, default JayadevHaddadi0). GitHub Actions sets it
from the repo secret. There is deliberately no password file and no fallback to the SFTP credentials: never paste the
password into a chat or a file.

STATUS: written from the BGA documentation, not yet run against Studio. Every page-structure guess is in SELECTORS
or the *_TEXT lists below, so the first live run (--probe) only needs those adjusted. See reports/e2e_setup.md.
"""
import argparse
import datetime
import json
import os
import re
import sys
import time
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
STUDIO = 'https://studio.boardgamearena.com'

# ---- Guesses about Studio's page structure: adjust here after the first --probe run. ---------------------
SELECTORS = {
    'username': 'input[name="email"], input#username_input, input[type="email"], input[type="text"]',
    'password': 'input[type="password"], input[placeholder*="assword" i]',
    'title': '#pagemaintitletext',          # BGA status bar text (current state / "End of game")
    'play_area': '#game_play_area',
}
LOGIN_BUTTON_TEXT = re.compile(r'^(log ?in|sign ?in)$', re.I)
USERNAME_PLACEHOLDER = re.compile(r'email or username', re.I)
EXPRESS_START_TEXT = re.compile(r'express start', re.I)
DEBUG_MENU_TEXT = re.compile(r'^debug$', re.I)
QUIT_TEXT = re.compile(r'(quit|leave) (the )?game', re.I)
CONFIRM_TEXT = re.compile(r'^(yes|confirm|ok)$', re.I)
REPLAY_TEXT = re.compile(r'replay', re.I)
END_TEXT = re.compile(r'end of game|game results|has won|winner|victory', re.I)
# Text that means the game or framework broke.
ERROR_TEXTS = [
    'Unexpected error', 'Fatal error', 'Error loading the game', 'Wrong formatted data', 'Server syntax error',
    'Unknown gamestate', 'Undefined variable', 'Uncaught', 'This move is not authorized', 'Please reload the page',
]
# Console noise that is not the game's fault.
IGNORED_CONSOLE = [
    re.compile(p, re.I) for p in (
        r'favicon', r'google', r'analytics', r'sentry', r'adsbygoogle', r'Failed to load resource.*(40[34]|net::ERR_BLOCKED)',
        r'cookie', r'facebook', r'twitter', r'status of 40[34]',
    )
]


class StepFailed(Exception):
    pass


def load_creds():
    password = os.environ.get('BGA_STUDIO_PASSWORD')
    if not password:
        return None
    return os.environ.get('BGA_STUDIO_USERNAME') or 'JayadevHaddadi0', password


def game_players(game):
    """(min, max) from gameinfos.jsonc (comments and trailing commas tolerated)."""
    for folder in ('bga', 'bga-prod'):
        info = REPO / 'games' / game / folder / 'gameinfos.jsonc'
        if info.exists():
            text = re.sub(r'^\s*//.*$', '', info.read_text(encoding='utf-8'), flags=re.M)
            m = re.search(r'"players"\s*:\s*\[([^\]]*)\]', text)
            if m:
                nums = [int(n) for n in re.findall(r'\d+', m.group(1))]
                return min(nums), max(nums)
    return 2, 2


class Runner:
    def __init__(self, page, out_dir, game, players):
        self.page, self.out, self.game, self.players = page, Path(out_dir), game, players
        self.out.mkdir(parents=True, exist_ok=True)
        self.shots, self.console, self.results, self.n = [], [], [], 0
        self.table_id = None
        page.on('console', self._on_console)
        page.on('pageerror', lambda e: self.console.append(f'pageerror: {e}'))
        self.net = []
        page.on('response', lambda r: self.net.append(f'{r.status} {r.request.method} {r.url[:140]}') if (r.status >= 400 or re.search(r'account|login|auth', r.url)) else None)
        page.on('dialog', lambda d: (self.console.append(f'js dialog: {d.message}'), d.accept()))

    def _on_console(self, msg):
        if msg.type != 'error':
            return
        text = msg.text
        if not any(p.search(text) for p in IGNORED_CONSOLE):
            self.console.append(f'console.error: {text}')

    def snap(self, name):
        self.n += 1
        path = self.out / f'{self.n:02d}_{name}.png'
        try:
            self.page.screenshot(path=str(path), full_page=False)
            self.shots.append(path.name)
        except Exception as e:  # a screenshot must never mask the real failure
            self.console.append(f'screenshot failed: {e}')
        return path.name

    def dump_dom(self, name):
        try:
            text = self.page.evaluate('document.body ? document.body.innerText : ""')
            (self.out / f'{name}.txt').write_text(text[:20000], encoding='utf-8')
            clickable = self.page.evaluate(
                """() => [...document.querySelectorAll('a,button,[role=button],select,input')]
                    .filter(e => e.offsetParent !== null)
                    .map(e => (e.tagName + ' #' + e.id + ' .' + (e.className||'').toString().slice(0,40) + ' | ' +
                               (e.tagName === 'INPUT' ? ('type=' + e.type + ' name=' + e.name + ' placeholder=' + e.placeholder) : (e.innerText||'')).toString().trim().slice(0,80)))
                    .slice(0, 400)""")
            (self.out / f'{name}_clickable.txt').write_text('\n'.join(clickable), encoding='utf-8')
        except Exception as e:
            self.console.append(f'dom dump failed: {e}')

    def dump_inputs(self, name):
        """Every input with type, name, placeholder and real visibility. Never reads values."""
        try:
            rows = self.page.evaluate(
                """() => [...document.querySelectorAll('input,textarea')].map(e => {
                    const r = e.getBoundingClientRect(), cs = getComputedStyle(e);
                    const vis = r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' && cs.opacity !== '0';
                    return `${vis ? 'VISIBLE' : 'hidden '} type=${e.type} name=${e.name} placeholder=${e.placeholder} autocomplete=${e.autocomplete} class=${(e.className||'').toString().slice(0,50)} w=${Math.round(r.width)}`;
                })""")
            (self.out / f'{name}_inputs.txt').write_text('\n'.join(rows) + '\n\nNETWORK:\n' + '\n'.join(self.net[-60:]), encoding='utf-8')
        except Exception as e:
            self.console.append(f'inputs dump failed: {e}')

    def page_error(self):
        try:
            body = self.page.evaluate('document.body ? document.body.innerText : ""')
        except Exception:
            return None
        for t in ERROR_TEXTS:
            if t.lower() in body.lower():
                i = body.lower().index(t.lower())
                return body[max(0, i - 60): i + 220].replace('\n', ' ')
        return None

    def check(self, what):
        err = self.page_error()
        if err:
            raise StepFailed(f'{what}: error text on page: "{err}"')

    def title(self):
        try:
            return self.page.locator(SELECTORS['title']).first.inner_text(timeout=2000).strip()
        except Exception:
            return ''

    def click_text(self, pattern, timeout=8000, exact_role=None):
        loc = self.page.get_by_text(pattern).first
        loc.wait_for(state='visible', timeout=timeout)
        loc.click()

    # ---- shared steps ------------------------------------------------------------------------------
    def login(self, user, password):
        """Studio login is a two-step modal: "Email or username" + Next, then the password."""
        p = self.page
        p.goto(STUDIO + '/', wait_until='domcontentloaded', timeout=60000)
        user_box = p.get_by_placeholder(USERNAME_PLACEHOLDER).first
        pass_box = p.locator(SELECTORS['password']).first
        try:
            user_box.wait_for(state='visible', timeout=15000)
        except Exception:
            try:
                p.get_by_text(LOGIN_BUTTON_TEXT).first.click(timeout=5000)
                user_box.wait_for(state='visible', timeout=15000)
            except Exception:
                self.snap('login_form_missing')
                self.dump_dom('login_form_missing')
                raise StepFailed('no "Email or username" field found on the Studio page (adjust USERNAME_PLACEHOLDER)')
        user_box.click()
        user_box.press_sequentially(user, delay=40)   # the form validates the name asynchronously; fill() is too fast
        time.sleep(2)
        shown = False
        modal_next = p.locator('a.bga-button:visible', has_text=re.compile(r'^\s*next\s*$', re.I)).first
        for attempt, how in enumerate(('click', 'enter', 'force', 'js')):
            try:
                if how == 'click':
                    p.get_by_text(re.compile(r'^next$', re.I)).first.click(timeout=8000)
                elif how == 'enter':
                    user_box.press('Enter')
                elif how == 'force':
                    modal_next.click(force=True, timeout=8000)
                else:
                    modal_next.evaluate('e => e.click()')
            except Exception as e:
                self.console.append(f'note: Next via {how} failed: {str(e)[:80]}')
            try:
                pass_box.wait_for(state='visible', timeout=8000)
                shown = True
                self.console.append(f'note: password step appeared after Next via {how}')
                break
            except Exception:
                self.snap(f'login_next_{how}')
        if not shown:
            self.dump_dom('login_no_password_step')
            self.dump_inputs('login_no_password_step')
            try:
                info = p.evaluate("() => ({hasBgaConfig: typeof bgaConfig !== 'undefined', keys: typeof bgaConfig !== 'undefined' ? Object.keys(bgaConfig).slice(0, 40) : [], tokenInHtml: document.documentElement.outerHTML.includes('requestToken')})")
                (self.out / 'login_page_config.json').write_text(json.dumps(info, indent=2), encoding='utf-8')
            except Exception as e:
                self.console.append(f'config probe failed: {e}')
            raise StepFailed('no password field after "Next" (unknown username, captcha, or changed flow)')
        pass_box.fill(password)
        p.keyboard.press('Enter')
        try:
            pass_box.wait_for(state='hidden', timeout=30000)
        except Exception:
            self.snap('login_failed')
            self.dump_dom('login_failed')
            raise StepFailed('login did not complete (wrong password, captcha, or changed flow)')
        p.wait_for_load_state('domcontentloaded')
        self.snap('logged_in')

    def open_table(self):
        p = self.page
        p.goto(f'{STUDIO}/gamepanel?game={self.game}', wait_until='domcontentloaded', timeout=60000)
        self.snap('gamepanel')
        self.select_players()
        try:
            self.click_text(EXPRESS_START_TEXT, timeout=20000)
        except Exception:
            self.dump_dom('no_express_start')
            raise StepFailed('no "Express start" control on the game panel (project missing, or page changed)')
        try:
            p.wait_for_url(re.compile(r'[?&]table=\d+'), timeout=90000)
        except Exception:
            self.snap('no_table')
            raise StepFailed('Express start did not open a table')
        self.table_id = re.search(r'table=(\d+)', p.url).group(1)
        self.wait_ready()

    def select_players(self):
        """Studio offers a player-count choice before Express start; the control is unverified."""
        p = self.page
        n = str(self.players)
        try:
            sel = p.locator('select').filter(has=p.locator(f'option[value="{n}"]')).first
            if sel.is_visible(timeout=1500):
                sel.select_option(n)
                return
        except Exception:
            pass
        try:
            p.get_by_role('button', name=re.compile(rf'^{n}$')).first.click(timeout=1500)
        except Exception:
            self.console.append(f'note: could not find a player-count control for {n} players; table uses the default')

    def wait_ready(self, timeout=120000):
        p = self.page
        try:
            p.locator(SELECTORS['title']).first.wait_for(state='visible', timeout=timeout)
            p.wait_for_function(
                "() => { const t = document.querySelector('%s'); return t && t.innerText.trim().length > 0 && "
                "!/loading/i.test(document.body.innerText.slice(0, 4000)); }" % SELECTORS['title'].replace("'", "\\'"),
                timeout=timeout)
        except Exception:
            self.snap('not_ready')
            self.dump_dom('not_ready')
            self.check('game did not finish loading')
            raise StepFailed('game did not finish loading (no status bar text)')

    def run_debug(self, name):
        """Open the Studio debug menu and run debug_<name>."""
        p = self.page
        try:
            self.click_text(DEBUG_MENU_TEXT, timeout=8000)
            p.get_by_text(re.compile(re.escape(name), re.I)).first.click(timeout=8000)
        except Exception:
            self.snap('debug_menu_missing')
            self.dump_dom('debug_menu_missing')
            raise StepFailed(f'Studio debug menu or "{name}" entry not found')

    def wait_end(self, timeout=420):
        end = time.time() + timeout
        while time.time() < end:
            self.check('while playing')
            body = self.page.evaluate('document.body ? document.body.innerText : ""')
            if END_TEXT.search(self.title()) or END_TEXT.search(body[:6000]):
                return True
            time.sleep(3)
        self.snap('stuck')
        self.dump_dom('stuck')
        raise StepFailed(f'game did not end within {timeout}s (stuck state; status bar: "{self.title()}")')

    # ---- scenarios -----------------------------------------------------------------------------------
    def scenario(self, name, fn):
        started = time.time()
        before = len(self.console)
        status, detail = 'pass', ''
        try:
            fn()
            fresh = self.console[before:]
            if fresh:
                status, detail = 'fail', f'{len(fresh)} JS error(s): ' + ' | '.join(fresh[:3])
        except StepFailed as e:
            status, detail = 'fail', str(e)
            self.snap(f'{name}_failed')
        except Exception as e:
            status, detail = 'fail', f'{type(e).__name__}: {e}'
            self.snap(f'{name}_failed')
            self.dump_dom(f'{name}_failed')
        self.results.append({'scenario': name, 'status': status, 'detail': detail, 'seconds': round(time.time() - started, 1)})
        print(f'[{status.upper()}] {name} {detail}')
        return status == 'pass'

    def s_load(self):
        self.check('after load')
        self.snap('loaded')
        if not self.title():
            raise StepFailed('status bar is empty after load')

    def s_refresh(self):
        before = self.title()
        self.page.reload(wait_until='domcontentloaded')
        self.wait_ready()
        self.check('after refresh')
        after = self.title()
        self.snap('refreshed')
        if before and after and before != after:
            raise StepFailed(f'state changed over a refresh: "{before}" -> "{after}"')

    def s_autoplay(self):
        self.run_debug('playToEnd')
        self.wait_end()
        self.snap('game_end')
        self.check('at game end')

    def s_zombie(self):
        """Current player quits; the rest of the game must still be playable to the end."""
        try:
            self.click_text(QUIT_TEXT, timeout=6000)
        except Exception:
            try:
                self.page.locator('#bga-menu-button, .bga-menu, [id*="menu"]').first.click(timeout=3000)
                self.click_text(QUIT_TEXT, timeout=6000)
            except Exception:
                self.dump_dom('quit_missing')
                raise StepFailed('"Quit game" control not found (unverified UI path)')
        try:
            self.click_text(CONFIRM_TEXT, timeout=5000)
        except Exception:
            pass
        time.sleep(5)
        self.check('after a player quit')
        self.snap('after_quit')
        self.s_autoplay()

    def s_replay(self):
        try:
            self.click_text(REPLAY_TEXT, timeout=10000)
        except Exception:
            self.dump_dom('replay_missing')
            raise StepFailed('no replay link found after game end (unverified UI path)')
        time.sleep(8)
        self.check('in replay')
        self.snap('replay')

    def write_reports(self, meta):
        overall = 'pass' if all(r['status'] != 'fail' for r in self.results) else 'fail'
        report = dict(meta, overall=overall, table_id=self.table_id, results=self.results,
                      screenshots=self.shots, console_errors=self.console[:50])
        (self.out / 'report.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
        lines = [f'## E2E {overall.upper()}: {self.game} at {self.players} players',
                 f'Table: {self.table_id or "not created"}  |  Time: {meta["finished"]}', '',
                 '| Scenario | Result | Detail |', '|---|---|---|']
        for r in self.results:
            lines.append(f'| {r["scenario"]} | {r["status"]} | {r["detail"].replace("|", "/")[:300]} |')
        if self.console:
            lines += ['', '**JS errors (first 10):**'] + [f'- `{c[:300]}`' for c in self.console[:10]]
        (self.out / 'report.md').write_text('\n'.join(lines) + '\n', encoding='utf-8')
        return overall


def launch(playwright, headed):
    # Prefer the machine's Chrome when present (no download needed); CI installs Playwright's chromium.
    try:
        return playwright.chromium.launch(channel='chrome', headless=not headed)
    except Exception:
        return playwright.chromium.launch(headless=not headed)


def selftest(out_dir):
    """Checks the runner's own plumbing (console capture, error-text detection, reports) on a local page."""
    from playwright.sync_api import sync_playwright
    html = ('<html><body><div id="pagemaintitletext">Your turn</div><div id="game_play_area">x</div>'
            '<script>console.error("boom from game");</script>'
            '<div id="late"></div><script>setTimeout(()=>document.getElementById("late").innerText='
            '"Unexpected error: Undefined variable $x",200)</script></body></html>')
    with sync_playwright() as pw:
        browser = launch(pw, False)
        page = browser.new_page()
        r = Runner(page, out_dir, 'selftest', 2)
        page.set_content(html)
        time.sleep(0.6)
        problems = []
        if not any('boom from game' in c for c in r.console):
            problems.append('console.error not captured')
        if not r.page_error():
            problems.append('error text not detected')
        if r.title() != 'Your turn':
            problems.append('title not read')
        r.snap('selftest')
        r.results.append({'scenario': 'selftest', 'status': 'pass' if not problems else 'fail',
                          'detail': '; '.join(problems), 'seconds': 0})
        r.write_reports({'game': 'selftest', 'players': 2, 'finished': datetime.datetime.utcnow().isoformat()})
        browser.close()
    print('selftest:', 'OK' if not problems else 'FAILED ' + '; '.join(problems))
    return 0 if not problems else 1


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('game', nargs='?')
    ap.add_argument('--players', default='2', help='a number, or "max" for the game\'s maximum')
    ap.add_argument('--scenarios', default='load,refresh,autoplay,zombie,replay')
    ap.add_argument('--out', help='output folder')
    ap.add_argument('--headed', action='store_true')
    ap.add_argument('--probe', action='store_true', help='log in, open a table, dump the page structure, assert nothing')
    ap.add_argument('--selftest', action='store_true')
    a = ap.parse_args()

    if a.selftest:
        return selftest(a.out or REPO / 'tools' / 'e2e' / 'out' / 'selftest')
    if not a.game:
        ap.error('game required')
    lo, hi = game_players(a.game)
    players = hi if a.players == 'max' else int(a.players)
    if not lo <= players <= hi:
        print(f'{a.game} supports {lo}-{hi} players, not {players}')
        return 2
    creds = load_creds()
    if not creds:
        print('BGA_STUDIO_PASSWORD is not set. This runner is meant for GitHub Actions (repo secret BGA_STUDIO_PASSWORD); for a local run export that env var yourself. Stopping.')
        return 2

    from playwright.sync_api import sync_playwright
    out = Path(a.out) if a.out else REPO / 'tools' / 'e2e' / 'out' / f'{a.game}-{players}p'
    meta = {'game': a.game, 'players': players, 'started': datetime.datetime.utcnow().isoformat()}
    with sync_playwright() as pw:
        browser = launch(pw, a.headed)
        page = browser.new_context(viewport={'width': 1280, 'height': 900}).new_page()
        r = Runner(page, out, a.game, players)
        try:
            r.login(*creds)
            r.open_table()
        except Exception as e:
            r.snap('setup_failed')
            r.dump_dom('setup_failed')
            r.results.append({'scenario': 'setup', 'status': 'fail', 'detail': str(e), 'seconds': 0})
            print('[FAIL] setup', e)
            meta['finished'] = datetime.datetime.utcnow().isoformat()
            r.write_reports(meta)
            browser.close()
            return 1
        if a.probe:
            r.snap('probe_table')
            r.dump_dom('probe_table')
            print('probe done, see', out)
            browser.close()
            return 0
        fns = {'load': r.s_load, 'refresh': r.s_refresh, 'autoplay': r.s_autoplay, 'zombie': r.s_zombie, 'replay': r.s_replay}
        for name in [s.strip() for s in a.scenarios.split(',') if s.strip()]:
            if name == 'zombie':
                # the quit scenario ends the game, so it needs its own table
                r.open_table()
            elif name == 'replay' and 'autoplay' not in a.scenarios:
                continue
            r.scenario(name, fns[name])
        meta['finished'] = datetime.datetime.utcnow().isoformat()
        overall = r.write_reports(meta)
        browser.close()
    print(f'{a.game} {players}p: {overall}  ->  {out}')
    return 0 if overall == 'pass' else 1


if __name__ == '__main__':
    sys.exit(main())
