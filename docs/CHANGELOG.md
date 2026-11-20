# Changelog

All notable changes to the Xophz XP plugin will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Fixed
- **An incomplete plugin folder no longer takes the site down**: the bootstrap hard-required `includes/class-xophz-compass-xp.php`, so when a deploy left the folder half copied on 2026-10-26 (80 of 109 files, two Hookshot receivers installing the same release at once) every request on the seven network sites that activate this plugin was a fatal for two days. `xophz_compass_xp_includes_ready()` now checks both bootstrap includes first; when one is missing it logs the file, shows an admin and network-admin notice, defines `XOPHZ_COMPASS_XP_INCOMPLETE` and returns from the main file, and the next request loads normally once the files are there. Tests: `tests/bootstrap-guard.test.mjs`. The deploy side is fixed in Hookshot (install lock, staged swap).

### Added
- **xp-pro license product (#1557, epic #1552)**: `Xophz_Compass_Xp_Pro::register_product` adds `xp-pro` to Gatekeeper's `gatekeeper_license_products` registry with everything XP Pro owns: the key format (`XP-PRO-`), the account (`family_id`, a lowercase UUID v4) and device validators, 10 devices, the 3 day grace and 7 day trial, the plan catalog (`get_plans`), the Stripe product name (`doitforthexp`), the webhook and Stripe setup options, the settings schema (`xp_pro_ai_daily_limit`, `xp_pro_lifetime_on_sale`) and the key email text. Every option name is the name the records already live under, so there is no data migration. Gatekeeper serves a built-in copy of the definition until this plugin registers it.
- **XP Pro AI proxy moved here (#1557)**: `Xophz_Compass_Xp_Pro_AI` (`includes/class-xophz-compass-xp-pro-ai.php`) serves `POST /compass/v1/gatekeeper/xp-pro/ai` with the same four tasks, prompts, parsing, Gemini call, limits and sandbox rule as the Gatekeeper copy. It checks the key with `gatekeeper_license_for_key('xp-pro', ...)`, rate limits with `gatekeeper_rate_limited` and counts the day with `gatekeeper_counter_bump`; a site without them answers 503 `gatekeeper_missing`. A device the key has not seen now takes a free slot (as in validate) and a key already on 10 devices answers 409 `device_limit`, where the proxy used to answer 402 for both; an unknown key still answers 402 `pro_required`.

### Changed
- The family Pro gate asks `gatekeeper_license_entitled('xp-pro', family, now)` when Gatekeeper defines it, and the `Gatekeeper_Xp_Pro_Licenses` facade of an older Gatekeeper otherwise.

## [26.10.19] - 2026-10-19

### Added
- **Two-phone play events (#6097)**: the `xp-family/v1` event stream takes `quest.claimed` (from the home device or from a hero key for its own hero only), `quest.approved`, `quest.rejected`, `hero.sheet` (stats and up to 60 quests, 16 KB) and `claim.settled` (status `pending`, `approved` or `rejected`), all family scope. A kind may now name a list of writers and its own `max_bytes`; a hero key sending any other kind, or a claim for another hero, is refused per event in `rejected`.

## [2026-10-12]

### Added
- **XP Pro catalog and buy resolver (#6011)**: `Xophz_Compass_Xp_Pro` (`includes/class-xophz-compass-xp-pro.php`) holds the Do It For The XP plans (`monthly` $4.99/month and `yearly` $39.99/year, both with a 7 day trial, and `lifetime` $79 one time, sold only while the option `xp_pro_lifetime_on_sale` is true). It resolves `/buy/doitforthexp/{plan}?family_id=&device_id=&return_url=` for Bazaar (a lowercase UUID v4 family id and an 8 to 64 character device id are required, anything else is a 400; an unknown or off-sale plan is a 404) and fills Gatekeeper's `gatekeeper_xp_pro_plans` filter for `GET /xp-pro/plans`. Bazaar builds the success and cancel urls from `return_url` after its trusted-domain check. Separate from the admin-set `_xp_is_pro` user flag.
