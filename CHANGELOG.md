# Changelog

All notable changes to bbDKP v2.x will be documented in this file. Format
follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [2.0.0-alpha1] — 2026-05-23

Ground-up rewrite of bbDKPMOD v1.4.6. New architecture: bbAccounts as
canonical ledger, bbDKP owns only domain metadata (pools, events, raids,
attendees, items, loot, adjustments) plus a ledger-link bridge table.

### Added

- Extension scaffolding: `composer.json`, `ext.php` with hard-dep check
  (bbGuild ≥ 2.0.0-b1, bbAccounts ≥ 1.1.0-alpha), `config/services.yml`,
  `config/tables.yml`, `config/routing.yml`.
- Single squashed install migration with all v2.0 schema:
  `bb_dkp_pools`, `bb_dkp_events`, `bb_dkp_raids`, `bb_dkp_raid_attendees`,
  `bb_dkp_items`, `bb_dkp_loot`, `bb_dkp_adjustments`,
  `bb_dkp_adjustment_recipients`, `bb_dkp_ledger_link`.
- Four permissions registered with bbDKP-prefixed display labels:
  `a_bbdkp`, `m_bbdkp`, `u_bbdkp_view`, `u_bbdkp_view_others`.
- ACP_DKP category under `ACP_BBGUILD_MAINPAGE` with pool and event
  sub-modules.
- **`dkp_ledger` service** (single posting surface to bbAccounts):
  - `mint_pool_accounts(pool_id)` — creates 5 per-pool accounts
    (`dkp_p<id>_w/ra/lp/ac/ad`) in bbAccounts. The wallet account uses
    `subledger_type='character'`.
  - `post_raid_award`, `post_loot_purchase`, `post_adjustment` — all
    write balanced journal entries via bbAccounts `create_entry()` with
    both `subledger_user_id=0` and `subledger_player_id=<player>` on
    character lines.
  - `reverse(link_id)` — posts the inverse journal entry via
    `bbaccounts->reverse_entry()` and records the link.
  - `archive_pool_accounts` is a no-op in alpha1; `delete_pool_accounts`
    always throws (bbAccounts v1.1.0-alpha has no account-deactivate /
    delete API).
- **`pool_manager` service + pool ACP module** — full CRUD with name-
  uniqueness pre-check, soft-disable, set-default (exactly-one-per-guild
  enforced), delete-with-guard surfacing POOL_DELETE_BLOCKED.
- **`event_manager` service + event ACP module** — full CRUD with
  per-pool filter, soft-disable, hard-delete blocked when raids
  reference the event.

### Out of scope (deferred per spec §2)

- Raid + attendee CRUD (alpha2)
- Adjustment CRUD (alpha2)
- Item catalog + loot history (alpha3)
- Front-end pages (beta1)
- UCP "My DKP" (beta2)
- Decay / zerosum (v2.1), EP/GP (v2.2), portal modules (v2.3),
  in-game log ingest (v2.4)

### Notes

- Clean-slate install only. Legacy bbDKPMOD v1.4.6 data is not migrated.
- Hard-delete pool / hard-delete event with referenced rows are
  intentionally blocked at the service layer to preserve audit history.
- Pool hard-delete throws unconditionally pending a future bbAccounts
  ticket exposing an account-delete API.
- Automated test suite deferred to alpha2 (no functional or service-layer
  tests ship with alpha1). All PHP files pass `php -l` syntax check.

[2.0.0-alpha1]: https://github.com/avatharbe/bbDKP/releases/tag/v2.0.0-alpha1
