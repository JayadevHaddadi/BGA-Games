# BGA Games Licensing & Adaptation Tracker

This document tracks publisher/designer permissions, communications, contact info, required credits, and asset availability for games being developed for [Board Game Arena (BGA)](https://studio.boardgamearena.com).

---

## 🚦 Pipeline Status Summary

| Game | Designer | Publisher / Rights Holder | Status | Priority / Phase |
| :--- | :--- | :--- | :--- | :--- |
| **Push Fight** | Brett J. Gilbert | Push Fight LLC | ✅ **Live / Released** | Maintained |
| **Mandala** | Trevor Benjamin, Brett J. Gilbert | Lookout Games / Asmodee | ✅ **Live / Released** | Maintained |
| **Yavalath** | Cameron Browne, Ludi | nestorgames (Néstor Romeral Andrés) | 🟢 **Ready for Testing** | Built on BGA (`yavalath`) |
| **Omega** | Néstor Romeral Andrés | nestorgames | 🟢 **Ready for Testing** | Built on BGA (`omegatest`) |
| **Seven** | Néstor Romeral Andrés | nestorgames | 🟢 **Approved** (SVGs received) | Rule clarification needed |
| **Lords of Scotland**| Richard Sivél | Z-Man Games / Asmodee | 🟡 **Pending Studio Approval** | Core Engine Ready (`lordsofscotland`) |
| **Gardens of Uranus** | Néstor Romeral Andrés | nestorgames | 🟢 **Recommended by Nestor** | Workspace Scaffolding Ready |
| **nestorgames GP** | Néstor Romeral Andrés | nestorgames | 🟢 **Recommended by Nestor** | Workspace Scaffolding Ready |
| **Taiji** | Néstor Romeral Andrés | nestorgames | ⚪ Inquiry Sent | Backlog |
| **Amazons** | Walter Zamkauskas | nestorgames (edition) | ⚪ Inquiry Sent | Backlog |
| **ConHex** | Michail Antonow | nestorgames (edition) | ⚪ Inquiry Sent | Backlog |

---

## 📋 Detailed Game Logs

### 1. Yavalath
* **Designer**: Cameron Browne (created with AI generator *Ludi*)
* **Publisher**: nestorgames (Néstor Romeral Andrés)
* **Status**: **Fully Approved**
* **Conditions / Mandatory Credits**:
  > *"Yes that’s fine, as long as you credit me and Ludi as the inventors of Yavalath. Thanks for checking."* — Cameron Browne
* **Assets**:
  * Rules PDF available.
  * Hexagonal board geometry + 2-3 color stones.
* **Next Actions**:
  * Send confirmation/thank you email to Cameron Browne.
  * Configure credits in `gameinfos.jsonc`.

---

### 2. Omega
* **Designer / Publisher**: Néstor Romeral Andrés (nestorgames)
* **Status**: **Fully Approved & Assets In Hand**
* **Assets**:
  * Clean SVGs for playing stones provided by Nestor.
  * Board layout: 2-3-4 player variants on hex grid (to be rendered via SVG/CSS).
  * Wordmark/logo requested from Nestor.
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
* **Designer**: Richard Sivél
* **Publisher**: Z-Man Games (Studio Big / Asmodee Group)
* **Key Contacts**:
  * **Lupe Gonzalez**: Senior Game Producer, Studio Big / Z-Man Games / Office Dog / Unexpected Games (Primary point of contact).
  * **Britta Fisher**: Content Marketing Specialist, Studio Big (Connected us to Lupe Gonzalez).
  * **Asmodee Corporate Contact Form**: Submitted via `asmodee-entertainment.biz` / Asmodee corporate contact.
  * **Asmodee North America Business/Legal**: `inquiries@asmodeena.com`.
* **Status**: Core Implementation Complete & Tested on BGA (`lordsofscotland`); Awaiting Publisher Response & High-Res Art.
* **Next Actions**:
  * Follow-up email prepared in `games/lordsofscotland/PUBLISHER_FOLLOW_UP_EMAIL.md` (to send to Lupe Gonzalez on Wednesday, Sep 30).
  * Keep logic and rules engine clean and ready for art swap.

---

### 5. nestorgames Slate (Inquiry Sent)
* **Titles**: Taiji, Game of the Amazons, ConHex.
* **Contact**: `orders@nestorgames.com`
* **Status**: Inquiry batched in recent reply to Nestor.

---

## 🗂️ Contacts Directory

| Contact Name | Organization / Role | Email / Channel |
| :--- | :--- | :--- |
| **Cameron Browne** | Inventor (Yavalath, Ludi) | Direct Email |
| **Néstor Romeral Andrés** | Founder, nestorgames | `orders@nestorgames.com` |
| **Lupe Gonzalez** | Senior Game Producer, Studio Big (Z-Man / Asmodee) | Follow-up pending (`PUBLISHER_FOLLOW_UP_EMAIL.md`) |
| **Britta Fisher** | Content Marketing Specialist, Studio Big | Email intro from Z-Man |
| **Sophie Gravel** | Former Head of Studio, Z-Man Games | LinkedIn / historical reference |
| **Asmodee Licensing** | Interactive Licensing Team | Corporate Contact Form (`asmodee-entertainment.biz`) |
