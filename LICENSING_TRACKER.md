# BGA Games Licensing & Adaptation Tracker

This document tracks publisher/designer permissions, communications, contact info, required credits, and asset availability for games being developed for [Board Game Arena (BGA)](https://studio.boardgamearena.com).

---

## 🚦 Pipeline Status Summary

| Game | Designer | Publisher / Rights Holder | Status | Priority / Phase |
| :--- | :--- | :--- | :--- | :--- |
| **Sugar Gliders** | Néstor Romeral Andrés | Eclipse Editorial / nestorgames / Grok | ✅ **BGA License Granted (2026-10-01)** | Active Dev (`sugargliders`) |
| **KILN** | Néstor Romeral Andrés | Eclipse Editorial / nestorgames | ✅ **BGA License Granted (2026-10-01)** | Active Dev (`kiln`) |
| **nestorgames GP** | Néstor Romeral Andrés | nestorgames | ✅ **BGA License Granted (2026-10-01)** | Active Dev (`nestorgamesgp`) |
| **Gardens of Uranus** | Néstor Romeral Andrés | nestorgames | ✅ **BGA License Granted (2026-10-01)** | Active Dev (`gardensofuranus`) |
| **Omega** | Néstor Romeral Andrés | Eclipse Editorial / nestorgames | ✅ **BGA License Granted (2024-07-24)** | Ready for Testing (`omega`) |
| **Seven** | Néstor Romeral Andrés | Eclipse Editorial / nestorgames | ✅ **BGA License Granted (2024-07-24)** | Scaffolding / Assets In Hand |
| **Taiji** | Néstor Romeral Andrés | Eclipse Editorial / nestorgames | ✅ **BGA License Granted (2024-07-24)** | Backlog / Approved |
| **Pent-Up** | Néstor Romeral Andrés | Eclipse Editorial / nestorgames | ✅ **BGA License Granted (2024-09-05)** | Backlog / Approved |
| **Counterplays** | Néstor Romeral Andrés | Eclipse Editorial / nestorgames | ✅ **BGA License Granted (2024-09-05)** | Backlog / Approved |
| **Stack-22** | Néstor Romeral Andrés | Eclipse Editorial / nestorgames | ✅ **BGA License Granted (2024-09-05)** | Backlog / Approved |
| **Line or Colour** | Néstor Romeral Andrés | Eclipse Editorial / nestorgames | ✅ **BGA License Granted (2025-03-27)** | Backlog / Approved |
| **Push Fight** | Brett Picotte | Brettco Inc. / New Publisher | 🟡 **BGA License Granted (2019-12-13)** | Private Alpha; awaiting new publisher |
| **Mandala** | Trevor Benjamin, Brett J. Gilbert | Lookout Games / Asmodee | ✅ **Live / Released** | Maintained (`mandala`) |
| **Yavalath** | Cameron Browne, Ludi | Cameron Browne / Cyberite Ltd | 🚀 **Public Alpha (2026-10-06)** | Reviewer Testing (`yavalath`) |
| **Lords of Scotland**| Richard James (Evertide Games) | Rights reverted to designer | 🔴 **Blocked: need designer contact** | Core Engine Ready (`lordsofscotland`) |
| **Amazons** | Walter Zamkauskas | nestorgames (edition) | ⚪ Inquiry Sent | Backlog |
| **ConHex** | Michail Antonow | nestorgames (edition) | ⚪ Inquiry Sent | Backlog |

---

## 📋 Detailed Game Logs

### 1. Yavalath
* **Designer**: Cameron Browne (created with AI generator *Ludi*)
* **Rights holder**: Cameron Browne / Cyberite Ltd. Nestor (nestorgames) confirmed he no longer has exclusive rights and sent us to Cameron.
* **Status**: **Public Alpha** (Approved by Cameron; license submission pending via `boardgamearena.com/gamepublishers`).
* **Conditions / Mandatory Credits** (credited in `gameinfos.jsonc`, BGA game description, and in-game rules/help):
  > *"Yes that’s fine, as long as you credit me and Ludi as the inventors of Yavalath. Thanks for checking."* — Cameron Browne
* **Assets**:
  * Complete BGA modern implementation (Standard Side 5, Five-not-four Side 6, Compact Side 4, Pie Rule, 2-3 players).
  * Full BGA metadata assets (280x280 box, 50x50 icon, 1386x400 text-free banner, 280x280 publisher, 900x600 display, 2000x2000 title).
* **Next Actions**:
  * Announce in BGA Reviewers Group (`group?id=5110878`) and Alpha Games Forum (`viewforum.php?f=240`).
  * Submit license confirmation on `boardgamearena.com/gamepublishers` before Beta.
  * Collect 10 reviewer approvals for Beta transition.

---

### 2. Omega
* **Designer / Publisher**: Néstor Romeral Andrés (nestorgames)
* **Status**: **Fully Approved & Assets In Hand**
* **Assets**:
  * Clean SVGs for playing stones provided by Nestor.
  * Board layout: 2-3-4 player variants on hex grid (to be rendered via SVG/CSS).
  * Wordmark/logo requested from Nestor. No box covers exist (cotton-case release): use logos as stylized cover art.
* **Next Actions**:
  * Ready to code board geometry and group-size scoring logic.

---

### 3. Seven
* **Designer / Publisher**: Néstor Romeral Andrés (nestorgames)
* **Status**: **Approved & SVGs In Hand**
* **Assets**:
  * Tile SVGs provided by Nestor.
* **Notes**:
  * "Must place on highest legal level" rule requires clarification / rules-forum verification before finalizing state machine.

---

### 4. Lords of Scotland
* **Designer**: Richard James (design studio: Evertide Games, ©2010). Rulebook credits: illustrations Chris Quilliams, graphic design Philippe Guérin & Karine Tremblay. (Earlier "Richard Sivél" in this file was wrong.)
* **Rights**: Lupe Gonzalez (Studio Big) confirmed ~2026-10 that Z-Man no longer has global rights; they reverted to the designer. Z-Man/Asmodee cannot give approvals or assets without his consent. Card art is also by Chris Quilliams, so ask the designer who owns the art.
* **Replied to Lupe** (cc Britta, BGA) asking for Richard James's contact. Awaiting answer.
* **Fallback contact leads for Richard James**: Evertide Games (evertidegames.blogspot.com, Twitter/Facebook @evertidegames, BGG publisher page 5217), BGG geekmail to Richard James. evertidegames.com no longer resolves.
* **Former publisher**: Z-Man Games (Studio Big / Asmodee Group)
* **Key Contacts**:
  * **Lupe Gonzalez**: Senior Game Producer, Studio Big / Z-Man Games / Office Dog / Unexpected Games (Primary point of contact).
  * **Britta Fisher**: Content Marketing Specialist, Studio Big (Connected us to Lupe Gonzalez).
  * **Asmodee Corporate Contact Form**: Submitted via `asmodee-entertainment.biz` / Asmodee corporate contact.
  * **Asmodee North America Business/Legal**: `inquiries@asmodeena.com`.
* **Status**: Core Implementation Complete & Tested on BGA (`lordsofscotland`); **blocked on the designer's permission** (publisher route closed).
* **Next Actions**:
  * Wait for Lupe's reply with Richard James's contact; meanwhile try the fallback leads above.
  * `PUBLISHER_FOLLOW_UP_EMAIL.md` is now obsolete (addressed the wrong party).
  * Keep logic and rules engine clean and ready for art swap; no alpha request until permission exists.

---

### 5. Sugar Gliders
* **Designer**: Néstor Romeral Andrés
* **Publishers**: nestorgames (Néstor Romeral Andrés) & **Grok Games** (Brazil - Brazilian edition agreement confirmed by Nestor)
* **Status**: **Fully Approved & Complete Implementation on BGA** (`sugargliders`)
* **Conditions / Mandatory Credits**:
  * Credit **nestorgames** and Brazilian publisher **Grok Games**.
  * Listed in `gameinfos.jsonc`, BGA GMM metadata, and game footer.
* **Assets In Hand**:
  * Rules PDF and Strategy Tips PDF.
  * Official board vector art (`SG_board.ai` — 61-space standard canopy + 37-space compact canopy).
  * Official illustrated food tokens (`SG_token1.ai` to `SG_token5.ai` + `SG_token_back.ai`).
  * Official wooden sugar glider meeple silhouette (`SG_token.ai`).
* **Next Actions**:
  * Swap placeholder tokens with official illustrated token assets.
  * Integrate Grok Games in BGA Studio Game Metadata Manager credits.

---

### 6. nestorgames Slate (Inquiry Sent)
* **Titles**: Taiji, Game of the Amazons, ConHex.
* **Contact**: `orders@nestorgames.com`
* **Status**: Inquiry batched in recent reply to Nestor.

---

## 🗂️ Contacts Directory

| Contact Name | Organization / Role | Email / Channel |
| :--- | :--- | :--- |
| **Cameron Browne** | Inventor (Yavalath, Ludi) | `cambolbro@gmail.com` (his `@maastrichtuniversity.nl` address bounces) |
| **Néstor Romeral Andrés** | Founder, nestorgames | `orders@nestorgames.com` |
| **Lupe Gonzalez** | Senior Game Producer, Studio Big (Z-Man / Asmodee) | Follow-up pending (`PUBLISHER_FOLLOW_UP_EMAIL.md`) |
| **Britta Fisher** | Content Marketing Specialist, Studio Big | Email intro from Z-Man |
| **Sophie Gravel** | Former Head of Studio, Z-Man Games | LinkedIn / historical reference |
| **Asmodee Licensing** | Interactive Licensing Team | Corporate Contact Form (`asmodee-entertainment.biz`) |
