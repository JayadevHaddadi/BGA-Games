# Browser harness (no BGA needed)

Runs the real `bga/modules/js/Game.js` against a mock `bga` object and checks layout and the main flows.

Serve the game folder and open the frame page (the frame gives a true phone width):

    python3 -m http.server 8000 --directory games/gardensofenceladus
    # then open in Chrome, or run headless:
    google-chrome --headless=new --no-sandbox --dump-dom "http://127.0.0.1:8000/tests/harness/frame.html?mode=turn&w=375"

- `mode=turn` or `mode=placement`; `w=375`, `390` or `1280` (page width).
- The `<pre id="out">` block holds the JSON report: `cell_px` (tap size), `board_centre_offset_px` (0 is centred),
  `horizontal_overflow_px` (0 means no sideways scroll), and one `ok` per flow.
- `index.html?clean=1&zoom=0.8` renders only the game (used for the Control Panel display images).
