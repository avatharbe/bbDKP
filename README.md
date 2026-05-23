bbDKP for phpBB 3.3
===================

DKP (Dragon Kill Points) extension for phpBB 3.3, a ground-up rewrite of the 2016 bbDKPMOD as a modern phpBB extension. Integrates with [bbGuild](https://github.com/avatharbe/bbguild) for guild and character management and [bbAccounts](https://github.com/avatharbe/bbAccounts) as the canonical double-entry ledger.

Unlike the legacy MOD, bbDKP v2 does **not** store per-player balances in denormalised columns. Every DKP transaction is a balanced journal entry in bbAccounts; standings and history are derived from `SUM()` over journal lines. Editing or deleting a raid posts reversing entries instead of mutating history.

#### Status

**v2.0.0-alpha1** — foundation only. Pool and event management work; raids, loot, and adjustments land in subsequent alphas.

See [`contrib/specs/2026-05-23-bbdkp-v2-design.md`](contrib/specs/2026-05-23-bbdkp-v2-design.md) for the full design.

#### Requirements

- phpBB 3.3.0 or higher
- PHP 8.1 or higher
- [avathar/bbguild](https://github.com/avatharbe/bbguild) ≥ 2.0.0-b1
- [avathar/bbaccounts](https://github.com/avatharbe/bbAccounts) ≥ 1.1.0-alpha (character subledger required)

The Enable button stays greyed out in phpBB ACP until both dependencies are installed and enabled at the required versions.

#### What ships in alpha1

- Single squashed install migration with all v2.0 schema (9 tables)
- Four permissions: `a_bbdkp`, `m_bbdkp`, `u_bbdkp_view`, `u_bbdkp_view_others`
- ACP_DKP category registered under bbGuild's `ACP_BBGUILD_MAINPAGE`
- `dkp_ledger` service — the single posting surface to bbAccounts (mints 5 chart-of-accounts entries per pool; supports posting + reversal; archive and delete are no-op / unsupported pending bbAccounts API additions)
- `pool_manager` service + full pool ACP module (add / edit / disable / set-default / delete-with-guard)
- `event_manager` service + full event ACP module (add / edit / disable / delete with raid-reference guard) with per-pool filter

#### What's deferred to later alphas / betas

- Raid + attendee CRUD (alpha2)
- Adjustment CRUD (alpha2)
- Item catalog + loot history CRUD (alpha3)
- Front-end pages: standings, raid history, loot history, per-character DKP (beta1)
- UCP "My DKP" page (beta2; depends on bbAccounts per-subledger activity API)
- Point decay, zero-sum DKP (v2.1)
- EP/GP system (v2.2)
- Portal modules (v2.3)
- In-game raid log ingest (v2.4)

#### Installation

1. Ensure bbGuild ≥ 2.0.0-b1 and bbAccounts ≥ 1.1.0-alpha are installed and enabled.
2. Copy this extension to `/ext/avathar/bbdkp/` (so `ext.php` is at `/ext/avathar/bbdkp/ext.php`).
3. In phpBB ACP: Customise → Manage extensions → bbDKP → Enable.

#### License

[GPL-2.0-only](license.txt).
