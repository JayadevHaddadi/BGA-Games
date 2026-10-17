Harness screenshots used for the Control Panel display images (see design/make_assets.py).

Serve the game folder and capture (Chrome, 1280 px):

    cd games/people && python3 -m http.server 8765
    google-chrome --headless=new --no-sandbox --window-size=1280,1000 --virtual-time-budget=6000 \
        --screenshot=design/screens/harness_factory_2p.png "http://localhost:8765/tests/harness/index.html?players=2&mode=factory"
    google-chrome --headless=new --no-sandbox --window-size=1280,1000 --virtual-time-budget=6000 \
        --screenshot=design/screens/harness_bribe_4p.png "http://localhost:8765/tests/harness/index.html?players=4&mode=bribe"
