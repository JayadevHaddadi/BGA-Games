#!/usr/bin/env python3
"""End-to-end test: play a real BGA Studio table with a headless browser.

  python3 tools/e2e/studio_play.py gardensofenceladus --players 2
  python3 tools/e2e/studio_play.py gardensofenceladus --players max
  python3 tools/e2e/studio_play.py gardensofenceladus --probe     # log in, dump page structure, no assertions
  python3 tools/e2e/studio_play.py --selftest                     # check the runner itself, no login needed

Scenarios (default: all): load, refresh, autoplay, replay (after the game ended), zombie (own table).
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
DEFAULT_USER = 'JayadevHaddadi0'
EXPRESS_START_TEXT = re.compile(r'express start', re.I)
DEBUG_MENU_TEXT = re.compile(r'^debug$', re.I)
QUIT_TEXT = re.compile(r'(quit|leave) (the )?game', re.I)
CONFIRM_TEXT = re.compile(r'^(yes|confirm|ok)$', re.I)
REPLAY_TEXT = re.compile(r'replay', re.I)
REPLAY_BUTTON_TEXT = re.compile(r'^\s*replay game\s*$', re.I)
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
        r'cookie', r'facebook', r'twitter', r'status of 40[034]', r'slideshare', r'privacy-center', r'unpkg', r'browser-update', r'Script load error', r'Failed to load script', r'Content Security Policy',
    )
]


class StepFailed(Exception):
    pass


class StepSkipped(Exception):
    pass


def load_creds():
    password = os.environ.get('BGA_STUDIO_PASSWORD')
    if not password:
        return None
    return os.environ.get('BGA_STUDIO_USERNAME') or DEFAULT_USER, password


def game_players(game):
    """(min, max) from gameinfos.jsonc (comments and trailing commas tolerated)."""
    root = next((d for d in (REPO / 'games').iterdir() if d.name.lower() == game.lower()), REPO / 'games' / game)  # KILN
    for folder in ('bga', 'bga-prod'):
        info = root / folder / 'gameinfos.jsonc'
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
        self.notes = []
        self.table_id = None
        page.on('console', self._on_console)
        page.on('pageerror', lambda e: self.console.append(f'pageerror: {e}'))
        self.net = []
        self.posts = []
        page.on('request', lambda rq: self.posts.append(f'{rq.url[:140]} :: {(rq.post_data or "")[:200]}') if (rq.method == 'POST' and not re.search(r'account|login|auth', rq.url)) else None)
        page.on('response', lambda r: self.net.append(f'{r.status} {r.request.method} {r.url[:140]}') if (r.status >= 400 or re.search(r'account|login|auth', r.url)) else None)
        page.on('dialog', lambda d: (self.console.append(f'js dialog: {d.message}'), d.accept()))

    def _on_console(self, msg):
        if msg.type != 'error':
            return
        text = msg.text
        if not any(p.search(text) for p in IGNORED_CONSOLE):
            self.console.append(f'console.error: {text}')

    def note(self, text):
        self.notes.append(text)
        print('note:', text)

    def snap(self, name):
        self.n += 1
        path = self.out / f'{self.n:02d}_{name}.png'
        try:
            self.page.screenshot(path=str(path), full_page=False)
            self.shots.append(path.name)
        except Exception as e:  # a screenshot must never mask the real failure
            self.console.append(f'screenshot failed: {e}')
        return path.name

    def game_frame(self):
        """The frame that holds the game (Studio wraps it: /tableview?table=N > /<server>/<game>?table=N)."""
        for f in self.page.frames:
            if re.search(rf'/\d+/{self.game}\?', f.url):
                return f
        return self.page.main_frame

    def all_text(self):
        """Visible text of the main page and every frame (the table page may render the game in a frame)."""
        parts = []
        for f in self.page.frames:
            try:
                parts.append(f.evaluate('document.body ? document.body.innerText : ""'))
            except Exception:
                pass
        return '\n'.join(parts)

    def dump_frames(self, name):
        rows = []
        for i, f in enumerate(self.page.frames):
            try:
                n = f.evaluate('document.body ? document.body.innerText.length : -1')
                shadow = f.evaluate('[...document.querySelectorAll("*")].filter(e => e.shadowRoot).length')
                rows.append(f'frame {i}: url={f.url[:120]} textLen={n} shadowHosts={shadow}')
            except Exception as e:
                rows.append(f'frame {i}: error {str(e)[:80]}')
        (self.out / f'{name}_frames.txt').write_text('\n'.join(rows), encoding='utf-8')

    def dump_dom(self, name):
        self.dump_frames(name)
        try:
            text = self.page.evaluate('document.body ? document.body.innerText : ""')
            (self.out / f'{name}.txt').write_text(text[:20000], encoding='utf-8')
            clickable = []
            for i, f in enumerate(self.page.frames):
                try:
                    rows = f.evaluate(
                        """() => [...document.querySelectorAll('a,button,[role=button],select,input,[onclick],[id]')]
                            .filter(e => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0; })
                            .map(e => (e.tagName + ' #' + e.id + ' .' + (e.className||'').toString().slice(0,40) + ' title=' + (e.getAttribute('title')||'') + ' | ' +
                                       (e.tagName === 'INPUT' ? ('type=' + e.type + ' name=' + e.name + ' placeholder=' + e.placeholder) : (e.innerText||'')).toString().trim().slice(0,60)))
                            .slice(0, 500)""")
                except Exception:
                    rows = []
                clickable += [f'[frame {i}] {r}' for r in rows]
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
        body = self.all_text()
        for t in ERROR_TEXTS:
            if t.lower() in body.lower():
                i = body.lower().index(t.lower())
                return body[max(0, i - 60): i + 220].replace('\n', ' ')
        return None

    def check(self, what):
        err = self.page_error()
        if err:
            raise StepFailed(f'{what}: error text on page: "{err}"')

    def header(self):
        """The BGA table header, e.g. "Move #1 Progression 0%" (present in every game)."""
        body = self.all_text()
        m = re.search(r'Move #(\d+)\s*Progression\s*(\d+)\s*%', body)
        return f'Move #{m.group(1)} Progression {m.group(2)}%' if m else ''

    def title(self):
        """Status bar text when the framework exposes it, else the table header."""
        try:
            text = self.page.locator(SELECTORS['title']).first.inner_text(timeout=1500).strip()
            if text:
                return text
        except Exception:
            pass
        return self.header()

    def click_text(self, pattern, timeout=8000, exact_role=None):
        loc = self.page.get_by_text(pattern).first
        loc.wait_for(state='visible', timeout=timeout)
        loc.click()

    # ---- shared steps ------------------------------------------------------------------------------
    def login_api(self, user, password):
        """Post to BGA's own login endpoint from inside the page (the modal login form is fragile in headless runs).
        Returns a summary with only status codes and a short error string: never the password or the response body."""
        return self.page.evaluate("""async ([user, pw]) => {
            const token = (typeof bgaConfig !== 'undefined') ? bgaConfig.requestToken : '';
            const body = new URLSearchParams({username: user, email: user, password: pw, remember_me: 'on', redirect: 'join',
                request_token: token, form_id: 'loginform', 'dojo.preventCache': String(Date.now())});
            const r = await fetch('/account/auth/loginUserWithPassword.html', {method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Request-Token': token}, body});
            let j = null;
            try { j = JSON.parse(await r.text()); } catch (e) {}
            const err = j && (j.error || (j.data && (j.data.error || j.data.message)) || j.exception || null);
            return {http: r.status, hasToken: !!token, status: j ? j.status : null, error: err ? String(err).slice(0, 120) : null,
                    json: !!j};
        }""", [user, password])

    def login(self, user, password):
        p = self.page
        users = [user] + ([DEFAULT_USER] if user != DEFAULT_USER else [])
        summary = []
        for u in users:
            p.goto(STUDIO + '/', wait_until='domcontentloaded', timeout=60000)
            res = self.login_api(u, password)
            summary.append(f'{u}: http={res["http"]} status={res["status"]} error={res["error"]} token={res["hasToken"]}')
            self.note('login ' + summary[-1])
            p.goto(STUDIO + '/', wait_until='domcontentloaded', timeout=60000)
            time.sleep(2)
            logged_out = p.get_by_text(LOGIN_BUTTON_TEXT).first
            try:
                still_out = logged_out.is_visible(timeout=3000)
            except Exception:
                still_out = False
            if not still_out:
                self.snap('logged_in')
                return
        self.snap('login_failed')
        self.dump_dom('login_failed')
        raise StepFailed('login failed (' + ' | '.join(summary) + ')')

    def api(self, path, form):
        """POST a form to a Studio endpoint from inside the logged-in page. Returns status codes and data only."""
        return self.page.evaluate("""async ([path, form]) => {
            const token = (typeof bgaConfig !== 'undefined') ? bgaConfig.requestToken : '';
            const r = await fetch(path, {method: 'POST', credentials: 'same-origin', headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-Requested-With': 'XMLHttpRequest',
                'X-Request-Token': token}, body: new URLSearchParams(form)});
            const t = await r.text(); let j = null; try { j = JSON.parse(t); } catch (e) {}
            return {http: r.status, status: j ? j.status : null, data: j ? j.data : null,
                    error: j ? (j.error || j.exception || null) : null, raw: j ? null : t.slice(0, 160)};
        }""", [path, form])

    def game_id(self):
        self.page.goto(f'{STUDIO}/gamepanel?game={self.game}', wait_until='domcontentloaded', timeout=60000)
        return self.page.evaluate("""async (g) => {
            const t = await (await fetch('/gamepanel?game=' + g, {credentials: 'same-origin'})).text();
            const m = t.match(/id=\\"game_id\\"[^>]*>(\\d+)</) || t.match(/id=.game_id.[^>]*>(\\d+)</);
            return m ? m[1] : null;
        }""", self.game)

    def create_started_table(self):
        """createnew -> wanted players -> Express start, through the endpoints the Studio panel itself calls
        (found in the panel's script: startTable with expressStart posts to /tablemanager/tablemanager/expressStart.html).
        Express start fills the table with the developer test accounts (JayadevHaddadi0, 1, ...). Returns the table id."""
        gid = self.game_id()
        if not gid:
            raise StepFailed('could not read the game id from the game panel (project missing?)')
        res = self.api('/table/table/createnew.html', {'game': str(gid), 'mode': '1'})
        tid = res.get('data', {}).get('table') if isinstance(res.get('data'), dict) else None
        if not tid:
            raise StepFailed(f'createnew failed: http={res.get("http")} error={res.get("error") or res.get("raw")}')
        tid = str(tid)
        res = self.api('/table/table/changeWantedPlayers.html', {'table': tid, 'minp': str(self.players), 'maxp': str(self.players)})
        if str(res.get('status')) != '1':
            raise StepFailed(f'could not set {self.players} players on table {tid}: {res.get("error") or res.get("raw")}')
        res = self.api('/tablemanager/tablemanager/expressStart.html', {'table': tid})
        self.note(f'expressStart table {tid}: http={res.get("http")} status={res.get("status")} error={res.get("error")}')
        if res.get('http') != 200 or str(res.get('status')) != '1':
            raise StepFailed(f'Express start failed for table {tid}: {res.get("error") or res.get("raw")}')
        return tid

    def probe_js(self, name):
        """List the endpoints mentioning "express" in the scripts the Studio pages load (find the real Express start call)."""
        urls = self.page.evaluate("() => [...new Set(performance.getEntriesByType('resource').filter(r => r.initiatorType === 'script' || /\\.js(\\?|$)/.test(r.name)).map(r => r.name))]")
        rows = [f'{len(urls)} scripts loaded']
        for u in urls[:80]:
            try:
                hits = self.page.evaluate("""async (u) => {
                    try {
                        const t = await (await fetch(u, {credentials: 'omit'})).text();
                        const out = [];
                        const re = /.{0,90}express.{0,110}/gi; let m;
                        while ((m = re.exec(t)) && out.length < 6) out.push(m[0].replace(/\\s+/g, ' '));
                        return out;
                    } catch (e) { return []; }
                }""", u)
            except Exception:
                hits = []
            if hits:
                rows.append(f'== {u[:150]}')
                rows += ['   ' + h for h in hits]
        (self.out / f'{name}_express_js.txt').write_text('\n'.join(rows), encoding='utf-8')

    def probe_tables(self):
        """First live run: start a table through the API, open it, and dump the page layout and the debug menu."""
        self.open_table()
        self.snap('probe_table')
        self.dump_dom('probe_table')
        self.dump_debug('probe_table')
        try:
            self.game_frame().locator('[id*="debug" i], [class*="debug" i], [title*="debug" i], [id*="bug" i], [class*="bug" i]').first.click(timeout=5000)
            time.sleep(1.5)
            self.snap('probe_debug_open')
            self.dump_dom('probe_debug_open')
        except Exception as e:
            self.note(f'no debug control to click: {str(e)[:100]}')

    def open_table(self):
        tid = self.create_started_table()
        self.table_id = tid
        time.sleep(5)
        p = self.page
        p.goto(f'{STUDIO}/table?table={tid}', wait_until='domcontentloaded', timeout=60000)
        try:
            p.get_by_text(re.compile(r'^\s*go to game\s*$', re.I)).first.click(timeout=30000)
        except Exception:
            self.snap('no_go_to_game')
            self.dump_dom('no_go_to_game')
            raise StepFailed(f'table {tid} has no "Go to game" button (the game did not start?)')
        self.wait_ready()

    def express_stop(self):
        """Clean up: end the running test table from its table page."""
        if not self.table_id:
            return
        try:
            self.page.goto(f'{STUDIO}/table?table={self.table_id}', wait_until='domcontentloaded', timeout=30000)
            self.page.get_by_text(re.compile(r'^\s*express stop\s*$', re.I)).first.click(timeout=8000)
            time.sleep(3)
        except Exception:
            pass

    def wait_ready(self, timeout=120000):
        end = time.time() + timeout / 1000
        while time.time() < end:
            if self.header() and not re.search(r'loading game|application loading', self.all_text()[:3000], re.I):
                return
            time.sleep(2)
        self.snap('not_ready')
        self.dump_dom('not_ready')
        self.check('game did not finish loading')
        raise StepFailed('game did not finish loading (no "Move #/Progression" table header in any frame)')

    def dump_debug(self, name):
        """Elements that look like the Studio debug control, in every frame, with their HTML (no input values)."""
        rows = []
        for i, f in enumerate(self.page.frames):
            try:
                found = f.evaluate("""() => [...document.querySelectorAll('*')].filter(e => {
                    const t = ((e.id || '') + ' ' + (e.className && e.className.toString ? e.className.toString() : '') + ' ' +
                               (e.getAttribute('title') || '') + ' ' + (e.getAttribute('aria-label') || '')).toLowerCase();
                    return /debug|bug/.test(t);
                }).slice(0, 25).map(e => e.outerHTML.replace(/\\s+/g, ' ').slice(0, 260))""")
            except Exception:
                found = []
            rows += [f'[frame {i}] {x}' for x in found]
        (self.out / f'{name}_debug_candidates.txt').write_text('\n'.join(rows), encoding='utf-8')

    def play_to_end(self):
        """Studio's debug panel (bug icon): the game's own debug_playToEnd button if it has one, otherwise the built-in
        "play moves using zombie code" button, double-clicked to play to the end of the game."""
        gf = self.game_frame()
        try:
            panel = gf.locator('#toggleDebugFunctionsPanel')
            if not panel.is_visible():
                gf.locator('#toggleDebugFunctions').click(timeout=8000)
                time.sleep(1)
            if gf.locator('#debug_playToEnd').count():
                gf.locator('#debug_playToEnd').click(timeout=8000)
                self.note('autoplay via the game\'s debug_playToEnd')
            else:
                # The built-in button's double click sends two concurrent requests, which deadlock in MySQL.
                raise StepFailed('the game has no debug_playToEnd(): add it to Game.php '
                                 '($this->debug->playUntil(...), see gardensofenceladus)')
        except Exception as e:
            self.snap('debug_menu_missing')
            self.dump_dom('debug_menu_missing')
            raise StepFailed(f'Studio debug panel not usable (#toggleDebugFunctions / #debug_play_moves): {str(e)[:100]}')

    def wait_end(self, timeout=420):
        end = time.time() + timeout
        while time.time() < end:
            self.check('while playing')
            body = self.all_text()
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
        except StepSkipped as e:
            status, detail = 'skip', str(e)
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
        self.play_to_end()
        self.wait_end()
        self.snap('game_end')
        self.check('at game end')

    def s_zombie(self):
        """The test account quits (from the table page); the game must carry on or end cleanly, with no error."""
        p = self.page
        p.goto(f'{STUDIO}/table?table={self.table_id}', wait_until='domcontentloaded', timeout=60000)
        try:
            p.get_by_text(QUIT_TEXT).first.click(timeout=10000)
        except Exception:
            self.snap('quit_missing')
            self.dump_dom('quit_missing')
            raise StepFailed('"Quit game" button not found on the table page')
        time.sleep(2)
        self.snap('quit_dialog')
        try:
            self.click_text(CONFIRM_TEXT, timeout=5000)
        except Exception:
            pass
        time.sleep(6)
        self.check('after a player quit')
        self.snap('after_quit')

    def s_replay(self):
        """After the game ended the log shows "Replay game"; it opens a point-of-view choice, then the replay itself.
        Studio writes the game archive a little after the end, so "Unable to find game archive" is retried."""
        game_url = self.page.url
        for attempt in range(1, 5):
            gf = self.game_frame()
            try:
                gf.get_by_text(REPLAY_BUTTON_TEXT).first.click(timeout=10000)
            except Exception:
                self.snap('replay_missing')
                self.dump_dom('replay_missing')
                raise StepFailed('no "Replay game" button in the log after the game ended')
            try:
                self.page.get_by_text(re.compile(r'choose this player', re.I)).first.click(timeout=30000)
            except Exception:
                if 'unable to find game archive' in self.all_text().lower() and attempt < 4:
                    self.note(f'replay attempt {attempt}: the game archive is not written yet; waiting')
                    time.sleep(30)
                    self.page.goto(game_url, wait_until='domcontentloaded', timeout=60000)
                    self.wait_ready()
                    continue
                self.snap('replay_pov_missing')
                self.dump_dom('replay_pov_missing')
                raise StepFailed('the replay did not offer a point-of-view choice ("Choose this player"): '
                                 + (self.page_error() or 'no error text'))
            time.sleep(6)
            missing = 'unable to find game archive' in self.all_text().lower()
            if not missing:
                try:
                    self.wait_ready(120000)
                except StepFailed:
                    missing = 'unable to find game archive' in self.all_text().lower()
                    if not missing:
                        raise
            if missing and attempt < 4:
                self.note(f'replay attempt {attempt}: the game archive is not written yet; waiting')
                time.sleep(30)
                self.page.goto(game_url, wait_until='domcontentloaded', timeout=60000)
                self.wait_ready()
                continue
            if missing:
                raise StepSkipped('Studio did not write the game archive (Studio limitation, not a game bug)')
            self.check('in replay')
            self.snap('replay')
            return

    def write_reports(self, meta):
        overall = 'pass' if all(r['status'] != 'fail' for r in self.results) else 'fail'
        report = dict(meta, overall=overall, table_id=self.table_id, results=self.results,
                      screenshots=self.shots, console_errors=self.console[:50], notes=self.notes)
        if self.posts:
            (self.out / 'posts.txt').write_text('\n'.join(self.posts[-80:]), encoding='utf-8')
        (self.out / 'report.json').write_text(json.dumps(report, indent=2), encoding='utf-8')
        lines = [f'## E2E {overall.upper()}: {self.game} at {self.players} players',
                 f'Table: {self.table_id or "not created"}  |  Time: {meta["finished"]}', '',
                 '| Scenario | Result | Detail |', '|---|---|---|']
        for r in self.results:
            lines.append(f'| {r["scenario"]} | {r["status"]} | {r["detail"].replace("|", "/")[:300]} |')
        if self.notes:
            lines += ['', '**Notes:**'] + [f'- {n[:300]}' for n in self.notes]
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
    ap.add_argument('--scenarios', default='load,refresh,autoplay,replay,zombie')
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
            if a.probe:
                r.probe_tables()
                r.write_reports(dict(meta, finished=datetime.datetime.utcnow().isoformat()))
                r.express_stop()
                print('probe done, see', out)
                browser.close()
                return 0
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
            r.dump_debug('probe_table')
            try:
                p2 = r.page.locator('[id*="debug" i], [class*="debug" i], [title*="debug" i], [class*="bug" i], [id*="bug" i]').first
                p2.click(timeout=5000)
                time.sleep(1.5)
                r.snap('probe_debug_open')
                r.dump_dom('probe_debug_open')
            except Exception as e:
                r.console.append(f'note: no debug control to click: {str(e)[:100]}')
                r.write_reports(dict(meta, finished=datetime.datetime.utcnow().isoformat()))
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
        r.express_stop()
        browser.close()
    print(f'{a.game} {players}p: {overall}  ->  {out}')
    return 0 if overall == 'pass' else 1


if __name__ == '__main__':
    sys.exit(main())
