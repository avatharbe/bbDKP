# bbDKP v2.0 — design spec

- **Date:** 2026-05-23
- **Status:** draft (under user review)
- **Author:** Andreas, with Claude assistance
- **Tracks:** [avatharbe/bbDKP#1](https://github.com/avatharbe/bbDKP/issues/1), [#2](https://github.com/avatharbe/bbDKP/issues/2)
- **Predecessors:**
  - bbDKPMOD v1.4.6 (phpBB 3.0 MOD, frozen 2016) — `ext/avathar/bbDKPMOD/`
  - bbPoints v2.0.0 — reference architecture for bbAccounts-as-canonical-store
  - bbAccounts v1.0.0-alpha — the double-entry ledger this design rides on

## 1. Summary

bbDKP v2.0 is a ground-up rewrite of the 2016 bbDKPMOD as a modern phpBB 3.3 extension that integrates with the avathar forum ecosystem:

- **bbGuild** supplies the guild, the players, the roster, and the routing.
- **bbAccounts** is the canonical store for every DKP transaction. bbDKP owns no point-balance fields and no transaction ledger of its own; all point movements are journal entries in bbAccounts.
- **bbDKP** owns only the domain metadata: pools, events, raids, raid attendees, item catalog, loot history, adjustments — plus a bridge table linking each domain row to the bbAccounts journal entries it produced.

The v1.4.6 denormalised cache (`bbdkp_memberdkp` with columns like `member_earned`, `member_spent`, `member_raid_decay`) is gone. Standings and history are computed by aggregating bbAccounts subledger entries.

## 2. Goals and non-goals

**In scope for v2.0.0:**

- DKP pool management (MultiDKP from day 1)
- Event (encounter type) management
- Raid management with attendance tracking and per-attendee value override
- Item catalog (per-game) and loot history (one row per drop event)
- Manual adjustments (single + bulk)
- Per-pool standings, raid history, loot history, per-character DKP page
- ACP for officers; UCP "My DKP" for members
- bbAccounts integration as canonical ledger with reversal semantics (never delete journal entries)

**Out of scope for v2.0.0 (deferred to later minor versions):**

- Point decay and zero-sum DKP (`v2.1`, issue [#9](https://github.com/avatharbe/bbDKP/issues/9))
- EP/GP point system (`v2.2`, issue [#11](https://github.com/avatharbe/bbDKP/issues/11))
- Portal modules (`v2.3`, issue [#12](https://github.com/avatharbe/bbDKP/issues/12))
- In-game raid log ingest pipeline (`v2.4`, issue [#13](https://github.com/avatharbe/bbDKP/issues/13))
- Migration importer from bbDKPMOD v1.4.6 data (clean-slate decision — see §3)

**Explicit non-goals:**

- bbDKP does not duplicate bbGuild features (roster, ranks, classes, races, factions, multi-guild, recruitment, member CRUD). Those stay in bbGuild.
- bbDKP does not duplicate bbAccounts features (chart of accounts, journal, reports, balance views). Those stay in bbAccounts.
- bbDKP does not parse raid log files in v2.0. The schema supports future log import, but the ingest pipeline (issue #13) is its own design.

## 3. Decisions log

The following decisions were reached during brainstorming and form the foundation of the rest of this spec.

| # | Decision | Why |
|---|---|---|
| D1 | bbAccounts is the canonical ledger. No bb_dkp transaction table. | Mirrors bbPoints v2.0. Avoids the "wrong" architecture issue [#1](https://github.com/avatharbe/bbDKP/issues/1) calls out. Reuses bbAccounts reporting and audit. |
| D2 | Multi-pool (MultiDKP) from day 1. | Most active guilds in v1.4.6 used it. Retrofitting is harder than building it in. |
| D3 | Each pool auto-mints a parallel set of accounts in bbAccounts (5 per pool). | No bbAccounts schema change required for analytical dimensions; uses existing chart-of-accounts model. |
| D4 | Subledger key = `bb_players.player_id` (not `phpbb_users.user_id`). | One forum user can have multiple characters; each character has its own DKP balance per pool. Matches v1.4.6 reality. |
| D5 | Lean MVP (issues #2, #3, #5, #7, #4, #6, #8, partial #10). Defer #9, #11, #12, #13 to follow-up minors. | Smaller v2.0 surface; faster to first release. |
| D6 | Clean slate; no bbDKPMOD v1.4.6 data migration. | Simplifies release. If migration is needed, ship it as a separate v2.x importer. |
| D7 | Standalone extension (`avathar/bbdkp`), hard deps on bbGuild ≥ 2.0.0-b1 and bbAccounts ≥ 1.0.0. | Mirrors bbPoints' relationship to bbAccounts. |
| D8 | Event ↔ pool is N:1 (event has one `pool_id` FK). | Matches v1.4.6 model and queries. Simpler ACP UX. Same boss in two pools = two event rows. |
| D9 | Raid value cascade: `attendee.value_override` → `raid.raid_value` → `event.event_value`. | v1.4.6 stored per-attendee value, supports late-joiners earning less. |
| D10 | Loot is split into a **catalog** (`bb_dkp_items`) and a **history** (`bb_dkp_loot`). One catalog row per game item; many loot rows per item across many raids. | Per-game item catalog with stable tooltip IDs; loot history tracks who-paid-what-when. |
| D11 | Single buyer per drop. No split-pay. | Split-pay does not exist in real raids. |
| D12 | Catalog is per-game (`bb_dkp_items.game_id`, `UNIQUE(game_id, item_name)`). | Avoids cross-game name collisions. Future log import scopes naturally to the guild's game. |
| D13 | Game item catalogs are seeded by each game plugin via a `bbdkp.item_seed_provider` tagged service. | Each game plugin owns its data. Adding a new game = new plugin, no bbDKP change. |
| D14 | bbDKPMOD v1.4.6 is treated as conceptual reference only. No PHP code is lifted. | v1.4.6 is phpBB 3.0 MOD format with hardcoded SQL; doesn't fit phpBB 3.3 DI. |

## 4. Architecture

```
┌─────────────────────────────────────────────────┐
│ ACP modules: pools, events, raids, loot,        │
│ adjustments, items (catalog browser)            │   bbDKP UI layer
│ UCP module: My DKP                              │
│ Front controllers: standings, raid history,     │
│ loot history, per-character DKP page            │
└─────────────────────────────────────────────────┘
                 │
┌─────────────────────────────────────────────────┐
│ Services:                                       │
│  • pool_manager, event_manager,                 │
│    raid_manager, item_manager, loot_manager,    │   bbDKP domain layer
│    adjustment_manager                           │
│  • standings_service (read-side)                │
│  • dkp_ledger          ← THE SINGLE WRITER      │
│                          to bbAccounts          │
└─────────────────────────────────────────────────┘
           │
┌─────────────────────────────────────────────────┐
│ bbDKP metadata tables (the WHY/WHERE):          │
│   bb_dkp_pools, bb_dkp_events,                  │
│   bb_dkp_raids, bb_dkp_raid_attendees,          │
│   bb_dkp_items (catalog), bb_dkp_loot (history),│
│   bb_dkp_adjustments,                           │
│   bb_dkp_adjustment_recipients,                 │
│   bb_dkp_ledger_link (bridge to bbAccounts)     │
└─────────────────────────────────────────────────┘
                   │
┌─────────────────────────────────────────────────┐
│ bbAccounts journal (the MONEY — immutable):     │   canonical ledger
│   journal_entries / journal_lines               │
│   chart-of-accounts (auto-minted per pool)      │
│   subledger: bb_players.player_id               │
└─────────────────────────────────────────────────┘
                   │
┌─────────────────────────────────────────────────┐
│ bbGuild tables (read-only from bbDKP):          │
│   bb_guild, bb_players, bb_games                │
└─────────────────────────────────────────────────┘
``` 

**Single posting rule:** `bbdkp\service\dkp_ledger` is the only writer to bbAccounts from bbDKP. Every domain service calls into it. There is no direct `INSERT INTO journal_entries` anywhere in bbDKP.

**Reversibility:** Every editable domain mutation (raid, loot, adjustment) emits a **reversing journal entry** when modified or deleted — never a delete on bbAccounts side. The `bb_dkp_ledger_link` table maps each domain row to its journal entries and tracks reversal chains.

## 5. Data model

All tables use `phpbb_` table prefix; names below omit it. All `audit cols` = `added_by` (user_id), `added_at` (unix), `updated_by`, `updated_at`.

### 5.1 bb_dkp_pools

| Column | Type | Notes |
|---|---|---|
| `pool_id` | UINT PK auto | |
| `guild_id` | UINT FK → bb_guild | pools are guild-scoped |
| `pool_name` | VCHAR_UNI:255 | UNIQUE per guild |
| `pool_desc` | VCHAR_UNI:255 | |
| `pool_status` | BOOL | 1 = active, 0 = soft-disabled |
| `pool_default` | BOOL | exactly one default per guild (service-enforced) |
| audit cols | | |

### 5.2 bb_dkp_events

| Column | Type | Notes |
|---|---|---|
| `event_id` | UINT PK auto | |
| `pool_id` | UINT FK → bb_dkp_pools | N:1 — each event belongs to one pool |
| `event_name` | VCHAR_UNI:255 | |
| `event_value` | DECIMAL(11,2) | default DKP value used when a raid of this event is created |
| `event_color` | VCHAR:8 | UI hint |
| `event_icon` | VCHAR:255 | image path |
| `event_status` | BOOL | active flag |
| audit cols | | |

### 5.3 bb_dkp_raids

| Column | Type | Notes |
|---|---|---|
| `raid_id` | UINT PK auto | |
| `guild_id` | UINT FK → bb_guild | |
| `pool_id` | UINT FK → bb_dkp_pools | pool this raid pays into |
| `event_id` | UINT FK → bb_dkp_events | encounter type |
| `raid_start` | TIMESTAMP | unix seconds |
| `raid_end` | TIMESTAMP NULL | NULL if raid is a point-in-time event |
| `raid_value` | DECIMAL(11,2) | default DKP for this raid (pre-filled from `event.event_value`, overridable per raid) |
| `raid_note` | TEXT_UNI | BBCode |
| audit cols | | |

### 5.4 bb_dkp_raid_attendees

| Column | Type | Notes |
|---|---|---|
| `attendee_id` | UINT PK auto | surrogate PK (so `bb_dkp_ledger_link.entity_id` can reference it as a single UINT) |
| `raid_id` | UINT FK → bb_dkp_raids | |
| `player_id` | UINT FK → bb_players | |
| `join_time` | TIMESTAMP NULL | for future time-bonus (deferred); NULL = "full raid" |
| `leave_time` | TIMESTAMP NULL | |
| `value_override` | DECIMAL(11,2) NULL | NULL = use `raid.raid_value` |

Indexes: `UNIQUE(raid_id, player_id)` — a player attends a raid at most once.

### 5.5 bb_dkp_items (catalog — game inventory)

| Column | Type | Notes |
|---|---|---|
| `item_id` | UINT PK auto | |
| `game_id` | VCHAR:10 FK → bb_games | per-game catalog |
| `item_name` | VCHAR_UNI:255 | |
| `item_gameid` | VCHAR:50 NULL | tooltip ID (wowhead id, etc.) |
| `itempool_id` | UINT FK NULL → bb_dkp_itempools | optional grouping (table deferred to v2.1 if needed) |
| audit cols | | |

Indexes: `UNIQUE(game_id, item_name)` — canonical naming, dedupe typos.

### 5.6 bb_dkp_loot (history — drop events)

| Column | Type | Notes |
|---|---|---|
| `loot_id` | UINT PK auto | |
| `item_id` | UINT FK → bb_dkp_items | which catalog item dropped |
| `raid_id` | UINT FK → bb_dkp_raids | which raid the drop happened in |
| `player_id` | UINT FK → bb_players | the buyer (single — D11) |
| `value` | DECIMAL(11,2) | DKP paid for this specific drop |
| `drop_date` | TIMESTAMP | |
| audit cols | | |

Indexes: `(raid_id)`, `(item_id)`, `(player_id, drop_date)`.

### 5.7 bb_dkp_adjustments

| Column | Type | Notes |
|---|---|---|
| `adjustment_id` | UINT PK auto | |
| `pool_id` | UINT FK → bb_dkp_pools | |
| `adjustment_date` | TIMESTAMP | |
| `adjustment_reason` | VCHAR_UNI:255 | |
| `group_key` | VCHAR:64 | UUID-style; same for all recipients in one bulk action |
| audit cols | | |

### 5.8 bb_dkp_adjustment_recipients

| Column | Type | Notes |
|---|---|---|
| `recipient_id` | UINT PK auto | surrogate PK (referenced by `bb_dkp_ledger_link.entity_id`) |
| `adjustment_id` | UINT FK → bb_dkp_adjustments | |
| `player_id` | UINT FK → bb_players | |
| `amount` | DECIMAL(11,2) | signed: positive = grant, negative = debit |

Indexes: `UNIQUE(adjustment_id, player_id)` — at most one row per recipient per adjustment.

### 5.9 bb_dkp_ledger_link (bridge to bbAccounts)

| Column | Type | Notes |
|---|---|---|
| `link_id` | UINT PK auto | |
| `entity_type` | VCHAR:32 | enum: `raid_attendee`, `loot`, `adjustment_recipient` |
| `entity_id` | UINT | surrogate PK of the corresponding bbDKP row: `bb_dkp_raid_attendees.attendee_id`, `bb_dkp_loot.loot_id`, or `bb_dkp_adjustment_recipients.recipient_id` |
| `journal_entry_id` | UINT | FK → bbAccounts `journal_entries.entry_id` |
| `reversal_of` | UINT NULL | link_id of the original entry being reversed (NULL for forward posts) |
| `posted_at` | TIMESTAMP | |

Indexes: `(entity_type, entity_id)`, `(journal_entry_id)`.

### 5.10 Deferred tables (not in MVP)

- `bb_dkp_itempools` — item category grouping (mentioned in issue #6, deferred unless users ask)
- `bb_dkp_decay_log` — decay run history (v2.1)
- `bb_dkp_zerosum_distribution` — zerosum bonus tracking (v2.1)
- EP/GP-specific tables — v2.2

## 6. bbAccounts integration

### 6.1 Chart of accounts (auto-minted per pool)

When bbDKP creates a pool with `pool_id = 5`, it mints these accounts in bbAccounts via the bbAccounts admin API:

| Account name | Type | Subledger | Purpose |
|---|---|---|---|
| `dkp_pool_5_player_wallets` | Liability | `'character'` (player_id) | per-player DKP balance |
| `dkp_pool_5_raid_attendance` | Expense | — | source account for raid awards |
| `dkp_pool_5_loot_proceeds` | Revenue | — | destination for loot purchases |
| `dkp_pool_5_adjust_credit` | Expense | — | source for positive adjustments |
| `dkp_pool_5_adjust_debit` | Revenue | — | destination for negative adjustments |

**Pool soft-disable:** accounts persist (journal history is immutable).
**Pool hard-delete:** only allowed if the pool has zero posted journal entries — accounts are deleted in the same transaction.

### 6.2 Posting verbs (all on `bbdkp\service\dkp_ledger`)

**`post_raid_award(attendee_id, amount)`**
```
DR  dkp_pool_<id>_raid_attendance      amount
CR  dkp_pool_<id>_player_wallets       amount    subledger=player_id
```
Link row: `(entity_type='raid_attendee', entity_id=attendee_id, je=…)`

**`post_loot_purchase(loot_id)`**
```
DR  dkp_pool_<id>_player_wallets       value     subledger=buyer player_id
CR  dkp_pool_<id>_loot_proceeds        value
```
Link row: `(entity_type='loot', entity_id=loot_id, je=…)`

**`post_adjustment(recipient_id)`**
```
if amount > 0:
    DR  dkp_pool_<id>_adjust_credit    amount
    CR  dkp_pool_<id>_player_wallets   amount    subledger=player_id
if amount < 0:
    DR  dkp_pool_<id>_player_wallets   |amount|  subledger=player_id
    CR  dkp_pool_<id>_adjust_debit     |amount|
```
Link row: `(entity_type='adjustment_recipient', entity_id=recipient_id, je=…)`

**`reverse(link_id)`**

Looks up the original journal entry, posts the inverse (DR↔CR swapped), creates a new link row with `reversal_of=<original_link_id>`. Never deletes journal entries.

### 6.3 Edit and delete flows

**Edit raid value (50 → 75):**
1. `raid_manager->update_raid($raid_id, ['raid_value' => 75])`
2. Service loads all `bb_dkp_raid_attendees` for the raid where `value_override IS NULL`
3. For each: `dkp_ledger->reverse($old_link_id)` then `dkp_ledger->post_raid_award($attendee, 75)`
4. Net effect on player wallet = +25 (reversed -50, posted +75)

**Delete a raid:**
1. Reverse all forward postings linked to this raid's attendees and its loot rows
2. Soft-delete or hard-delete the metadata row depending on policy (default: soft-delete to keep history queryable)

**Edit loot row value:** same pattern — reverse, post.

### 6.4 Standings query (per pool)

Pool standings = subledger balance of `dkp_pool_<id>_player_wallets`:

```sql
SELECT
  je.subledger_entity_id AS player_id,
  SUM(CASE WHEN jl.side = 'CR' THEN jl.amount ELSE -jl.amount END) AS net_dkp
FROM journal_entries je
JOIN journal_lines jl ON jl.entry_id = je.entry_id
WHERE jl.account_id = ?    -- dkp_pool_<id>_player_wallets
GROUP BY je.subledger_entity_id
ORDER BY net_dkp DESC;
```

Exact column names depend on bbAccounts' final schema; verify during plan phase.

Earned / Spent / Adjustments breakdown columns are the same query filtered by the source/destination account.

### 6.5 Per-player history page

Uses bbAccounts' per-subledger activity API ([bbAccounts#13](https://github.com/avatharbe/bbAccounts/issues/13)). For each transaction returned, bbDKP joins `bb_dkp_ledger_link` to attach the WHY (raid / loot / adjustment row) for display.

### 6.6 bbAccounts prerequisites

**Status update (2026-05-23):** Prerequisite #1 was delivered in bbAccounts v1.1.0-alpha. Prerequisite #2 is still pending and only needed for beta2 (UCP "My DKP" history). bbDKP alpha1 implementation is unblocked.

1. **Character subledger support — DELIVERED in bbAccounts v1.1.0-alpha.** The delivered shape differs from the originally proposed "subledger source registry": bbAccounts added a fourth `subledger_type` enum value `'character'` and a parallel `subledger_player_id` UINT column on `bbaccounts_journal_lines`, rather than a generic source-table registry. The end goal — letting bbDKP attribute balances to `bb_players.player_id` instead of `phpbb_users.user_id` — is satisfied. The bbDKP API surface against bbAccounts is therefore:

   - **Pool account creation:** `ledger::create_account($code, $name, 'liability', 'POINTS', 0, 'character')` mints a character-type wallet account. The other 4 per-pool accounts (`raid_attendance`, `loot_proceeds`, `adjust_credit`, `adjust_debit`) use `subledger_type=''` (no subledger).
   - **Posting:** every line array passed to `ledger::create_entry()` includes `subledger_user_id` (= 0 for character lines) AND `subledger_player_id` (= player_id for character lines, 0 otherwise). Strict mutual exclusion is enforced by `validate_lines()`.
   - **Balance reads:** `ledger::get_subledger_balance_by_character($account_id, $player_id, $as_of)` for single-account; `ledger::get_subledger_account_balances_by_character($player_id, $from, $to)` for full per-character standings. Return shape mirrors the existing user-keyed methods (`opening`/`period_debit`/`period_credit`/`closing`).
   - **Character deletion / anonymization:** bbDKP listens to bbGuild's player-deleted event and calls `ledger::anonymize_player_subledger($player_id)`. bbAccounts ships no listener for this — source-agnostic by design.

   Reference: `ext/avathar/bbaccounts/contrib/specs/2026-05-23-character-subledger-design.md`.

2. **Per-subledger activity API ([bbAccounts#13](https://github.com/avatharbe/bbAccounts/issues/13)) — STILL PENDING.** Needed for the UCP "My DKP" page in bbDKP beta2 (transaction-level history per character). bbPoints UCP needs the user-keyed equivalent for the same reason. NOT required for bbDKP alpha1 or beta1; can land in bbAccounts on its own schedule before bbDKP beta2.

## 7. Module and route surface

### 7.1 ACP modules

Registered under `ACP_BBGUILD_MAINPAGE` (consistent with bbguild's own modules and game plugins):

```
ACP_BBGUILD_MAINPAGE
└── ACP_DKP                          (new top-level category)
    ├── ACP_DKP_POOLS                list / add / edit / disable
    ├── ACP_DKP_EVENTS               list / add / edit / disable
    ├── ACP_DKP_RAIDS                list / add / edit / delete; per-attendee value override
    ├── ACP_DKP_LOOT                 list / add / edit / delete; autocomplete from bb_dkp_items
    ├── ACP_DKP_ADJUSTMENTS          list / add / bulk-add / edit / delete
    └── ACP_DKP_ITEMS                catalog browser; manual add/edit, mostly populated via autocomplete
```

Each module follows phpBB ACP conventions: `acp/acp_<mode>_module.php` + matching info file + `language/<lang>/info_acp_<mode>.php`.

### 7.2 UCP module

```
UCP
└── UCP_BBDKP
    └── My DKP                       per-pool standings, transaction history, raid attendance
```

If a phpBB user has multiple linked characters, the UCP page shows a character switcher.

### 7.3 Front-end routes

Under bbGuild's `/guild/{guild_id}/` route family:

| Route | Purpose | Controller |
|---|---|---|
| `/guild/{guild_id}/dkp/standings` | per-pool standings; pool selector via `?pool=` | `standings_controller` |
| `/guild/{guild_id}/dkp/raids` | raid history list; filter by pool/event/date | `raids_controller::list` |
| `/guild/{guild_id}/dkp/raids/{raid_id}` | raid detail (attendees + loot) | `raids_controller::view` |
| `/guild/{guild_id}/dkp/loot` | loot history list; filter by pool/item/player | `loot_controller::list` |
| `/guild/{guild_id}/dkp/players/{player_id}` | per-character DKP page | `player_controller::view` |

### 7.4 Permissions

Display labels prefixed `bbDKP: ` (Recent-Topics / bbAccounts convention).

| Permission | Default grant | Gates |
|---|---|---|
| `a_bbdkp` | `ROLE_ADMIN_FULL` | all ACP modules |
| `m_bbdkp` | `ROLE_MOD_FULL` | add/edit raids, loot, adjustments — not pool/event schema changes |
| `u_bbdkp_view` | `ROLE_USER_*`, `GUESTS` | front-end pages (standings, history) |
| `u_bbdkp_view_others` | `ROLE_USER_*` | per-character page for other characters |

UCP page is un-gated (any logged-in user sees their own DKP) — mirrors bbAccounts' "My Wallet" pattern.

### 7.5 bbGuild log type registration

Registered via `core.user_setup` listener at extension load. IDs from issue [#2](https://github.com/avatharbe/bbDKP/issues/2):

- `DKPSYS_ADDED/UPDATED/DELETED` (1-3) — pool CRUD
- `EVENT_ADDED/UPDATED/DELETED` (4-6) — event CRUD
- `INDIVADJ_ADDED/UPDATED/DELETED` (8-10) — adjustment CRUD
- `ITEM_ADDED/UPDATED/DELETED` (11-13) — loot CRUD
- `RAID_ADDED/UPDATED/DELETED` (23-25) — raid CRUD
- `PLAYERDKP_UPDATED/DELETED` (34-35) — player-level admin actions

Decay/zerosum/sync log types (28-32) are reserved but registered only when those features land in v2.1+.

Source language keys are archived at `bbguild/contrib/archive/language/{en,de,fr,it}/dkp_common.php` — copy into `bbdkp/language/<lang>/` during scaffolding.

## 8. Files and folder layout

```
ext/avathar/bbdkp/
├── composer.json                ← name: avathar/bbdkp; requires bbguild >= 2.0.0-b1, bbaccounts >= 1.0.0
├── ext.php                      ← namespace avathar\bbdkp; is_enableable() checks both deps
├── config/
│   ├── services.yml
│   ├── tables.yml               ← table-name parameters
│   └── routing.yml
├── controller/                  ← front + ACP controllers
├── service/
│   ├── pool_manager.php
│   ├── event_manager.php
│   ├── raid_manager.php
│   ├── item_manager.php
│   ├── loot_manager.php
│   ├── adjustment_manager.php
│   ├── standings_service.php
│   └── dkp_ledger.php           ← THE bbAccounts gateway
├── event/
│   └── listener.php             ← log type registration, language load
├── acp/                         ← ACP modules + info files
├── ucp/                         ← UCP module
├── migrations/
│   └── bbdkp_install.php        ← single squashed v2.0.0 install
├── language/{en,de,fr,it}/      ← seeded from bbguild/contrib/archive/language/<lang>/dkp_common.php
├── styles/all/template/dkp/
├── adm/style/                   ← ACP templates + CSS/JS
└── contrib/
    ├── specs/                   ← design docs (this file)
    ├── plans/                   ← implementation plans
    ├── announcements/           ← forum BBCode posts
    └── archive/                 ← reference snippets from v1.4.6 if useful
```

## 9. Item seed extension point

bbDKP defines a tagged-service contract: `bbdkp.item_seed_provider`.

Any game plugin (bbguildwow, bbguildeq, …) MAY ship a service implementing this interface:

```php
namespace avathar\bbdkp\service;

interface item_seed_provider_interface
{
    /** @return string the game_id this provider seeds (e.g. 'wow', 'eq', 'eq2') */
    public function get_game_id(): string;

    /** @return iterable<array{item_name: string, item_gameid: ?string}> */
    public function get_items(): iterable;
}
```

On bbDKP enable, the migration scans the service collection for tagged providers and upserts their rows into `bb_dkp_items`. Each provider is responsible only for its own game; bbDKP itself ships no seed data.

If no game plugin ships seeds, the catalog starts empty and grows organically via ACP autocomplete + (eventual) log import.

## 10. Migrations

Single squashed install migration `migrations/bbdkp_install.php` (bbPoints v2.0 pattern):

- `effectively_installed()` gates on `$this->config['bbdkp_version'] === '2.0.0'`.
- `update_data()` creates all tables, adds permissions, registers ACP/UCP modules, sets config keys, and runs the item-seed-provider scan.
- `revert_data()` removes everything cleanly. bbAccounts entries posted before revert are NOT deleted (immutable journal); the accounts created by bbDKP are removed only if they have zero entries.
- Depends on: `\avathar\bbguild\migrations\v200b2\schema` and the bbAccounts schema migration at the v1.0.0 baseline.

No v1.x migration chain — bbDKPMOD v1.4.6 data does not need to import (D6).

## 11. Testing strategy

All tests run against a real phpBB test install with a real database — no DB mocks. Mirrors the bbAccounts and bbPoints v2.0 test patterns.

### 11.1 Service-layer integration tests (`tests/service/`)

- `dkp_ledger` posting verbs: each verb posts exactly the expected DR/CR pair, creates a `bb_dkp_ledger_link` row, does not write to bbAccounts directly.
- Pool create → confirms 5 accounts minted in bbAccounts with correct types and subledger source.
- `reverse()`: the reversal balances the original; cumulative subledger balance returns to pre-post state.
- Idempotency: editing a raid twice doesn't double-post.

### 11.2 Domain-flow tests (`tests/flow/`)

- End-to-end: create pool → event → raid → 3 attendees → save → standings show expected DKP per player.
- Add loot → buyer's wallet decreases by `value`, pool's `loot_proceeds` revenue increases by `value`.
- Bulk adjustment with mixed +/- amounts → recipients land in the correct accounts (some via `adjust_credit`, some via `adjust_debit`).
- Delete a raid (with loot) → all linked journal entries reversed; standings return to pre-raid state.
- Per-attendee `value_override` is respected — editing `raid_value` does not affect overridden attendees.

### 11.3 Permissions tests (`tests/functional/`)

- Anonymous user hitting `/guild/.../dkp/standings` without `u_bbdkp_view` → 403.
- Mod with `m_bbdkp` can add raid but not delete a pool.
- User viewing own UCP DKP page works without `u_bbdkp_view_others`; viewing another player's page requires it.

### 11.4 CI matrix

- `tests.yml` — main pushes + PRs, PHP 8.1 only.
- `tests-release.yml` — tag pushes + workflow_dispatch, full PHP 8.1–8.4 matrix.

Same structure as bbAccounts; trims free-tier minutes.

## 12. Release plan

Pre-work (bbAccounts, not bbDKP):

- bbAccounts: subledger source registry (§6.6 #1).
- bbAccounts#13: per-subledger activity API.

Both ship in a bbAccounts minor release before bbDKP implementation starts.

Then bbDKP, in order:

| Version | Scope |
|---|---|
| `2.0.0-alpha1` | scaffolding (#2), schema (#3), pool ACP (#7), event ACP (#5) |
| `2.0.0-alpha2` | raid ACP (#4) with attendance, adjustment ACP (#8) |
| `2.0.0-alpha3` | item catalog ACP, loot ACP (#6 — catalog + history, single buyer) |
| `2.0.0-beta1` | front-end standings + raid history + loot history (lean #10) |
| `2.0.0-beta2` | UCP "My DKP" page |
| `2.0.0` | stable |

Deferred to follow-up minors (separate specs):

- `2.1.0` — point decay + zero-sum DKP (#9)
- `2.2.0` — EP/GP system (#11)
- `2.3.0` — portal modules (#12)
- `2.4.0` — in-game raid log ingest (#13)

## 13. Open assumptions (verify during plan phase)

1. `bb_players.player_id` is stable and unique across rename / inactivate cycles in bbGuild.
2. ~~bbAccounts subledger source is pluggable~~ — **resolved.** bbAccounts v1.1.0-alpha ships `subledger_type='character'` + `subledger_player_id` column. See §6.6 #1.
3. bbAccounts#13 lands before bbDKP **beta2** (UCP history). Not blocking for alpha1 or beta1. See §6.6 #2.
4. `bbguild.portal.module` tag pattern exists for the deferred v2.3 work (already documented in bbGuild memory).
5. bbGuild's log registry accepts log type registration via `core.user_setup` (see [bbguild#321](https://github.com/avandenberghe/bbguild/issues/321) referenced in bbDKP#2).

## 14. Reference: bbDKPMOD v1.4.6 schema parity

For implementers comparing v2 against the legacy MOD:

| v1.4.6 table | v2.0 disposition |
|---|---|
| `bbdkp_dkpsystem` | → `bb_dkp_pools` (add `guild_id`, rename `dkpsys_*` → `pool_*`) |
| `bbdkp_events` | → `bb_dkp_events` (rename `event_dkpid` → `pool_id`, drop status/image rename — kept as-is) |
| `bbdkp_memberdkp` | **DROPPED** — replaced by bbAccounts subledger aggregation |
| `bbdkp_adjustments` | → `bb_dkp_adjustments` + `bb_dkp_adjustment_recipients` (M:N split for bulk) |
| `bbdkp_raids` | → `bb_dkp_raids` (add `guild_id`, `pool_id`, `raid_value`) |
| `bbdkp_raid_detail` | → `bb_dkp_raid_attendees` (drop bonus columns; keep `value_override`, `join_time`, `leave_time`) |
| `bbdkp_raid_items` | → `bb_dkp_items` (catalog) + `bb_dkp_loot` (history) — split per D10 |
| `bbdkp_memberguild` | → handled by bbGuild's `bb_guild` |
| `bbdkp_memberlist` | → handled by bbGuild's `bb_players` |
| `bbdkp_classes/races/factions/games/language` | → handled by bbGuild + game plugins |
| `bbdkp_member_ranks/roles/gameroles/recruit` | → handled by bbGuild |
| `bbdkp_news/welcomemsg` | → handled by bbGuild |
| `bbdkp_logs` | → handled by bbGuild's log registry |
| `bbdkp_plugins` | → not needed (phpBB extension format makes this obsolete) |

Columns explicitly dropped from v2.0 MVP (re-introduced when feature lands):

- `member_*decay`, `member_*bonus`, `item_decay`, `item_zs`, `adj_decay`, `can_decay`, `decay_time` — tied to decay / zerosum / time-bonus (v2.1)
- EP/GP `base_gp`, `pool_type` — v2.2

## 15. Next steps

1. **User review of this spec.** Confirm or request changes.
2. **File bbAccounts prerequisite tickets** (§6.6) and confirm landing schedule with bbAccounts owners.
3. **Invoke `writing-plans`** to produce the implementation plan at `contrib/plans/2026-05-23-bbdkp-v2-plan.md`.
4. **Begin alpha1 scaffolding** once the implementation plan is approved.
