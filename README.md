# Xophz XP

> **Category:** True North · **Version:** 1.0.0

Experience Points (XP) Gamification System and Event Router for the COMPASS ecosystem.

## Description

**Xophz XP** is the core gamification engine for the COMPASS platform. It handles player progression, leveling formulas, and competitive leaderboards within the "True North" ecosystem. It transforms standard site operations into a deeply engaging, game-like experience.

### Core Capabilities

- **Player Progression** – Tracks Level, Experience Points (XP), Gold Pieces (GP), and Action Points (AP).
- **Leaderboard** – Global ranking system dynamically fed by the `useXpStore` Pinia store.
- **Masquerading System** – Linked to WordPress user login, but visually supports abstract avatars via `userMask`.
- **Leveling Logic** – Tiered leveling equations (Level 1 threshold at 600 XP).
- **Gamified Onboarding** – Prompts users to initialize their profile ("Join the Adventure") with a `<x-dialog>`.

## Design Language Integration

The XP System Native Views (`/routes/xp/`) aggressively utilize COMPASS atomic UI primitives:
- Uses `<x-card>` with `neon-card` utility classes and `backdrop-filter: blur(12px)`.
- Uses `<x-chip>` for variant-based coloring (`cyan-accent-4` for XP, `amber-accent-2` for GP).
- Strict adherence to FontAwesome 6 categories (`fad`, `fas`, `fal`).

## Requirements

- **Xophz COMPASS** parent plugin (active)
- WordPress 5.8+, PHP 7.4+

## Installation

1. Ensure **Xophz COMPASS** is installed and active.
2. Upload `xophz-compass-xp` to `/wp-content/plugins/`.
3. Activate through the Plugins menu.
4. Access via the COMPASS dashboard → **XP**.

## Frontend Routes

| Route | View | Description |
|---|---|---|
| `/xp` | Leaderboard | The global XP dashboard, rankings, and player stat breakdowns |

## PHP Class Map

| Class | File | Purpose |
|---|---|---|
| `Xophz_Compass_Xp` | `class-xophz-compass-xp.php` | Core plugin loader and global point hooks |
| `Xophz_Compass_Xp_Public` | `class-xophz-compass-xp-public.php` | Enqueues public-facing assets and scripts |
| `Xophz_Compass_Xp_Admin` | `class-xophz-compass-xp-admin.php` | Exposes admin configurations and menus |

## Changelog

### 1.0.0

- Refactored UI array to COMPASS atomic primitives (`x-card`, `x-chip`).
- Implementation of True North aesthetic (Cyan glows, dark mode).
- Centralized Data Store tracking XP, GP, and AP.
