# Games status board

Generated 2026-10-27 11:56 by `python3 tools/status_board.py`. Do not edit by hand:
licence and notes come from `tools/games_registry.json`, everything else is read live.

| Game | Studio slug | BGA stage | Production release | Unreleased | Help wiki | Licence | Advertised | Live tests | Next step / notes |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| Kiln | `kiln` | alpha | 261026-0917 | none | in sync | granted | not recorded | - | Beta requested 19 Oct, Nestor confirmed; waiting for BGA |
| Omega | `omega` | alpha | 261025-1537 | none | in sync | granted (2024) | not recorded | - | Public alpha since 21 Oct; tutorial video at beta (Digital Board Gamer) |
| Yavalath | `yavalath` | alpha | 261026-1744 | 1 commits | in sync | Cameron approved; BGA says a submission already exists | not recorded | - | Beta requested; licence entry to be sorted with BGA |
| Sugar Gliders | `sugargliders` | alpha | 261026-0928 | none | in sync | granted | not recorded | - | Public alpha since 20 Oct |
| Gardens of Io | `gardensofio` | alpha | 261026-0929 | none | in sync | given by Nestor | not recorded | PASS 2p, 5p, all scenarios (2026-10-21) | Beta requested 21 Oct; BGA will ask Nestor to confirm |
| Gardens of Enceladus | `gardensofenceladus` | private_alpha | 261021-1533 | none | in sync | given by Nestor | not recorded | PASS 2p, 5p (replay skipped: no Studio archive) (2026-10-19) | Public alpha requested 21 Oct |
| People | `people` | private_alpha | 261026-0929 | none | in sync | Nestor thinks given (check BGA list) | not recorded | PASS 2p (all), 4p (replay skipped) (2026-10-19) | Public alpha requested 22 Oct |
| Pent-Up | `pentup` | private_alpha | 261026-1739 | none | in sync | on file (2024) | not recorded | PASS 2p (replay skipped: no Studio archive) (2026-10-23) | Public alpha requested 23 Oct |
| Microchip | `microchip` | private_alpha | 261026-1738 | none | in sync | given by Nestor | not recorded | PASS 2p, 5p, all scenarios (2026-10-19) | Solo puzzle not implemented yet |
| Red | `red` | private_alpha | 261026-1738 | none | in sync | Nestor thinks given (check BGA list) | not recorded | PASS 3p (all), 2p (replay skipped) (2026-10-19) |  |
| Hippos & Crocodiles | `hipposcrocodiles` | private_alpha | 261026-1739 | none | in sync | MISSING: Nestor must register (BGG 51197) | not recorded | PASS 2p, all scenarios incl. replay (2026-10-19) | Private alpha requested 23 Oct; old project hipposandcrocodiles can be deleted |
| Vault (nestorgames) | `vaultnestorgames` | private_alpha | 261026-1558 | none | in sync | granted | not recorded | Pending first live run (2026-10-24) | Default board 8x8 (owner decision) |
| Gardens of Mars | `gardensofmars` | studio only | - | 47 commits | in sync | given by Nestor | not recorded | - | Private alpha requested 9 Oct (reminder 19 Oct) |
| Gardens of Uranus | `gardensofuranus` | studio only | - | 45 commits | in sync | granted | not recorded | - | Private alpha requested 2 Oct (reminder 19 Oct) |
| nestorgames GP | `nestorgamesgp` | studio only | - | 40 commits | in sync | granted | not recorded | - | Private alpha requested again |
| Little Commanders | `microforge` | studio only | - | 37 commits | in sync | own design | not recorded | - | Project name must become littlecommanders (or rename game back); see reports/little_commanders_review.md |
| Lords of Scotland | `lordsofscotland` | studio only | - | 30 commits | in sync | none (outreach) | not recorded | - | Needs designer/publisher approval |
| Push Fight | `pushfight` | alpha | 261025-1538 | 1 commits | in sync | DECLINED by Tinkerhouse (22 Oct) | not recorded | - | Decide whether to ask BGA to take it down |
| Mandala | `mandala` | public | 261019-0816 | 6 commits | in sync | shared project (Sir Heo) | not recorded | - | Upload-only; always fetch Studio copy before editing |

Stages: studio only → private_alpha → alpha (public) → beta → public. "Unreleased" = commits since the
production build; run `python3 tools/deploy.py auto` to build and deploy them (one game at a time).
Help wiki "differs": run `python3 tools/upload_gamehelp.py <slug>`.
