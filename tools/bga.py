#!/usr/bin/env python3
"""One tool for every BGA Studio game in this repo: scaffold, register, build art, check, fill in the Control Panel.

  python3 tools/bga.py new <slug> --name "Gardens of X" [--bgg 123456] [--prefix gox] [--publisher nestorgames]
  python3 tools/bga.py register <slug> [--test]   # sync.py target + GitHub deploy block (idempotent)
  python3 tools/bga.py assets <slug>              # runs games/<slug>/design/make_assets.py
  python3 tools/bga.py check <slug>               # PASS / WARN / FAIL list; exit 1 on any FAIL
  python3 tools/bga.py gmm <slug>                 # writes games/<slug>/GMM_FILLIN.md from metadata.json
  python3 tools/bga.py sync <slug> [--dry-run]    # fast-lane upload to Studio (needs tools/sftp.config.json)

A new game starts with `new`, then `register`, then the code, then `assets`, `check` and `gmm`.
See BGA_DEVELOPER_CHEAT_SHEET.md section 13 for the full checklist.
"""
import argparse
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
GAMES = REPO / 'games'
SYNC = REPO / 'tools' / 'sync.py'
WORKFLOW = REPO / '.github' / 'workflows' / 'bga-deploy.yml'
ALPHA_ICON_SIZES = {'box_280x280.png': (280, 280), 'icon_50x50.png': (50, 50), 'icon_500x500.png': (500, 500),
                    'banner_1386x400.jpg': (1386, 400), 'banner_1920x556.jpg': (1920, 556),
                    'title_2000x2000.png': (2000, 2000), 'publisher_280x280.png': (280, 280)}

# Things that break BGA's build, its HAL linter, or our guidelines. Scanned in PHP, JS, CSS and SQL.
FAIL_PATTERNS = [
    (r'\bplaySound\b', 'Mistake 11: never name anything playSound (use this.bga.sounds.play or sfx(id))', ['js', 'php']),
    (r'gameui\.playSound', 'deprecated gameui.playSound', ['js']),
    (r'ajaxcall', 'deprecated gameui.ajaxcall (use this.bga.actions.performAction)', ['js']),
    (r'scoreCtrl', 'deprecated this.scoreCtrl (use this.bga.playerPanels.getScoreCounter)', ['js']),
    (r'player_board_', 'direct player board DOM access (use this.bga.playerPanels.getElement)', ['js']),
    (r'TRUNCATE\s+TABLE', 'Mistake 5: TRUNCATE is rejected by HAL (use DELETE FROM)', ['php', 'sql']),
    (r'box-shadow', 'guideline C.3: no drop shadows', ['css', 'js']),
    (r'drop-shadow', 'guideline C.3: no drop shadows', ['css', 'js']),
    (r'transition:\s*all', 'guideline C.3: no transition: all', ['css', 'js']),
    (r'AudioContext|createOscillator', 'guideline D.4: no Web Audio synths (use sound files)', ['js']),
    (r"color:\s*'(danger|red)'", 'Mistake 8: button colour must be alert, primary or secondary', ['js']),
    (r'[\U0001F300-\U0001FAFF\u2600-\u27BF]', 'guideline C.3: no emoji in UI text or labels', ['js', 'css', 'php']),
]
WARN_PATTERNS = [
    (r'animation\s*:', 'guideline C.3: no looping animation (one-shot only, with a reduced-motion guard)', ['css', 'js']),
    (r'mouseenter', 'guideline D.4: hover must not trigger sounds or staging (use @media (hover: hover))', ['js']),
]
EXT_OF = {'js': ('.js',), 'php': ('.php',), 'css': ('.css',), 'sql': ('.sql',)}


class Report:
    def __init__(self):
        self.rows = []

    def add(self, level, name, detail=''):
        self.rows.append((level, name, detail))

    def print(self):
        for level, name, detail in self.rows:
            tail = f' - {detail}' if detail else ''
            print(f'{level:<5} {name}{tail}')
        fails = sum(1 for r in self.rows if r[0] == 'FAIL')
        warns = sum(1 for r in self.rows if r[0] == 'WARN')
        print(f'\n{len(self.rows)} checks: {fails} FAIL, {warns} WARN')
        return fails


def slug_dir(slug):
    return GAMES / slug


def bga_dir(slug):
    # mandala keeps its Studio files in bga-prod; everything else uses bga/
    for name in ('bga', 'bga-prod'):
        if (slug_dir(slug) / name).exists():
            return slug_dir(slug) / name
    return slug_dir(slug) / 'bga'


def read_jsonc(path):
    text = Path(path).read_text(encoding='utf-8')
    out, i, n, in_str = [], 0, len(text), False
    while i < n:
        ch = text[i]
        if in_str:
            out.append(ch)
            if ch == '\\':
                out.append(text[i + 1])
                i += 2
                continue
            if ch == '"':
                in_str = False
            i += 1
            continue
        if ch == '"':
            in_str = True
            out.append(ch)
            i += 1
            continue
        if text.startswith('//', i):
            while i < n and text[i] != '\n':
                i += 1
            continue
        if text.startswith('/*', i):
            i = text.index('*/', i + 2) + 2
            continue
        out.append(ch)
        i += 1
    return json.loads(''.join(out))


def files_under(folder, kinds):
    exts = tuple(e for k in kinds for e in EXT_OF[k])
    out = []
    for root, dirs, names in os.walk(folder):
        dirs[:] = [d for d in dirs if d not in ('node_modules', 'src-disabled', 'vendor')]
        for name in names:
            if name.endswith(exts) and name not in ('_ide_helper.php', 'bga-framework.d.ts'):
                out.append(Path(root) / name)
    return out


# ------------------------------------------------------------------------------------ new

TEMPLATE_GAMEINFOS = '''{
    // Name of the game in English (basis for translation)
    "game_name": "%(name)s",
    "publisher": "%(publisher)s",
    "publisher_website": "%(publisher_site)s",
    "publisher_bgg_id": %(publisher_bgg)s,
    "bgg_id": %(bgg)s,
    "players": [%(players)s],
    "suggest_player_number": null,
    "not_recommend_player_number": null,
    "estimated_duration": 30,
    "fast_additional_time": 30,
    "medium_additional_time": 40,
    "slow_additional_time": 50,
    "tie_breaker_description": "",
    "losers_not_ranked": false,
    "solo_mode_ranked": false,
    "is_coop": 0,
    "language_dependency": false,
    "player_colors": ["ff0000", "008000", "0000ff", "ffa500", "e94190", "982fff", "72c3b1", "f07f16", "bdd002", "7b7b7b", "000000", "ffffff"],
    "favorite_colors_support": true,
    "disable_player_order_swap_on_rematch": false,
    "game_interface_width": { "min": 320 },
    "exception_on_warning": true
}
'''

TEMPLATE_GAME_PHP = '''<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * %(name)s implementation : © %(author)s
 *
 * Game.php - game engine and database access. Rules live in Rules.php (pure PHP, tests/rules_test.php).
 *------
 */

declare(strict_types=1);

namespace Bga\\Games\\%(slug)s;

use Bga\\Games\\%(slug)s\\States\\PlayerTurn;

class Game extends \\Bga\\GameFramework\\Table
{
    public function __construct()
    {
        parent::__construct();
    }

    public function getGameProgression(): int
    {
        // Never leave this at 0 for the whole game (reviewer checklist)
        return 0;
    }

    protected function setupNewGame($players, $options = [])
    {
        // 1. Insert players into the `player` table (see AGENTS.md mistake 1)
        $defaultColors = ['4caf50', 'e91e63', '2196f3', 'ff9800', '9c27b0'];
        $values = [];
        $i = 0;
        foreach ($players as $pid => $player) {
            $values[] = vsprintf("(%%s, '%%s', '%%s')", [$pid, $defaultColors[$i %% count($defaultColors)], addslashes($player['player_name'])]);
            $i++;
        }
        static::DbQuery("INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES " . implode(',', $values));
        // 2. reload the basic infos, 3. init stats, 4. first player
        $this->reloadPlayersBasicInfos();
        $this->playerScore->initDb(array_map('intval', array_keys($players)), 0);
        $this->gamestate->changeActivePlayer((int) array_key_first($players));
        return PlayerTurn::class;
    }

    protected function getAllDatas(int $currentPlayerId): array
    {
        return [];
    }

    public function upgradeTableDb($from_version)
    {
        // First public version: nothing to migrate. dbmodel.sql drops and recreates every table.
    }
}
'''

TEMPLATE_STATE = '''<?php

declare(strict_types=1);

namespace Bga\\Games\\%(slug)s\\States;

use Bga\\GameFramework\\StateType;
use Bga\\GameFramework\\States\\GameState;
use Bga\\GameFramework\\States\\PossibleAction;
use Bga\\Games\\%(slug)s\\Game;

class PlayerTurn extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct($game, id: 30, type: StateType::ACTIVE_PLAYER);
    }

    public function getArgs(): array
    {
        return [];
    }

    #[PossibleAction]
    public function actPass(int $activePlayerId, array $args): string
    {
        return PlayerTurn::class;
    }

    /** Mandatory: every ACTIVE_PLAYER state needs a zombie() (AGENTS.md mistake 2). */
    public function zombie(int $playerId): string
    {
        return PlayerTurn::class;
    }
}
'''

TEMPLATE_GAME_JS = '''/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * %(name)s implementation : © %(author)s
 *
 * Game.js - client interface. The server is the source of truth: the client stages choices and sends one action.
 *------
 */

const %(upper)s_COLOURS = ['blue', 'red', 'yellow', 'green', 'magenta'];

class PlayerTurnState {
    constructor(game) { this.game = game; }
    onEnteringState(args, isActive) { this.game.enterTurn(args, isActive); }
    onUpdateActionButtons(args, isActive) { this.game.updateButtons(args, isActive); }
    onLeavingState() { this.game.leaveState(); }
}

export class Game {
    constructor(bga) {
        this.bga = bga;
        this.stage = null;
        this.args = null;
        this.bga.states.register('PlayerTurn', new PlayerTurnState(this));
    }

    setup(gamedatas) {
        this.gamedatas = gamedatas;
        const root = document.createElement('div');
        root.id = '%(prefix)s_root';
        this.bga.gameArea.getElement().appendChild(root);
        document.body.classList.add('%(prefix)s_page_bg');
        // Without this call the interface only updates after a page refresh (AGENTS.md mistake 12).
        this.bga.notifications.setupPromiseNotifications({});
    }

    enterTurn(args, isActive) { this.args = args; }

    leaveState() { this.stage = null; }

    updateButtons(args, isActive) {
        this.bga.statusBar.removeActionButtons();
    }
}
'''

TEMPLATE_CSS = '''/* %(name)s: play area styles. Guidelines: no shadows, no looping animation, no emoji, radius <= 3px. */

body.%(prefix)s_page_bg {
    background-color: #1d1f24;
}

body.%(prefix)s_page_bg #page-content,
body.%(prefix)s_page_bg #ebd-body,
body.%(prefix)s_page_bg #game_play_area,
body.%(prefix)s_page_bg #game_play_area_background {
    background: transparent;
}
'''

TEMPLATE_WIKI = """==Goal==

Describe the goal in one or two sentences.

==Setup==

* Describe the setup.

==Your turn==

{| class="wikitable"
! # !! Step !! What you do
|-
| 1 || '''Step name''' || What happens.
|}

==Game end==

* When the game ends and how the winner is found.
"""

TEMPLATE_DBMODEL = '''-- %(name)s database model
-- Every table is dropped first, so a table restart never meets an old column layout.
'''

TEMPLATE_STATS = '''{
    // Statistics for the table
    "table": {},

    // Statistics for each player
    "player": {}
}
'''

TEMPLATE_METADATA = {
    "_comment": "Single source for the Control Panel (Game Metadata Manager). Run: python3 tools/bga.py gmm <slug>",
    "slug": None,
    "studio_project": None,
    "game_name": None,
    "bgg_id": None,
    "bgg_url": None,
    "year_published": None,
    "players": None,
    "estimated_duration_minutes": 30,
    "publisher": {"name": None, "bgg_id": None, "website": None},
    "designers": [],
    "artists": [],
    "rules_edits": [],
    "rules_url": None,
    "description": "TODO: 1 to 2 paragraphs in English about the theme, the components and the objective.",
    "zombie_mode_level": 1,
    "tags": [],
    "tags_note": "PROPOSAL: pick from BGA's tag list in the Control Panel.",
    "characteristics": {"complexity": 3, "luck": 2, "strategy": 3, "diplomacy": 1,
                        "note": "PROPOSAL: sliders 1 to 5 in the Control Panel only."},
    "licence": {"status": "NOT CONFIRMED", "note": "Add a row to LICENSING_TRACKER.md and confirm before publisher sign-off."},
    "assets": {
        "box": "metadata_assets/box_280x280.png",
        "icon": "metadata_assets/icon_50x50.png",
        "icon_hi": "metadata_assets/icon_500x500.png",
        "banner": "metadata_assets/banner_1386x400.jpg",
        "banner_wide": "metadata_assets/banner_1920x556.jpg",
        "title": "metadata_assets/title_2000x2000.png",
        "publisher": "metadata_assets/publisher_280x280.png",
        "display": ["metadata_assets/display_1.jpg", "metadata_assets/display_2.jpg"]
    }
}

TEMPLATE_ASSETS = '''"""Builds the %(name)s art: board and sprite crops here, metadata images via tools/bga_assets.py.

Run: python3 tools/bga.py assets %(slug)s
"""
from pathlib import Path
import sys

GAME = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(GAME.parent.parent / 'tools'))
from bga_assets import build_metadata  # noqa: E402

if __name__ == '__main__':
    # TODO: crop or draw the gameplay art into bga/img/ (keep it small: BGA preloads img/)
    build_metadata(GAME, {
        'wordmark': 'TODO_title.png',            # title art in the game folder
        'flowers': [],                           # up to five sprites, e.g. 'bga/img/piece_1.png'
        'peak': None,
        'background': [29, 31, 36],
        'publisher_logo': None,                  # e.g. '../Nestor Games Logo.jpg'
        'publisher_tint': None,
    })
    print('metadata images written')
'''


def cmd_new(args):
    slug = args.slug.lower()
    folder = slug_dir(slug)
    if folder.exists() and any(folder.iterdir()):
        sys.exit(f'{folder} already exists and is not empty')
    bga = folder / 'bga'
    for sub in ['modules/php/States', 'modules/js', 'img', 'sounds', 'metadata_assets', 'misc']:
        (bga / sub).mkdir(parents=True, exist_ok=True)
    (folder / 'design').mkdir(exist_ok=True)
    (folder / 'tests').mkdir(exist_ok=True)
    prefix = args.prefix or re.sub(r'[^a-z0-9]', '', slug)[:6]
    publisher = args.publisher
    values = {
        'name': args.name, 'slug': slug, 'prefix': prefix, 'upper': prefix.upper(),
        'author': 'Jayadev Haddadi', 'bgg': args.bgg or 0, 'publisher': publisher,
        'publisher_site': args.publisher_site or '', 'publisher_bgg': args.publisher_bgg or 0,
        'players': ', '.join(str(p) for p in range(args.players[0], args.players[1] + 1)),
    }
    write = lambda p, text: (p.parent.mkdir(parents=True, exist_ok=True), p.write_text(text, encoding='utf-8'))
    write(bga / 'gameinfos.jsonc', TEMPLATE_GAMEINFOS % values)
    write(bga / 'gameoptions.jsonc', '{\n  // option ids should be between 100 and 199\n}\n')
    write(bga / 'gamepreferences.jsonc', '{\n  // user preference ids should be between 100 and 199\n}\n')
    write(bga / 'stats.jsonc', TEMPLATE_STATS)
    write(bga / 'dbmodel.sql', TEMPLATE_DBMODEL % values)
    write(bga / 'GAMEHELP.wiki', TEMPLATE_WIKI)
    write(bga / 'modules' / 'php' / 'Game.php', TEMPLATE_GAME_PHP % values)
    write(bga / 'modules' / 'php' / 'States' / 'PlayerTurn.php', TEMPLATE_STATE % values)
    write(bga / 'modules' / 'js' / 'Game.js', TEMPLATE_GAME_JS % values)
    write(bga / f'{slug}.css', TEMPLATE_CSS % values)
    write(folder / 'design' / 'make_assets.py', TEMPLATE_ASSETS % values)
    meta = dict(TEMPLATE_METADATA)
    meta.update({'slug': slug, 'studio_project': slug, 'game_name': args.name, 'bgg_id': args.bgg or None,
                 'bgg_url': f'https://boardgamegeek.com/boardgame/{args.bgg}' if args.bgg else None,
                 'publisher': {'name': publisher, 'bgg_id': args.publisher_bgg, 'website': args.publisher_site}})
    write(folder / 'metadata.json', json.dumps(meta, indent=2, ensure_ascii=False) + '\n')
    for keep in [bga / 'img' / '.gitkeep', bga / 'sounds' / '.gitkeep', bga / 'metadata_assets' / '.gitkeep']:
        keep.touch()
    print(f'scaffolded {folder}')
    cmd_register(argparse.Namespace(slug=slug, test=False, name=args.name))
    print('next: write the rules and tests, then `assets`, `check` and `gmm`')


# ------------------------------------------------------------------------------------ register

def cmd_register(args):
    slug = args.slug.lower()
    name = getattr(args, 'name', None) or read_name(slug)
    sync = SYNC.read_text(encoding='utf-8')
    if f'"{slug}":' not in sync:
        lines = [f'    "{slug}": ("games/{slug}/bga", "{slug}"),']
        if getattr(args, 'test', False):
            lines.append(f'    "{slug}test": ("games/{slug}/bga", "{slug}"),')
        sync = sync.replace('    # <bga:targets>', '\n'.join(lines) + '\n    # <bga:targets>', 1)
        SYNC.write_text(sync, encoding='utf-8')
        print(f'sync.py: added {slug}')
    else:
        print(f'sync.py: {slug} already registered')

    wf = WORKFLOW.read_text(encoding='utf-8')
    if f'          - {slug}\n' not in wf:
        wf = wf.replace('          # <bga:options>', f'          - {slug}\n          # <bga:options>', 1)
        print('workflow: added choice option')
    if f'games/{slug}' not in wf:
        block = (f'            if echo "$CHANGED" | grep -qi "games/{slug}"; then\n'
                 f'              echo "Deploying {name}..."\n'
                 f'              python tools/sync.py {slug}\n'
                 f'              DEPLOYED=1\n'
                 f'            fi\n\n')
        wf = wf.replace('            # <bga:deploy>', block + '            # <bga:deploy>', 1)
        print('workflow: added deploy block')
    WORKFLOW.write_text(wf, encoding='utf-8')


def read_name(slug):
    gi = bga_dir(slug) / 'gameinfos.jsonc'
    return read_jsonc(gi)['game_name'] if gi.exists() else slug


# ------------------------------------------------------------------------------------ assets

def cmd_assets(args):
    script = slug_dir(args.slug) / 'design' / 'make_assets.py'
    if not script.exists():
        sys.exit(f'no {script}')
    subprocess.run([sys.executable, '-W', 'ignore', str(script)], check=True)


# ------------------------------------------------------------------------------------ check

def cmd_check(args):
    slug = args.slug.lower()
    folder = slug_dir(slug)
    bga = bga_dir(slug)
    rep = Report()
    if not folder.exists():
        sys.exit(f'no game folder {folder}')

    # 1. Required files
    required = ['gameinfos.jsonc', 'gameoptions.jsonc', 'gamepreferences.jsonc', 'stats.jsonc', 'dbmodel.sql',
                'GAMEHELP.wiki', 'modules/php/Game.php', 'modules/js/Game.js', f'{slug}.css']
    for rel in required:
        (rep.add('PASS', f'file {rel}') if (bga / rel).exists() else rep.add('FAIL', f'file {rel}', 'missing'))
    (rep.add('PASS', 'metadata.json') if (folder / 'metadata.json').exists() else rep.add('FAIL', 'metadata.json', 'missing'))

    # 2. Syntax: PHP lint, JS syntax, JSON in the config files
    for php in files_under(bga, ['php']):
        out = subprocess.run(['php', '-l', str(php)], capture_output=True, text=True)
        if out.returncode != 0:
            rep.add('FAIL', f'php -l {php.relative_to(folder)}', out.stdout.strip() or out.stderr.strip())
    if not any(r[1].startswith('php -l') and r[0] == 'FAIL' for r in rep.rows):
        rep.add('PASS', 'php -l on every PHP file')
    for js in files_under(bga / 'modules', ['js']):
        with tempfile.TemporaryDirectory() as tmp:
            copy = Path(tmp) / 'check.mjs'
            shutil.copy(js, copy)
            out = subprocess.run(['node', '--check', str(copy)], capture_output=True, text=True)
            if out.returncode != 0:
                rep.add('FAIL', f'node --check {js.relative_to(folder)}', out.stderr.strip().splitlines()[0] if out.stderr else 'syntax error')
    for name in ['gameinfos.jsonc', 'gameoptions.jsonc', 'gamepreferences.jsonc', 'stats.jsonc']:
        if (bga / name).exists():
            try:
                read_jsonc(bga / name)
            except (ValueError, json.JSONDecodeError) as e:
                rep.add('FAIL', f'JSONC {name}', str(e))
    css = bga / f'{slug}.css'
    if css.exists():
        text = css.read_text(encoding='utf-8')
        if text.count('{') != text.count('}'):
            rep.add('FAIL', 'css braces balance', f'{text.count("{")} open, {text.count("}")} close')
        else:
            rep.add('PASS', 'css braces balance')

    # 3. Banned patterns
    banned_hits = 0
    for level, table in (('FAIL', FAIL_PATTERNS), ('WARN', WARN_PATTERNS)):
      for pattern, why, kinds in table:
        hits = []
        for path in files_under(bga, kinds):
            if 'src-disabled' in path.parts:
                continue
            for n, line in enumerate(path.read_text(encoding='utf-8', errors='replace').splitlines(), 1):
                if re.search(pattern, line):
                    hits.append(f'{path.relative_to(folder)}:{n}')
        if hits:
            banned_hits += 1
            rep.add(level, why, ', '.join(hits[:4]) + (' ...' if len(hits) > 4 else ''))
    if banned_hits == 0:
        rep.add('PASS', 'no banned patterns (playSound, ajaxcall, scoreCtrl, shadows, emoji, TRUNCATE, danger colour)')

    # 4. Wiki: MediaWiki, not Markdown
    wiki = bga / 'GAMEHELP.wiki'
    if wiki.exists():
        text = wiki.read_text(encoding='utf-8')
        md = [n for n, line in enumerate(text.splitlines(), 1) if re.match(r'^#{1,6} ', line) or line.startswith('**')]
        (rep.add('FAIL', 'GAMEHELP.wiki is MediaWiki', f'Markdown on lines {md[:5]}') if md else rep.add('PASS', 'GAMEHELP.wiki is MediaWiki'))
        (rep.add('PASS', 'GAMEHELP.wiki has headings') if '==' in text else rep.add('FAIL', 'GAMEHELP.wiki has headings'))

    # 5. Config values
    if (bga / 'gameinfos.jsonc').exists():
        gi = read_jsonc(bga / 'gameinfos.jsonc')
        (rep.add('PASS', 'bgg_id set') if gi.get('bgg_id') else rep.add('FAIL', 'bgg_id set', 'add "bgg_id" to gameinfos.jsonc (AGENTS.md section 5.B.6)'))
        (rep.add('PASS', 'publisher set') if gi.get('publisher') else rep.add('WARN', 'publisher set'))
        width = (gi.get('game_interface_width') or {}).get('min')
        (rep.add('PASS', 'game_interface_width min 320') if width == 320 else rep.add('FAIL', 'game_interface_width min 320', f'is {width}'))
        for key in ['gameinfos.jsonc']:
            if any(k in gi for k in ['complexity', 'luck', 'strategy', 'diplomacy']):
                rep.add('FAIL', 'characteristics only in the Control Panel', 'remove complexity/luck/strategy/diplomacy from gameinfos.jsonc')
    for name, lo in [('gameoptions.jsonc', 'option'), ('gamepreferences.jsonc', 'preference')]:
        if (bga / name).exists():
            # top-level keys only: the values inside an option ("1", "2") are not ids
            ids = [int(k) for k in read_jsonc(bga / name).keys()]
            bad = [i for i in ids if not 100 <= i <= 199]
            (rep.add('PASS', f'{lo} ids in 100-199') if not bad else rep.add('FAIL', f'{lo} ids in 100-199', str(bad)))

    # 6. Every ACTIVE_PLAYER / MULTIPLE_ACTIVE_PLAYER state has zombie()
    missing = []
    for php in files_under(bga / 'modules' / 'php' / 'States', ['php']):
        text = php.read_text(encoding='utf-8')
        if re.search(r'StateType::(ACTIVE_PLAYER|MULTIPLE_ACTIVE_PLAYER)', text) and 'function zombie(' not in text:
            missing.append(php.name)
    (rep.add('PASS', 'zombie() in every active state') if not missing else rep.add('FAIL', 'zombie() in every active state', ', '.join(missing)))

    # 7. Notifications: every server notification has a client handler, and handlers are registered
    php_notifs = set()
    notify_patterns = [r"notifyAllPlayers\(\s*'(\w+)'", r"notifyPlayer\([^,]+,\s*'(\w+)'",
                       r"notify->all\(\s*'(\w+)'", r"notify->player\([^,]+,\s*'(\w+)'"]
    for php in files_under(bga / 'modules' / 'php', ['php']):
        text = php.read_text(encoding='utf-8')
        for pattern in notify_patterns:
            php_notifs.update(re.findall(pattern, text))
    js_text = (bga / 'modules' / 'js' / 'Game.js').read_text(encoding='utf-8') if (bga / 'modules' / 'js' / 'Game.js').exists() else ''
    js_handlers = set(re.findall(r'notif_(\w+)\s*\(', js_text))
    no_handler = sorted(php_notifs - js_handlers)
    (rep.add('PASS', f'notification handlers ({len(php_notifs)} server notifications)') if not no_handler
     else rep.add('FAIL', 'notification handlers', 'no notif_<name> for: ' + ', '.join(no_handler)))
    (rep.add('PASS', 'setupPromiseNotifications called') if 'setupPromiseNotifications' in js_text
     else rep.add('FAIL', 'setupPromiseNotifications called', 'Mistake 12: the UI will not update without it'))

    # 8. Sounds: each sound id used in the client exists as .ogg and .mp3 in the top-level sounds/ folder
    sound_ids = set(re.findall(r"sfx\('(\w+)'\)|sounds\.play\('(\w+)'\)", js_text))
    sound_ids = {next(g for g in t if g) for t in sound_ids}
    missing_sound = [s for s in sorted(sound_ids) if not ((bga / 'sounds' / f'{s}.ogg').exists() and (bga / 'sounds' / f'{s}.mp3').exists())]
    (rep.add('PASS', f'sounds ({len(sound_ids)} ids, ogg + mp3)') if not missing_sound
     else rep.add('FAIL', 'sounds ogg + mp3 present', ', '.join(missing_sound)))
    if (bga / 'img').exists():
        stray = [p.name for p in (bga / 'img').iterdir() if re.match(r'(box|banner|icon|publisher|display|title)', p.name)]
        (rep.add('FAIL', 'no metadata images in img/', ', '.join(stray) + ' (move to metadata_assets/)') if stray
         else rep.add('PASS', 'no metadata images in img/'))
        total = sum(p.stat().st_size for p in (bga / 'img').rglob('*') if p.is_file())
        (rep.add('PASS', f'img/ size {total // 1024} KB') if total <= 1500 * 1024
         else rep.add('WARN', f'img/ size {total // 1024} KB', 'BGA preloads img/: keep it under about 1.5 MB'))

    # 9. Stats used in code exist in stats.jsonc
    if (bga / 'stats.jsonc').exists():
        st = read_jsonc(bga / 'stats.jsonc')
        known_t, known_p = set(st.get('table', {})), set(st.get('player', {}))
        code = '\n'.join(p.read_text(encoding='utf-8') for p in files_under(bga / 'modules' / 'php', ['php']))
        used_t = set(re.findall(r"tableStats->(?:inc|set)\('(\w+)'", code))
        used_p = set(re.findall(r"playerStats->(?:inc|set)\('(\w+)'", code))
        for m in re.finditer(r"(table|player)Stats->init\(\s*\[([^\]]*)\]", code):
            names = set(re.findall(r"'(\w+)'", m.group(2)))
            (used_t if m.group(1) == 'table' else used_p).update(names)
        bad = sorted((used_t - known_t) | (used_p - known_p))
        (rep.add('PASS', 'stats used in code exist in stats.jsonc') if not bad else rep.add('FAIL', 'stats used in code exist in stats.jsonc', ', '.join(bad)))

    # 10. SQL: DROP before CREATE, no inline comments on column lines
    sql = bga / 'dbmodel.sql'
    if sql.exists():
        text = sql.read_text(encoding='utf-8')
        creates = re.findall(r'CREATE TABLE IF NOT EXISTS `(\w+)`', text)
        drops = set(re.findall(r'DROP TABLE IF EXISTS `(\w+)`', text))
        no_drop = [t for t in creates if t not in drops]
        (rep.add('PASS', 'DROP TABLE IF EXISTS before each CREATE') if not no_drop else rep.add('WARN', 'DROP TABLE IF EXISTS before each CREATE', ', '.join(no_drop)))
        inline = [n for n, line in enumerate(text.splitlines(), 1)
                  if re.match(r'^\s*`\w+`\s', line) and '--' in line]
        (rep.add('PASS', 'no inline comments on column lines') if not inline else rep.add('FAIL', 'no inline comments on column lines', f'lines {inline[:5]} (Mistake 9)'))

    # 11. Metadata images: present and the right size
    meta_dir = bga / 'metadata_assets'
    try:
        from PIL import Image
        for name, size in ALPHA_ICON_SIZES.items():
            p = meta_dir / name
            if not p.exists():
                rep.add('FAIL', f'metadata image {name}', 'missing: run tools/bga.py assets ' + slug)
                continue
            got = Image.open(p).size
            (rep.add('PASS', f'metadata image {name}') if got == size else rep.add('FAIL', f'metadata image {name}', f'{got}, expected {size}'))
        for name in ['display_1.jpg', 'display_2.jpg']:
            p = meta_dir / name
            if not p.exists():
                rep.add('FAIL', f'metadata image {name}', 'missing')
                continue
            w, h = Image.open(p).size
            ok = (h >= 400 and h <= 760 and w <= 1.5 * h)
            (rep.add('PASS', f'metadata image {name} {w}x{h}') if ok else rep.add('WARN', f'metadata image {name} {w}x{h}', 'height 400 to 760, width at most 1.5 x height'))
            if w == 1000 and h == 750 and meta_dir.joinpath(name).stat().st_size < 20000:
                rep.add('WARN', f'metadata image {name}', 'looks like the placeholder: render a real screenshot (tests/harness)')
    except ImportError:
        rep.add('WARN', 'metadata image sizes', 'Pillow not installed, sizes not checked')

    # 12. metadata.json: what the Control Panel needs
    mj = folder / 'metadata.json'
    if mj.exists():
        meta = json.loads(mj.read_text(encoding='utf-8'))
        if (bga / 'gameinfos.jsonc').exists() and meta.get('bgg_id') != read_jsonc(bga / 'gameinfos.jsonc').get('bgg_id'):
            rep.add('FAIL', 'metadata.json bgg_id matches gameinfos.jsonc')
        desc = meta.get('description') or ''
        if desc.startswith('TODO') or len(desc) < 200:
            rep.add('FAIL' if desc.startswith('TODO') else 'WARN', 'GMM description', 'write 1 to 2 English paragraphs (AGENTS.md 5.B.2)')
        else:
            rep.add('PASS', 'GMM description')
        (rep.add('PASS', 'designers listed') if meta.get('designers') else rep.add('WARN', 'designers listed'))
        status = (meta.get('licence') or {}).get('status', '')
        (rep.add('WARN', 'licence status', status) if 'NOT' in status or not status else rep.add('PASS', 'licence status', status))
        if 'TODO' in json.dumps(meta):
            rep.add('WARN', 'metadata.json has TODO fields')

    # 13. Wiring: sync.py, workflow, harness
    sync_text = SYNC.read_text(encoding='utf-8')
    (rep.add('PASS', 'tools/sync.py target registered') if f'"{slug}":' in sync_text else rep.add('FAIL', 'tools/sync.py target registered', f'run: python3 tools/bga.py register {slug}'))
    wf_text = WORKFLOW.read_text(encoding='utf-8')
    (rep.add('PASS', 'deploy workflow wired') if f'games/{slug}' in wf_text and f'          - {slug}' in wf_text
     else rep.add('FAIL', 'deploy workflow wired', f'run: python3 tools/bga.py register {slug}'))

    # 14. Rules tests and the browser harness
    for test in sorted((folder / 'tests').glob('*_test.php')):
        out = subprocess.run(['php', str(test)], capture_output=True, text=True)
        last = (out.stdout.strip().splitlines() or ['no output'])[-1]
        (rep.add('PASS', f'{test.name}: {last}') if out.returncode == 0 else rep.add('FAIL', f'{test.name}', last))
    if not list((folder / 'tests').glob('*_test.php')):
        rep.add('WARN', 'rules tests', 'no tests/*_test.php: write the pure rules as CLI checks first')
    if not (folder / 'tests' / 'harness' / 'index.html').exists():
        rep.add('WARN', 'browser harness', 'no tests/harness: check the UI at 375px and 1280px before asking for review')

    fails = rep.print()
    return 1 if fails else 0


# ------------------------------------------------------------------------------------ gmm

def cmd_gmm(args):
    slug = args.slug.lower()
    folder = slug_dir(slug)
    meta = json.loads((folder / 'metadata.json').read_text(encoding='utf-8'))
    gi = read_jsonc(bga_dir(slug) / 'gameinfos.jsonc') if (bga_dir(slug) / 'gameinfos.jsonc').exists() else {}
    chars = meta.get('characteristics', {})
    lines = [
        f"# {meta.get('game_name')}: Control Panel fill-in",
        '',
        '_Generated by `python3 tools/bga.py gmm ' + slug + '` from `metadata.json`. Edit the JSON, not this file._',
        '',
        '## Required before Private Alpha (blocking)',
        '',
        f"* **Description** (English, 1 to 2 paragraphs): see below.",
        f"* **Zombie mode level**: Level {meta.get('zombie_mode_level', 1)}.",
        '',
        '## Game details',
        '',
        f"* Game name: {meta.get('game_name')}",
        f"* Studio project: `{meta.get('studio_project')}`",
        f"* BGG ID: {meta.get('bgg_id')} (set in gameinfos.jsonc, not editable in the Control Panel): {meta.get('bgg_url')}",
        f"* Year published: {meta.get('year_published')}",
        f"* Players: {meta.get('players')}",
        f"* Estimated duration: {meta.get('estimated_duration_minutes')} minutes",
        f"* Publisher: {(meta.get('publisher') or {}).get('name')} (BGG {(meta.get('publisher') or {}).get('bgg_id')})",
        f"* Designers: {', '.join(meta.get('designers', [])) or 'TODO'}",
        f"* Artists: {', '.join(meta.get('artists', [])) or 'not credited in the rulebook: leave empty or confirm'}",
        f"* Rules edits: {', '.join(meta.get('rules_edits', [])) or 'none'}",
        f"* English rules link: {meta.get('rules_url')}",
        '',
        '## Characteristics (sliders 1 to 5, Control Panel only)',
        '',
        f"* Complexity: {chars.get('complexity')}",
        f"* Luck: {chars.get('luck')}",
        f"* Strategy: {chars.get('strategy')}",
        f"* Diplomacy: {chars.get('diplomacy')}",
        f"* {chars.get('note', '')}",
        '',
        '## Tags',
        '',
    ] + [f'* {t}' for t in meta.get('tags', [])] + [
        '',
        f"_{meta.get('tags_note', '')}_",
        '',
        '## Images (upload from bga/metadata_assets/)',
        '',
    ]
    for key, rel in (meta.get('assets') or {}).items():
        items = rel if isinstance(rel, list) else [rel]
        for item in items:
            lines.append(f'* {key}: `{item}`')
    lines += [
        '',
        '## Description (paste)',
        '',
        meta.get('description', ''),
        '',
        '## Licence',
        '',
        f"* Status: {(meta.get('licence') or {}).get('status')}",
        f"* Note: {(meta.get('licence') or {}).get('note')}",
        '',
        '## Before you request Private Alpha',
        '',
        '* `python3 tools/bga.py check ' + slug + '` has no FAIL lines.',
        '* Screenshots at 375 px and 1280 px pass the layout checks (`tests/harness`).',
        '* The GMM warnings about licence and tags can be ignored for Private Alpha (AGENTS.md 5.C).',
        '',
    ]
    out = folder / 'GMM_FILLIN.md'
    out.write_text('\n'.join(lines), encoding='utf-8')
    print(f'wrote {out}')


# ------------------------------------------------------------------------------------ sync

def cmd_sync(args):
    cmd = [sys.executable, str(SYNC), args.slug.lower()] + (['--dry-run'] if args.dry_run else [])
    raise SystemExit(subprocess.run(cmd).returncode)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest='command', required=True)

    p = sub.add_parser('new', help='scaffold a game folder and register it')
    p.add_argument('slug', help='Studio project name, lowercase, no spaces (e.g. gardensofenceladus)')
    p.add_argument('--name', required=True, help='game name as shown on BGA')
    p.add_argument('--bgg', type=int, help='BGG id of the game')
    p.add_argument('--prefix', help='CSS and sound prefix (default: slug without digits, 6 letters)')
    p.add_argument('--publisher', default='', help='publisher name')
    p.add_argument('--publisher-bgg', type=int, dest='publisher_bgg')
    p.add_argument('--publisher-site', dest='publisher_site', default='')
    p.add_argument('--players', nargs=2, type=int, default=[2, 4], metavar=('MIN', 'MAX'))
    p.set_defaults(func=cmd_new)

    p = sub.add_parser('register', help='add the game to tools/sync.py and the deploy workflow (idempotent)')
    p.add_argument('slug')
    p.add_argument('--test', action='store_true', help='also add a <slug>test sandbox target')
    p.set_defaults(func=cmd_register)

    p = sub.add_parser('assets', help='run games/<slug>/design/make_assets.py')
    p.add_argument('slug')
    p.set_defaults(func=cmd_assets)

    p = sub.add_parser('check', help='pre-push and pre-review checks')
    p.add_argument('slug')
    p.set_defaults(func=cmd_check)

    p = sub.add_parser('gmm', help='write GMM_FILLIN.md for the Control Panel')
    p.add_argument('slug')
    p.set_defaults(func=cmd_gmm)

    p = sub.add_parser('sync', help='fast-lane upload to BGA Studio')
    p.add_argument('slug')
    p.add_argument('--dry-run', action='store_true')
    p.set_defaults(func=cmd_sync)

    args = parser.parse_args()
    result = args.func(args)
    if isinstance(result, int):
        sys.exit(result)


if __name__ == '__main__':
    main()
