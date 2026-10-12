# Changelog

All notable changes to the Xophz XP plugin will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [2026-10-12]

### Added
- **XP Pro catalog and buy resolver (#6011)**: `Xophz_Compass_Xp_Pro` (`includes/class-xophz-compass-xp-pro.php`) holds the Do It For The XP plans (`monthly` $4.99/month and `yearly` $39.99/year, both with a 7 day trial, and `lifetime` $79 one time, sold only while the option `xp_pro_lifetime_on_sale` is true). It resolves `/buy/doitforthexp/{plan}?family_id=&device_id=&return_url=` for Bazaar (a lowercase UUID v4 family id and an 8 to 64 character device id are required, anything else is a 400; an unknown or off-sale plan is a 404) and fills Gatekeeper's `gatekeeper_xp_pro_plans` filter for `GET /xp-pro/plans`. Bazaar builds the success and cancel urls from `return_url` after its trusted-domain check. Separate from the admin-set `_xp_is_pro` user flag.
