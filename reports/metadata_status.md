# BGA Game Metadata Status Tracker

> **Single source of truth** for metadata, assets, and GMM configuration across all games.
> Updated automatically via `python3 tools/audit_metadata.py`.

## 1. Quick Summary Dashboard

| Game | Studio Project | Studio Status | Box | Icon | Banner | Publisher | Displays | Title | Desc | Characteristics | Tags | Physical Photos | Next Action |
| :--- | :--- | :--- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :--- | :--- |
| **pentup** | `pentup` | Studio | ✓ | ✓ | ✓ | ✓ | ✓ (2) | ✓ | ✓ | ✓ (4/4) | ✓ (3) | Physical photos (Nestor) | Request Public Alpha |
| **red** | `red` | Studio | ✓ | ✓ | ✓ | ✓ | ✓ (2) | ✓ | ✓ | ✓ (4/4) | ✓ (2) | Physical photos (Nestor) | Ready / Up to date |
| **hipposcrocodiles** | `hipposcrocodiles` | Studio | ✓ | ✓ | ✓ | ✓ | ✓ (2) | ✓ | ✓ | ✓ (4/4) | ✓ (2) | Physical photos (Nestor) | Ready / Up to date |
| **people** | `people` | Studio | ✓ | ✓ | ✓ | ✓ | ✓ (2) | ✓ | ✓ | ✓ (4/4) | ✓ (2) | Physical photos (Nestor) | Ready / Up to date |
| **microchip** | `microchip` | Studio | ✓ | ✓ | ✓ | ✓ | ✓ (2) | ✓ | ✓ | ✓ (4/4) | ✓ (2) | Awaiting physical photos (Screenshots) | Need real physical photos |
| **gardensofenceladus** | `gardensofenceladus` | Studio | ✓ | ✓ | ✓ | ✓ | ✓ (2) | ✓ | ✓ | ✓ (4/4) | ✓ (3) | Awaiting physical photos (Screenshots) | Need real physical photos |
| **gardensofio** | `gardensofio` | Studio | ✓ | ✓ | ✓ | ✓ | ✓ (2) | ✓ | ✓ | ✓ (4/4) | ✓ (3) | Physical photos (Nestor) | Ready / Up to date |
| **gardensofmars** | `gardensofmars` | Studio | ✓ | ✓ | ✓ | ✓ | ✓ (2) | ✓ | ✓ | ✓ (4/4) | ✓ (3) | Physical photos (Nestor) | Ready / Up to date |
| **gardensofuranus** | `gardensofuranus` | Studio | ✓ | ✓ | ✓ | ✓ | ✓ (3) | ✓ | ✓ | ✓ (4/4) | ✓ (4) | Physical photos (Nestor) | Ready / Up to date |
| **nestorgamesgp** | `nestorgamesgp` | Studio | ✓ | ✓ | ✓ | ✓ | ✓ (5) | ✓ | ✓ | ✓ (4/4) | ✓ (6) | Physical photos (Nestor) | Ready / Up to date |
| **kiln** | `kiln` | Studio | ✓ | ✓ | ✓ | ✓ | ✓ (2) | ✓ | ✓ | ✓ (4/4) | ✓ (4) | Physical photos (Nestor) | Ready / Up to date |
| **microforge** | `microforge` | Studio | ✓ | ✓ | ✓ | ✓ | ✓ (4) | ✓ | ✓ | ✓ (4/4) | ✓ (4) | Awaiting physical photos (Renders) | Need real physical photos |
| **omega** | `omega` | Studio | ✓ | ✓ | ✓ | ✓ | ✓ (2) | ✓ | ✓ | ✓ (4/4) | ✓ (2) | Awaiting physical photos (Renders) | Need real physical photos |
| **sugargliders** | `sugargliders` | Studio | ✓ | ✓ | ✓ | ✓ | ⚠️ (2 local) | ✓ | ✓ | ✓ (4/4) | ✓ (4) | Awaiting physical photos (Renders) | Need real physical photos |
| **yavalath** | `yavalath` | Studio | ✓ | ✓ | ✓ | ✓ | ⚠️ (2 local) | ✓ | ✓ | ✓ (4/4) | ✓ (2) | Awaiting physical photos (Screenshots) | Need real physical photos |
| **lordsofscotland** | `lordsofscotland` | Studio | ✓ | ✓ | ✓ | ✓ | ✓ (5) | ✓ | ✓ | ✓ (4/4) | ✓ (5) | Awaiting physical photos (Title card) | Need real physical photos |
| **pushfight** | `pushfight` | Studio | ✓ | ✓ | ✓ | ✓ | ⚠️ (2 local) | ✓ | ✓ | ✓ (4/4) | ✓ (3) | Product photo / Box art (Licence declined) | None (Licence declined) |
| **mandala** | `mandala` | Studio | ✓ | ✓ | ✓ | ✓ | ✓ (5) | ✓ | ✓ | ❌ | ✓ (4) | Legacy production art | Ready / Up to date |
| **vaultnestorgames** | `vaultnestorgames` | Studio | ✓ | ✓ | ✓ | ✓ | ✓ (4) | ✓ | ✓ | ✓ (4/4) | ✓ (2) | OK (physical photo from Nestor) | Ready / Up to date |

Legend:
- **✓**: Uploaded and verified in Studio GMM.
- **⚠️**: Asset/text exists locally in repo, but not uploaded or locked because project is deployed to production.
- **❌**: Missing both on Studio and locally.

---

## 2. Detailed Game Breakdown

### Pent-Up (`pentup`)
- **Studio Project**: `pentup` | **Status**: Studio Accessible
- **BGG ID**: `152115` | **Publisher**: `nestorgames`
- **Designer(s)**: Studio=`Néstor Romeral Andrés` | Local=`Néstor Romeral Andrés`
- **Publication Year**: Studio=`2003` | Local=`2003`
- **Zombie Mode Level**: Studio=`1` | Local=`1`
- **Characteristics**: Studio=`{'Complexity': '3', 'Luck': '2', 'Strategy': '3', 'Diplomacy': '1'}` | Local=`{'complexity': 3, 'luck': 2, 'strategy': 3, 'diplomacy': 1, 'note': 'PROPOSAL: sliders 1 to 5 in the Control Panel only.'}`
- **Tags**: Studio=['Casual games', 'Family', 'Abstract games'] | Local=['Abstract Strategy', 'Combinatorial']
- **Description**: Studio=`SET (515 chars)` | Local=`SET (511 chars)`
- **Media Uploaded on Studio**:
  - Box: ✓ (Local: ✓)
  - Icon: ✓ (Local: ✓)
  - Banner: ✓ (Local: ✓)
  - Publisher: ✓ (Local: ✓)
  - Title: ✓ (Local: ✓)
  - Displays: 2 on Studio | 2 local (display_1.jpg, display_2.jpg)
- **Display Photos Nature**: Physical photos (Nestor)

### Red (`red`)
- **Studio Project**: `red` | **Status**: Studio Accessible
- **BGG ID**: `112118` | **Publisher**: `nestorgames`
- **Designer(s)**: Studio=`Néstor Romeral Andrés` | Local=`Néstor Romeral Andrés`
- **Publication Year**: Studio=`2011` | Local=`2011`
- **Zombie Mode Level**: Studio=`1` | Local=`1`
- **Characteristics**: Studio=`{'Complexity': '3', 'Luck': '2', 'Strategy': '3', 'Diplomacy': '1'}` | Local=`{'complexity': 3, 'luck': 2, 'strategy': 3, 'diplomacy': 1, 'note': 'PROPOSAL: sliders 1 to 5 in the Control Panel only.'}`
- **Tags**: Studio=['Casual games', 'Family'] | Local=['Abstract Strategy', 'Tile Placement', 'Combinatorial']
- **Description**: Studio=`SET (403 chars)` | Local=`SET (398 chars)`
- **Media Uploaded on Studio**:
  - Box: ✓ (Local: ✓)
  - Icon: ✓ (Local: ✓)
  - Banner: ✓ (Local: ✓)
  - Publisher: ✓ (Local: ✓)
  - Title: ✓ (Local: ✓)
  - Displays: 2 on Studio | 2 local (display_1.jpg, display_2.jpg)
- **Display Photos Nature**: Physical photos (Nestor)

### Hippos & Crocodiles (`hipposcrocodiles`)
- **Studio Project**: `hipposcrocodiles` | **Status**: Studio Accessible
- **BGG ID**: `51197` | **Publisher**: `nestorgames`
- **Designer(s)**: Studio=`Néstor Romeral Andrés` | Local=`Néstor Romeral Andrés`
- **Publication Year**: Studio=`2008` | Local=`2008`
- **Zombie Mode Level**: Studio=`1` | Local=`1`
- **Characteristics**: Studio=`{'Complexity': '2', 'Luck': '1', 'Strategy': '3', 'Diplomacy': '1'}` | Local=`{'complexity': 2, 'luck': 1, 'strategy': 3, 'diplomacy': 1, 'note': 'PROPOSAL: sliders 1 to 5 in the Control Panel only.'}`
- **Tags**: Studio=['Casual games', 'Family'] | Local=['Abstract', 'Animals', 'Tile Placement']
- **Description**: Studio=`SET (350 chars)` | Local=`SET (345 chars)`
- **Media Uploaded on Studio**:
  - Box: ✓ (Local: ✓)
  - Icon: ✓ (Local: ✓)
  - Banner: ✓ (Local: ✓)
  - Publisher: ✓ (Local: ✓)
  - Title: ✓ (Local: ✓)
  - Displays: 2 on Studio | 2 local (display_1.jpg, display_2.jpg)
- **Display Photos Nature**: Physical photos (Nestor)

### People (`people`)
- **Studio Project**: `people` | **Status**: Studio Accessible
- **BGG ID**: `467193` | **Publisher**: `nestorgames`
- **Designer(s)**: Studio=`Néstor Romeral Andrés` | Local=`Néstor Romeral Andrés`
- **Publication Year**: Studio=`2026` | Local=`2026`
- **Zombie Mode Level**: Studio=`1` | Local=`1`
- **Characteristics**: Studio=`{'Complexity': '2', 'Luck': '2', 'Strategy': '4', 'Diplomacy': '1'}` | Local=`{'complexity': 2, 'luck': 2, 'strategy': 4, 'diplomacy': 1, 'note': 'PROPOSAL: sliders 1 to 5 in the Control Panel only (not in gameinfos.jsonc).'}`
- **Tags**: Studio=['Casual games', 'Family'] | Local=['Abstract strategy', 'Area control', 'Connection', 'Grid', 'Science fiction']
- **Description**: Studio=`SET (566 chars)` | Local=`SET (562 chars)`
- **Media Uploaded on Studio**:
  - Box: ✓ (Local: ✓)
  - Icon: ✓ (Local: ✓)
  - Banner: ✓ (Local: ✓)
  - Publisher: ✓ (Local: ✓)
  - Title: ✓ (Local: ✓)
  - Displays: 2 on Studio | 2 local (display_1.jpg, display_2.jpg)
- **Display Photos Nature**: Physical photos (Nestor)

### Microchip (`microchip`)
- **Studio Project**: `microchip` | **Status**: Studio Accessible
- **BGG ID**: `371557` | **Publisher**: `nestorgames`
- **Designer(s)**: Studio=`NOT SET` | Local=`Néstor Romeral Andrés`
- **Publication Year**: Studio=`NOT SET` | Local=`2022`
- **Zombie Mode Level**: Studio=`1` | Local=`1`
- **Characteristics**: Studio=`{'Complexity': '3', 'Luck': '2', 'Strategy': '3', 'Diplomacy': '1'}` | Local=`{'complexity': 3, 'luck': 2, 'strategy': 3, 'diplomacy': 1, 'note': 'PROPOSAL: sliders 1 to 5 in the Control Panel only.'}`
- **Tags**: Studio=['Casual games', 'Family'] | Local=['Abstract strategy', 'Tile placement', 'Pattern building']
- **Description**: Studio=`SET (508 chars)` | Local=`SET (504 chars)`
- **Media Uploaded on Studio**:
  - Box: ✓ (Local: ✓)
  - Icon: ✓ (Local: ✓)
  - Banner: ✓ (Local: ✓)
  - Publisher: ✓ (Local: ✓)
  - Title: ✓ (Local: ✓)
  - Displays: 2 on Studio | 2 local (display_1.jpg, display_2.jpg)
- **Display Photos Nature**: Awaiting physical photos (Screenshots)

### Gardens of Enceladus (`gardensofenceladus`)
- **Studio Project**: `gardensofenceladus` | **Status**: Studio Accessible
- **BGG ID**: `253793` | **Publisher**: `nestorgames`
- **Designer(s)**: Studio=`Néstor Romeral Andrés` | Local=`Néstor Romeral Andrés`
- **Publication Year**: Studio=`2018` | Local=`2018`
- **Zombie Mode Level**: Studio=`1` | Local=`1`
- **Characteristics**: Studio=`{'Complexity': '3', 'Luck': '2', 'Strategy': '4', 'Diplomacy': '1'}` | Local=`{'complexity': 3, 'luck': 2, 'strategy': 4, 'diplomacy': 1, 'note': 'PROPOSAL: sliders 1 to 5 in the Control Panel only (not in gameinfos.jsonc).'}`
- **Tags**: Studio=['Casual games', 'Family', 'Collection'] | Local=['Abstract strategy', 'Set collection', 'Grid', 'Science fiction', 'Cooperative variant']
- **Description**: Studio=`SET (701 chars)` | Local=`SET (697 chars)`
- **Media Uploaded on Studio**:
  - Box: ✓ (Local: ✓)
  - Icon: ✓ (Local: ✓)
  - Banner: ✓ (Local: ✓)
  - Publisher: ✓ (Local: ✓)
  - Title: ✓ (Local: ✓)
  - Displays: 2 on Studio | 2 local (display_1.jpg, display_2.jpg)
- **Display Photos Nature**: Awaiting physical photos (Screenshots)

### Gardensofio (`gardensofio`)
- **Studio Project**: `gardensofio` | **Status**: Studio Accessible
- **BGG ID**: `None` | **Publisher**: `None`
- **Designer(s)**: Studio=`Néstor Romeral Andrés` | Local=`None`
- **Publication Year**: Studio=`2016` | Local=`None`
- **Zombie Mode Level**: Studio=`1` | Local=`None`
- **Characteristics**: Studio=`{'Complexity': '2', 'Luck': '1', 'Strategy': '4', 'Diplomacy': '1'}` | Local=`None`
- **Tags**: Studio=['Casual games', 'Tile placement', 'Family'] | Local=[]
- **Description**: Studio=`SET (930 chars)` | Local=`None`
- **Media Uploaded on Studio**:
  - Box: ✓ (Local: ✓)
  - Icon: ✓ (Local: ✓)
  - Banner: ✓ (Local: ✓)
  - Publisher: ✓ (Local: ✓)
  - Title: ✓ (Local: ✓)
  - Displays: 2 on Studio | 2 local (display_1.jpg, display_2.jpg)
- **Display Photos Nature**: Physical photos (Nestor)

### Gardensofmars (`gardensofmars`)
- **Studio Project**: `gardensofmars` | **Status**: Studio Accessible
- **BGG ID**: `None` | **Publisher**: `None`
- **Designer(s)**: Studio=`Néstor Romeral Andrés` | Local=`None`
- **Publication Year**: Studio=`2011` | Local=`None`
- **Zombie Mode Level**: Studio=`1` | Local=`None`
- **Characteristics**: Studio=`{'Complexity': '2', 'Luck': '1', 'Strategy': '3', 'Diplomacy': '1'}` | Local=`None`
- **Tags**: Studio=['Casual games', 'Family', 'Dice'] | Local=[]
- **Description**: Studio=`SET (621 chars)` | Local=`None`
- **Media Uploaded on Studio**:
  - Box: ✓ (Local: ✓)
  - Icon: ✓ (Local: ✓)
  - Banner: ✓ (Local: ✓)
  - Publisher: ✓ (Local: ✓)
  - Title: ✓ (Local: ✓)
  - Displays: 2 on Studio | 2 local (display_1.jpg, display_2.jpg)
- **Display Photos Nature**: Physical photos (Nestor)

### Gardensofuranus (`gardensofuranus`)
- **Studio Project**: `gardensofuranus` | **Status**: Studio Accessible
- **BGG ID**: `None` | **Publisher**: `None`
- **Designer(s)**: Studio=`NOT SET` | Local=`None`
- **Publication Year**: Studio=`NOT SET` | Local=`None`
- **Zombie Mode Level**: Studio=`1` | Local=`None`
- **Characteristics**: Studio=`{'Complexity': '2', 'Luck': '2', 'Strategy': '3', 'Diplomacy': '1'}` | Local=`None`
- **Tags**: Studio=['Casual games', 'Cards', 'Drafting', 'Family'] | Local=[]
- **Description**: Studio=`SET (336 chars)` | Local=`None`
- **Media Uploaded on Studio**:
  - Box: ✓ (Local: ✓)
  - Icon: ✓ (Local: ✓)
  - Banner: ✓ (Local: ✓)
  - Publisher: ✓ (Local: ✓)
  - Title: ✓ (Local: ✓)
  - Displays: 3 on Studio | 3 local (display_1.jpg, display_2.jpg, display_3.jpg)
- **Display Photos Nature**: Physical photos (Nestor)

### Nestorgamesgp (`nestorgamesgp`)
- **Studio Project**: `nestorgamesgp` | **Status**: Studio Accessible
- **BGG ID**: `None` | **Publisher**: `None`
- **Designer(s)**: Studio=`Néstor Romeral Andrés` | Local=`None`
- **Publication Year**: Studio=`2021` | Local=`None`
- **Zombie Mode Level**: Studio=`1` | Local=`None`
- **Characteristics**: Studio=`{'Complexity': '2', 'Luck': '4', 'Strategy': '2', 'Diplomacy': '1'}` | Local=`None`
- **Tags**: Studio=['Casual games', 'Race', 'Push your luck', 'Family', 'Party game', 'Dice'] | Local=[]
- **Description**: Studio=`SET (531 chars)` | Local=`None`
- **Media Uploaded on Studio**:
  - Box: ✓ (Local: ✓)
  - Icon: ✓ (Local: ✓)
  - Banner: ✓ (Local: ✓)
  - Publisher: ✓ (Local: ✓)
  - Title: ✓ (Local: ✓)
  - Displays: 5 on Studio | 5 local (display_1.jpg, display_2.jpg, display_3.jpg, display_4.jpg, display_5.jpg)
- **Display Photos Nature**: Physical photos (Nestor)

### Kiln (`kiln`)
- **Studio Project**: `kiln` | **Status**: Studio Accessible
- **BGG ID**: `None` | **Publisher**: `None`
- **Designer(s)**: Studio=`Néstor Romeral Andrés` | Local=`None`
- **Publication Year**: Studio=`2020` | Local=`None`
- **Zombie Mode Level**: Studio=`1` | Local=`None`
- **Characteristics**: Studio=`{'Complexity': '2', 'Luck': '1', 'Strategy': '3', 'Diplomacy': '0'}` | Local=`None`
- **Tags**: Studio=['Casual games', 'Tile placement', 'Abstract games', 'Family'] | Local=[]
- **Description**: Studio=`SET (1766 chars)` | Local=`None`
- **Media Uploaded on Studio**:
  - Box: ✓ (Local: ✓)
  - Icon: ✓ (Local: ✓)
  - Banner: ✓ (Local: ✓)
  - Publisher: ✓ (Local: ✓)
  - Title: ✓ (Local: ✓)
  - Displays: 2 on Studio | 2 local (display_1.jpg, display_2.jpg)
- **Display Photos Nature**: Physical photos (Nestor)

### Microforge (`microforge`)
- **Studio Project**: `microforge` | **Status**: Studio Accessible
- **BGG ID**: `None` | **Publisher**: `None`
- **Designer(s)**: Studio=`Jayadev Haddad` | Local=`None`
- **Publication Year**: Studio=`NOT SET` | Local=`None`
- **Zombie Mode Level**: Studio=`1` | Local=`None`
- **Characteristics**: Studio=`{'Complexity': '3', 'Luck': '1', 'Strategy': '4', 'Diplomacy': '2'}` | Local=`None`
- **Tags**: Studio=['For regular players', 'Wargame', 'Economy', 'Objectives'] | Local=[]
- **Description**: Studio=`SET (52 chars)` | Local=`None`
- **Media Uploaded on Studio**:
  - Box: ✓ (Local: ✓)
  - Icon: ✓ (Local: ✓)
  - Banner: ✓ (Local: ✓)
  - Publisher: ✓ (Local: ✓)
  - Title: ✓ (Local: ✓)
  - Displays: 4 on Studio | 2 local (display_1.jpg, display_2.jpg)
- **Display Photos Nature**: Awaiting physical photos (Renders)

### Omega (`omega`)
- **Studio Project**: `omega` | **Status**: Studio Accessible
- **BGG ID**: `81588` | **Publisher**: `nestorgames`
- **Designer(s)**: Studio=`Néstor Romeral Andrés` | Local=`Néstor Romeral Andrés`
- **Publication Year**: Studio=`2010` | Local=`2010`
- **Zombie Mode Level**: Studio=`1` | Local=`1`
- **Characteristics**: Studio=`{'Complexity': '1', 'Luck': '0', 'Strategy': '4', 'Diplomacy': '1'}` | Local=`{'complexity': 2, 'luck': 1, 'strategy': 5, 'diplomacy': 1, 'note': 'PROPOSAL: sliders 1 to 5 in the Control Panel only (not in gameinfos.jsonc).'}`
- **Tags**: Studio=['Casual games', 'Abstract games'] | Local=['Abstract Strategy', 'Combinatorial', 'Hexagonal grid', 'Connection']
- **Description**: Studio=`SET (1163 chars)` | Local=`SET (417 chars)`
- **Media Uploaded on Studio**:
  - Box: ✓ (Local: ✓)
  - Icon: ✓ (Local: ✓)
  - Banner: ✓ (Local: ✓)
  - Publisher: ✓ (Local: ✓)
  - Title: ✓ (Local: ✓)
  - Displays: 2 on Studio | 2 local (display_1.jpg, display_2.jpg)
- **Display Photos Nature**: Awaiting physical photos (Renders)

### Sugargliders (`sugargliders`)
- **Studio Project**: `sugargliders` | **Status**: Studio Accessible
- **BGG ID**: `None` | **Publisher**: `None`
- **Designer(s)**: Studio=`Néstor Romeral Andrés` | Local=`None`
- **Publication Year**: Studio=`2012` | Local=`None`
- **Zombie Mode Level**: Studio=`1` | Local=`None`
- **Characteristics**: Studio=`{'Complexity': '1', 'Luck': '0', 'Strategy': '4', 'Diplomacy': '0'}` | Local=`None`
- **Tags**: Studio=['Casual games', 'Abstract games', 'Animals', 'Family'] | Local=[]
- **Description**: Studio=`SET (588 chars)` | Local=`None`
- **Media Uploaded on Studio**:
  - Box: ✓ (Local: ✓)
  - Icon: ✓ (Local: ✓)
  - Banner: ✓ (Local: ✓)
  - Publisher: ✓ (Local: ✓)
  - Title: ✓ (Local: ✓)
  - Displays: 1 on Studio | 2 local (display_1.jpg, display_2.jpg)
- **Display Photos Nature**: Awaiting physical photos (Renders)

### Yavalath (`yavalath`)
- **Studio Project**: `yavalath` | **Status**: Studio Accessible
- **BGG ID**: `None` | **Publisher**: `None`
- **Designer(s)**: Studio=`Cameron Browne` | Local=`None`
- **Publication Year**: Studio=`2007` | Local=`None`
- **Zombie Mode Level**: Studio=`1` | Local=`None`
- **Characteristics**: Studio=`{'Complexity': '1', 'Luck': '0', 'Strategy': '5', 'Diplomacy': '0'}` | Local=`None`
- **Tags**: Studio=['Best for 2', 'Abstract games'] | Local=[]
- **Description**: Studio=`SET (98 chars)` | Local=`None`
- **Media Uploaded on Studio**:
  - Box: ✓ (Local: ✓)
  - Icon: ✓ (Local: ✓)
  - Banner: ✓ (Local: ✓)
  - Publisher: ✓ (Local: ✓)
  - Title: ✓ (Local: ✓)
  - Displays: 1 on Studio | 2 local (display_1.jpg, display_2.jpg)
- **Display Photos Nature**: Awaiting physical photos (Screenshots)

### Lordsofscotland (`lordsofscotland`)
- **Studio Project**: `lordsofscotland` | **Status**: Studio Accessible
- **BGG ID**: `None` | **Publisher**: `None`
- **Designer(s)**: Studio=`Richard James` | Local=`None`
- **Publication Year**: Studio=`2010` | Local=`None`
- **Zombie Mode Level**: Studio=`1` | Local=`None`
- **Characteristics**: Studio=`{'Complexity': '3', 'Luck': '2', 'Strategy': '4', 'Diplomacy': '1'}` | Local=`None`
- **Tags**: Studio=['Casual games', 'For regular players', 'Historical', 'Cards', 'Hand management'] | Local=[]
- **Description**: Studio=`SET (568 chars)` | Local=`None`
- **Media Uploaded on Studio**:
  - Box: ✓ (Local: ✓)
  - Icon: ✓ (Local: ✓)
  - Banner: ✓ (Local: ✓)
  - Publisher: ✓ (Local: ✓)
  - Title: ✓ (Local: ✓)
  - Displays: 5 on Studio | 2 local (display_1.jpg, display_2.jpg)
- **Display Photos Nature**: Awaiting physical photos (Title card)

### Pushfight (`pushfight`)
- **Studio Project**: `pushfight` | **Status**: Studio Accessible
- **BGG ID**: `None` | **Publisher**: `None`
- **Designer(s)**: Studio=`Brett Picotte` | Local=`None`
- **Publication Year**: Studio=`2008` | Local=`None`
- **Zombie Mode Level**: Studio=`0` | Local=`None`
- **Characteristics**: Studio=`{'Complexity': '1', 'Luck': '0', 'Strategy': '5', 'Diplomacy': '0'}` | Local=`None`
- **Tags**: Studio=['Casual games', 'Abstract games', 'Best for 2'] | Local=[]
- **Description**: Studio=`SET (815 chars)` | Local=`None`
- **Media Uploaded on Studio**:
  - Box: ✓ (Local: ✓)
  - Icon: ✓ (Local: ✓)
  - Banner: ✓ (Local: ✓)
  - Publisher: ✓ (Local: ✓)
  - Title: ✓ (Local: ✓)
  - Displays: 1 on Studio | 2 local (display_1.jpg, display_2.jpg)
- **Display Photos Nature**: Product photo / Box art (Licence declined)

### Mandala (`mandala`)
- **Studio Project**: `mandala` | **Status**: Studio Accessible
- **BGG ID**: `None` | **Publisher**: `None`
- **Designer(s)**: Studio=`Trevor Benjamin, Brett J. Gilbert` | Local=`None`
- **Publication Year**: Studio=`2019` | Local=`None`
- **Zombie Mode Level**: Studio=`NOT SET` | Local=`None`
- **Characteristics**: Studio=`None` | Local=`None`
- **Tags**: Studio=['Best for 2', 'Cards', 'Abstract games', 'Family'] | Local=[]
- **Description**: Studio=`SET (329 chars)` | Local=`None`
- **Media Uploaded on Studio**:
  - Box: ✓ (Local: ❌)
  - Icon: ✓ (Local: ❌)
  - Banner: ✓ (Local: ❌)
  - Publisher: ✓ (Local: ❌)
  - Title: ✓ (Local: ❌)
  - Displays: 5 on Studio | 0 local (None)
- **Display Photos Nature**: Legacy production art

### Vault (nestorgames) (`vaultnestorgames`)
- **Studio Project**: `vaultnestorgames` | **Status**: Studio Accessible
- **BGG ID**: `186404` | **Publisher**: `nestorgames`
- **Designer(s)**: Studio=`Néstor Romeral Andrés` | Local=`Néstor Romeral Andrés`
- **Publication Year**: Studio=`2015` | Local=`2015`
- **Zombie Mode Level**: Studio=`1` | Local=`1`
- **Characteristics**: Studio=`{'Complexity': '2', 'Luck': '1', 'Strategy': '4', 'Diplomacy': '1'}` | Local=`{'complexity': 2, 'luck': 1, 'strategy': 4, 'diplomacy': 1, 'note': 'PROPOSAL: sliders 1 to 5 in the Control Panel only.'}`
- **Tags**: Studio=['Casual games', 'Family'] | Local=['Abstract Strategy', 'Grid Movement']
- **Description**: Studio=`SET (751 chars)` | Local=`SET (747 chars)`
- **Media Uploaded on Studio**:
  - Box: ✓ (Local: ✓)
  - Icon: ✓ (Local: ✓)
  - Banner: ✓ (Local: ✓)
  - Publisher: ✓ (Local: ✓)
  - Title: ✓ (Local: ✓)
  - Displays: 4 on Studio | 2 local (display_1.jpg, display_2.jpg)
- **Display Photos Nature**: OK (physical photo from Nestor)

---

## 3. Backlog / Pipeline Games (No Studio Project Yet)

| Game Slug | Status | Notes |
| :--- | :--- | :--- |
| `counterplays` | Concept / Scaffolding | Registered folder in `games/`, not yet registered on Studio. |
| `flink` | Concept / Scaffolding | Registered folder in `games/`, not yet registered on Studio. |
| `manalath` | Concept / Scaffolding | Registered folder in `games/`, not yet registered on Studio. |
| `onager` | Concept / Scaffolding | Registered folder in `games/`, not yet registered on Studio. |
| `seven` | Concept / Scaffolding | Registered folder in `games/`, not yet registered on Studio. |
| `stack-22` | Concept / Scaffolding | Registered folder in `games/`, not yet registered on Studio. |
| `taiji` | Concept / Scaffolding | Registered folder in `games/`, not yet registered on Studio. |

---

## 4. How to Update This File

Run the automated audit script anytime:
```bash
python3 tools/audit_metadata.py
```
It reads local assets and queries Studio Control Panel GMM to generate this report.
