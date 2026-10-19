# Changelog

All notable changes to the Xophz XP plugin will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [26.10.19] - 2026-10-19

### Added
- **Two-phone play events (#6097)**: the `xp-family/v1` event stream takes `quest.claimed` (from the home device or from a hero key for its own hero only), `quest.approved`, `quest.rejected`, `hero.sheet` (stats and up to 60 quests, 16 KB) and `claim.settled` (status `pending`, `approved` or `rejected`), all family scope. A kind may now name a list of writers and its own `max_bytes`; a hero key sending any other kind, or a claim for another hero, is refused per event in `rejected`.

## [2026-10-12]

### Added
- **XP Pro catalog and buy resolver (#6011)**: `Xophz_Compass_Xp_Pro` (`includes/class-xophz-compass-xp-pro.php`) holds the Do It For The XP plans (`monthly` $4.99/month and `yearly` $39.99/year, both with a 7 day trial, and `lifetime` $79 one time, sold only while the option `xp_pro_lifetime_on_sale` is true). It resolves `/buy/doitforthexp/{plan}?family_id=&device_id=&return_url=` for Bazaar (a lowercase UUID v4 family id and an 8 to 64 character device id are required, anything else is a 400; an unknown or off-sale plan is a 404) and fills Gatekeeper's `gatekeeper_xp_pro_plans` filter for `GET /xp-pro/plans`. Bazaar builds the success and cancel urls from `return_url` after its trusted-domain check. Separate from the admin-set `_xp_is_pro` user flag.
