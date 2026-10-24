#!/usr/bin/env python3
"""
Audits the metadata status (local repo assets + remote BGA Studio GMM) for all games.
Generates and keeps reports/metadata_status.md up to date.

Usage:
    python3 tools/audit_metadata.py [--local-only]
"""

import os
import sys
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

GAMES = [
    ("pentup", "pentup", "pentup"),
    ("red", "red", "red"),
    ("hipposcrocodiles", "hipposcrocodiles", "hipposcrocodiles"),
    ("people", "people", "people"),
    ("microchip", "microchip", "microchip"),
    ("gardensofenceladus", "gardensofenceladus", "gardensofenceladus"),
    ("gardensofio", "gardensofio", "gardensofio"),
    ("gardensofmars", "gardensofmars", "gardensofmars"),
    ("gardensofuranus", "gardensofuranus", "gardensofuranus"),
    ("nestorgamesgp", "nestorgamesgp", "nestorgamesgp"),
    ("kiln", "KILN", "kiln"),
    ("microforge", "microforge", "microforge"),
    ("omega", "omega", "omega"),
    ("sugargliders", "sugargliders", "sugargliders"),
    ("yavalath", "yavalath", "yavalath"),
    ("lordsofscotland", "lordsofscotland", "lordsofscotland"),
    ("pushfight", "pushfight", "pushfight"),
    ("mandala", "mandala", "mandala"),
    ("vaultnestorgames", "vaultnestorgames", "vaultnestorgames"),
]

BACKLOG_GAMES = [
    "counterplays",
    "flink",
    "manalath",
    "onager",
    "seven",
    "stack-22",
    "taiji",
]

PHYSICAL_PHOTO_STATUS = {
    "pentup": "Physical photos (Nestor)",
    "red": "Physical photos (Nestor)",
    "hipposcrocodiles": "Physical photos (Nestor)",
    "people": "Physical photos (Nestor)",
    "kiln": "Physical photos (Nestor)",
    "nestorgamesgp": "Physical photos (Nestor)",
    "gardensofuranus": "Physical photos (Nestor)",
    "gardensofmars": "Physical photos (Nestor)",
    "gardensofio": "Physical photos (Nestor)",
    "pushfight": "Product photo / Box art (Licence declined)",
    "gardensofenceladus": "Awaiting physical photos (Screenshots)",
    "microchip": "Awaiting physical photos (Screenshots)",
    "omega": "Awaiting physical photos (Renders)",
    "sugargliders": "Awaiting physical photos (Renders)",
    "yavalath": "Awaiting physical photos (Screenshots)",
    "microforge": "Awaiting physical photos (Renders)",
    "lordsofscotland": "Awaiting physical photos (Title card)",
    "mandala": "Legacy production art",
    "vaultnestorgames": "OK (physical photo from Nestor)",
}


def load_cookies():
    cookie_file = ROOT / "tools" / "bga_session.txt"
    if not cookie_file.exists():
        return None
    cookie_str = cookie_file.read_text().strip()
    cookies = []
    for part in cookie_str.split(";"):
        if "=" in part:
            name, val = part.strip().split("=", 1)
            cookies.append({"name": name, "value": val, "domain": ".boardgamearena.com", "path": "/"})
            cookies.append({"name": name, "value": val, "domain": "studio.boardgamearena.com", "path": "/"})
    return cookies


def check_local(slug, folder_name):
    folder = ROOT / "games" / folder_name
    meta_file = folder / "metadata.json"
    bga_dir = folder / ("bga-prod" if slug == "mandala" else "bga")
    assets_dir = bga_dir / "metadata_assets"
    gi_file = bga_dir / "gameinfos.jsonc"

    local_info = {
        "slug": slug,
        "folder": folder_name,
        "has_meta_json": meta_file.exists(),
        "has_bga": bga_dir.exists(),
        "has_assets_dir": assets_dir.exists(),
        "local_assets": {},
        "metadata_fields": {},
    }

    # Assets
    if assets_dir.exists():
        for req in ["box_280x280.png", "icon_50x50.png", "banner_1386x400.jpg", "publisher_280x280.png", "title_2000x2000.png"]:
            local_info["local_assets"][req] = (assets_dir / req).exists()
        displays = sorted(assets_dir.glob("display_*.jpg"))
        local_info["local_assets"]["displays"] = [d.name for d in displays]
    else:
        local_info["local_assets"] = {k: False for k in ["box_280x280.png", "icon_50x50.png", "banner_1386x400.jpg", "publisher_280x280.png", "title_2000x2000.png"]}
        local_info["local_assets"]["displays"] = []

    # Metadata
    if meta_file.exists():
        try:
            with open(meta_file, encoding="utf-8") as f:
                data = json.load(f)
            local_info["metadata_fields"] = {
                "game_name": data.get("game_name"),
                "bgg_id": data.get("bgg_id"),
                "year": data.get("year_published"),
                "publisher": (data.get("publisher") or {}).get("name"),
                "designers": data.get("designers", []),
                "description": data.get("description", ""),
                "zombie_level": data.get("zombie_mode_level"),
                "tags": data.get("tags", []),
                "characteristics": data.get("characteristics", {}),
            }
        except Exception as e:
            local_info["metadata_fields"]["error"] = str(e)
    elif gi_file.exists():
        # Fallback to gameinfos
        text = gi_file.read_text(encoding="utf-8")
        text_clean = re.sub(r"//.*$", "", text, flags=re.M)
        try:
            data = json.loads(text_clean)
            local_info["metadata_fields"] = {
                "game_name": data.get("game_name"),
                "bgg_id": data.get("bgg_id"),
                "year": None,
                "publisher": data.get("publisher"),
                "designers": [],
                "description": "",
                "zombie_level": None,
                "tags": [],
                "characteristics": {},
            }
        except Exception:
            pass

    return local_info


def audit_studio(page, slug, project_slug):
    url = f"https://studio.boardgamearena.com/controlpanelgames?game={project_slug}"
    try:
        page.goto(url, wait_until="domcontentloaded", timeout=25000)
        page.wait_for_selector('div:has-text("Box"), div.cursor-pointer:has-text("Media")', timeout=15000)
    except Exception as e:
        pass

    content = page.content()
    if "You are not authorized to view this page" in content or "You do not have permission" in content:
        return {"accessible": False}

    deployed = "has been deployed" in content

    # Media Tab
    media_tab = [t for t in page.locator("div.cursor-pointer").all() if t.is_visible() and t.inner_text().strip() == "Media"]
    media_status = {"box": False, "icon": False, "banner": False, "publisher": False, "displays": 0, "title": False}
    if media_tab:
        media_tab[0].click()
        try:
            page.wait_for_selector('div:has-text("Box")', timeout=8000)
        except Exception:
            pass

        for label, key in [("Box", "box"), ("Icon", "icon"), ("Banner", "banner"), ("Publisher images", "publisher"), ("Title images", "title")]:
            h = page.get_by_text(label, exact=True).first
            if h.count() > 0:
                parent = h.locator("xpath=../..")
                thumbs = parent.locator("div.cursor-pointer")
                for i in range(thumbs.count()):
                    inner = thumbs.nth(i).locator("div.bg-contain")
                    if inner.count() > 0:
                        style = inner.get_attribute("style") or ""
                        if "url(" in style and "chequerboard" not in style:
                            media_status[key] = True
                            break

        h_disp = page.get_by_text("Display images", exact=True).first
        if h_disp.count() > 0:
            parent = h_disp.locator("xpath=../..")
            thumbs = parent.locator("div.cursor-pointer")
            valid_disp = 0
            for i in range(thumbs.count()):
                inner = thumbs.nth(i).locator("div.bg-contain")
                if inner.count() > 0:
                    style = inner.get_attribute("style") or ""
                    if "url(" in style and "chequerboard" not in style:
                        valid_disp += 1
            media_status["displays"] = valid_disp

    # Metadata Tab
    meta_tab = [t for t in page.locator("div.cursor-pointer").all() if t.is_visible() and t.inner_text().strip() == "Metadata"]
    metadata_status = {
        "designer": "",
        "year": "",
        "characteristics": {},
        "zombie": "",
        "has_description": False,
        "description_length": 0,
    }
    if meta_tab:
        meta_tab[0].click()
        try:
            page.wait_for_selector('div.tabmetadata__row-2, div.tabmetadata__row-1', timeout=8000)
        except Exception:
            pass
        d_row = page.locator('div.tabmetadata__row-2:has-text("Designer")').first
        if d_row.count() > 0:
            val = d_row.inner_text().replace("Designer", "").strip()
            if "NOT SET" not in val:
                metadata_status["designer"] = val

        y_row = page.locator('div.tabmetadata__row-1:has-text("Publication year")').first
        if y_row.count() > 0:
            val = y_row.inner_text().replace("Publication year", "").strip()
            if "NOT SET" not in val:
                metadata_status["year"] = val

        desc_row = page.locator('div.tabmetadata__row-1:has-text("Description")').first
        if desc_row.count() > 0:
            val = desc_row.inner_text().replace("Description", "").strip()
            if "NOT SET" not in val and len(val) > 20:
                metadata_status["has_description"] = True
                metadata_status["description_length"] = len(val)

        z_row = page.locator('div.tabmetadata__row-1:has-text("Zombie Mode level")').first
        if z_row.count() > 0:
            val = z_row.inner_text().replace("Zombie Mode level", "").strip()
            if "NOT SET" not in val:
                metadata_status["zombie"] = val

        for char_name in ["Complexity", "Luck", "Strategy", "Diplomacy"]:
            c_row = page.locator(f'div.tabmetadata__row-4:has-text("{char_name}")').first
            if c_row.count() > 0:
                val = c_row.inner_text().replace(char_name, "").strip()
                if "NOT SET" not in val:
                    metadata_status["characteristics"][char_name] = val

    # Tags Tab
    tags_tab = [t for t in page.locator("div.cursor-pointer").all() if t.is_visible() and t.inner_text().strip() == "Tags"]
    tags = []
    if tags_tab:
        tags_tab[0].click()
        try:
            page.wait_for_selector('.bga-game-tag-list, .bga-game-tag-list__item', timeout=8000)
        except Exception:
            pass
        for it in page.locator(".bga-game-tag-list__item").all():
            t_name = it.inner_text().strip().split("\n")[0]
            if t_name and t_name not in tags:
                tags.append(t_name)

    return {
        "accessible": True,
        "deployed": deployed,
        "media": media_status,
        "metadata": metadata_status,
        "tags": tags,
    }


def generate_markdown(audit_results):
    lines = []
    lines.append("# BGA Game Metadata Status Tracker")
    lines.append("")
    lines.append("> **Single source of truth** for metadata, assets, and GMM configuration across all games.")
    lines.append("> Updated automatically via `python3 tools/audit_metadata.py`.")
    lines.append("")
    lines.append("## 1. Quick Summary Dashboard")
    lines.append("")
    lines.append("| Game | Studio Project | Studio Status | Box | Icon | Banner | Publisher | Displays | Title | Desc | Characteristics | Tags | Physical Photos | Next Action |")
    lines.append("| :--- | :--- | :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :--- | :--- |")

    for res in audit_results:
        slug = res["slug"]
        project = res["project"]
        studio = res.get("studio", {})
        local = res.get("local", {})

        # Status
        if not studio.get("accessible", False):
            st_text = "No access"
        elif studio.get("deployed"):
            st_text = "**Prod (Locked)**"
        else:
            st_text = "Studio"

        # Media checks
        media = studio.get("media", {})
        m_box = "✓" if media.get("box") else ("⚠️" if local["local_assets"].get("box_280x280.png") else "❌")
        m_icon = "✓" if media.get("icon") else ("⚠️" if local["local_assets"].get("icon_50x50.png") else "❌")
        m_ban = "✓" if media.get("banner") else ("⚠️" if local["local_assets"].get("banner_1386x400.jpg") else "❌")
        m_pub = "✓" if media.get("publisher") else ("⚠️" if local["local_assets"].get("publisher_280x280.png") else "❌")
        m_tit = "✓" if media.get("title") else ("⚠️" if local["local_assets"].get("title_2000x2000.png") else "❌")
        disp_count = media.get("displays", 0)
        local_disp_count = len(local["local_assets"].get("displays", []))
        m_disp = f"✓ ({disp_count})" if disp_count >= 2 else (f"⚠️ ({local_disp_count} local)" if local_disp_count >= 2 else f"❌ ({disp_count})")

        # Metadata checks
        meta = studio.get("metadata", {})
        m_desc = "✓" if meta.get("has_description") else ("⚠️ local" if local.get("metadata_fields", {}).get("description") else "❌")
        char_count = len(meta.get("characteristics", {}))
        m_chars = f"✓ ({char_count}/4)" if char_count == 4 else (f"⚠️ local" if local.get("metadata_fields", {}).get("characteristics") else "❌")
        tag_count = len(studio.get("tags", []))
        m_tags = f"✓ ({tag_count})" if tag_count >= 2 else (f"⚠️ ({tag_count})" if tag_count > 0 else "❌")

        photo_status = PHYSICAL_PHOTO_STATUS.get(slug, "Unknown")
        
        # Next action
        if slug == "pentup":
            action = "Request Public Alpha"
        elif slug == "pushfight":
            action = "None (Licence declined)"
        elif studio.get("deployed"):
            action = "Manage via Prod GMM"
        elif not media.get("box") and local["local_assets"].get("box_280x280.png"):
            action = f"Run `tools/upload_metadata_images.py {slug}`"
        elif not meta.get("has_description") and local.get("metadata_fields", {}).get("description"):
            action = f"Run `tools/fill_gmm.py {slug}`"
        elif "Awaiting" in photo_status:
            action = "Need real physical photos"
        else:
            action = "Ready / Up to date"

        lines.append(f"| **{slug}** | `{project}` | {st_text} | {m_box} | {m_icon} | {m_ban} | {m_pub} | {m_disp} | {m_tit} | {m_desc} | {m_chars} | {m_tags} | {photo_status} | {action} |")

    lines.append("")
    lines.append("Legend:")
    lines.append("- **✓**: Uploaded and verified in Studio GMM.")
    lines.append("- **⚠️**: Asset/text exists locally in repo, but not uploaded or locked because project is deployed to production.")
    lines.append("- **❌**: Missing both on Studio and locally.")
    lines.append("")
    lines.append("---")
    lines.append("")
    lines.append("## 2. Detailed Game Breakdown")
    lines.append("")

    for res in audit_results:
        slug = res["slug"]
        project = res["project"]
        studio = res.get("studio", {})
        local = res.get("local", {})
        meta_local = local.get("metadata_fields", {})
        media_studio = studio.get("media", {})
        meta_studio = studio.get("metadata", {})
        tags_studio = studio.get("tags", [])

        lines.append(f"### {meta_local.get('game_name') or slug.capitalize()} (`{slug}`)")
        lines.append(f"- **Studio Project**: `{project}` | **Status**: {'Deployed to Production' if studio.get('deployed') else ('Studio Accessible' if studio.get('accessible') else 'Studio Inaccessible')}")
        lines.append(f"- **BGG ID**: `{meta_local.get('bgg_id') or 'None'}` | **Publisher**: `{meta_local.get('publisher') or 'None'}`")
        lines.append(f"- **Designer(s)**: Studio=`{meta_studio.get('designer') or 'NOT SET'}` | Local=`{', '.join(meta_local.get('designers', [])) or 'None'}`")
        lines.append(f"- **Publication Year**: Studio=`{meta_studio.get('year') or 'NOT SET'}` | Local=`{meta_local.get('year') or 'None'}`")
        lines.append(f"- **Zombie Mode Level**: Studio=`{meta_studio.get('zombie') or 'NOT SET'}` | Local=`{meta_local.get('zombie_level') or 'None'}`")
        lines.append(f"- **Characteristics**: Studio=`{meta_studio.get('characteristics') or 'None'}` | Local=`{meta_local.get('characteristics') or 'None'}`")
        lines.append(f"- **Tags**: Studio={tags_studio} | Local={meta_local.get('tags', [])}")
        lines.append(f"- **Description**: Studio=`{'SET (' + str(meta_studio.get('description_length', 0)) + ' chars)' if meta_studio.get('has_description') else 'NOT SET'}` | Local=`{'SET (' + str(len(meta_local.get('description', ''))) + ' chars)' if meta_local.get('description') else 'None'}`")
        lines.append(f"- **Media Uploaded on Studio**:")
        lines.append(f"  - Box: {'✓' if media_studio.get('box') else '❌'} (Local: {'✓' if local['local_assets'].get('box_280x280.png') else '❌'})")
        lines.append(f"  - Icon: {'✓' if media_studio.get('icon') else '❌'} (Local: {'✓' if local['local_assets'].get('icon_50x50.png') else '❌'})")
        lines.append(f"  - Banner: {'✓' if media_studio.get('banner') else '❌'} (Local: {'✓' if local['local_assets'].get('banner_1386x400.jpg') else '❌'})")
        lines.append(f"  - Publisher: {'✓' if media_studio.get('publisher') else '❌'} (Local: {'✓' if local['local_assets'].get('publisher_280x280.png') else '❌'})")
        lines.append(f"  - Title: {'✓' if media_studio.get('title') else '❌'} (Local: {'✓' if local['local_assets'].get('title_2000x2000.png') else '❌'})")
        lines.append(f"  - Displays: {media_studio.get('displays', 0)} on Studio | {len(local['local_assets'].get('displays', []))} local ({', '.join(local['local_assets'].get('displays', [])) or 'None'})")
        lines.append(f"- **Display Photos Nature**: {PHYSICAL_PHOTO_STATUS.get(slug, 'Unknown')}")
        lines.append("")

    lines.append("---")
    lines.append("")
    lines.append("## 3. Backlog / Pipeline Games (No Studio Project Yet)")
    lines.append("")
    lines.append("| Game Slug | Status | Notes |")
    lines.append("| :--- | :--- | :--- |")
    for bg in BACKLOG_GAMES:
        lines.append(f"| `{bg}` | Concept / Scaffolding | Registered folder in `games/`, not yet registered on Studio. |")
    lines.append("")
    lines.append("---")
    lines.append("")
    lines.append("## 4. How to Update This File")
    lines.append("")
    lines.append("Run the automated audit script anytime:")
    lines.append("```bash")
    lines.append("python3 tools/audit_metadata.py")
    lines.append("```")
    lines.append("It reads local assets and queries Studio Control Panel GMM to generate this report.")
    lines.append("")

    return "\n".join(lines)


def main():
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("--local-only", action="store_true", help="Audit local repository files only")
    args = parser.parse_args()

    cookies = load_cookies() if not args.local_only else None
    audit_results = []

    if cookies and not args.local_only:
        from playwright.sync_api import sync_playwright
        with sync_playwright() as p:
            browser = p.chromium.launch(executable_path="/usr/bin/google-chrome", headless=True)
            ctx = browser.new_context(viewport={"width": 1280, "height": 1200})
            ctx.add_cookies(cookies)
            page = ctx.new_page()

            for slug, folder, project in GAMES:
                print(f"Auditing {slug} (project: {project})...")
                local_info = check_local(slug, folder)
                studio_info = audit_studio(page, slug, project)
                audit_results.append({
                    "slug": slug,
                    "folder": folder,
                    "project": project,
                    "local": local_info,
                    "studio": studio_info,
                })

            browser.close()
    else:
        for slug, folder, project in GAMES:
            local_info = check_local(slug, folder)
            audit_results.append({
                "slug": slug,
                "folder": folder,
                "project": project,
                "local": local_info,
                "studio": {},
            })

    report_md = generate_markdown(audit_results)
    out_file = ROOT / "reports" / "metadata_status.md"
    out_file.write_text(report_md, encoding="utf-8")
    print(f"\n[x] Successfully generated {out_file}")


if __name__ == "__main__":
    main()
