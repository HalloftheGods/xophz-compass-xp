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
4. Access via the My Compass dashboard → **XP**.

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
| `Xophz_Compass_Xp_Keys` | `admin/class-xophz-compass-xp-keys.php` | `/xp/v1/keys` and API key authentication |
| `Xophz_Compass_Xp_Key_Grants` | `admin/class-xophz-compass-xp-key-grants.php` | Which tier and scopes a caller may put on a key |
| `Xophz_Compass_Xp_Key_Scopes` | `admin/class-xophz-compass-xp-key-scopes.php` | Route-to-scope map and its enforcement |

## API Keys and Scopes

Send a key in the `X-API-Key` header or as `Authorization: Bearer <key>`. A key in the URL (`?api_key=`) gets 400 `xp_api_key_in_url`; rotate any key that was sent that way. Keys are stored as SHA-256 hashes; the plain key is shown once, when it is created.

A key acts as its owner (the owner's capability checks still apply) and only within its scopes. A key without the scope a route needs gets 403 `xp_insufficient_scope`. A key that carries any scope XP does not recognise, or no scopes, is refused on every route. `admin:all` covers every scope. Signed-in (cookie and nonce) requests are not scoped. The map lives in `Xophz_Compass_Xp_Key_Scopes::ROUTES`; a route missing from it refuses keys.

| Route | Method | Scope |
|---|---|---|
| `/xp/v1`, `/state`, `/logs`, `/my-badges`, `/my-metrics`, `/badges`, `/goals`, `/subscriptions/checkout-url` | GET | `xp:read` |
| `/simulate-decay` | POST | `xp:write` |
| `/fire/{action_slug}` | GET, POST | `xp:actions:trigger` |
| `/spend-gp`, `/transaction` | POST | `xp:bank` |
| `/goals`, `/goals/{id}`, `/actions`, `/actions/{id}`, `/pro` | POST | `xp:admin` |
| `/actions` | GET | `xp:admin` |
| `/keys`, `/keys/{id}`, `/subscriptions/checkout` | any | none: a key gets 403 `key_session_required` |

`xp:leaderboards` is recognised but no route needs it yet. HEAD is scoped as GET; PUT and PATCH as POST.

## Changelog

### 1.0.0

- Refactored UI array to COMPASS atomic primitives (`x-card`, `x-chip`).
- Implementation of True North aesthetic (Cyan glows, dark mode).
- Centralized Data Store tracking XP, GP, and AP.
