# bbDKP 2.0.0-alpha2 — design spec

**Date:** 2026-05-24
**Author:** Sajaki
**Parent spec:** `contrib/specs/2026-05-23-bbdkp-v2-design.md` (full v2.0 design)
**Predecessor:** `contrib/plans/2026-05-23-bbdkp-v2-alpha1-plan.md` (alpha1 implementation)

## 1. Summary

alpha2 adds the first two posting surfaces to bbDKP v2 — **raid CRUD with attendance** and **adjustment CRUD with bulk-add** — plus log-type registration so admin actions appear in bbGuild's moderation log. No new tables (alpha1 created them); no new permissions; no new front-end routes.

Two new services (`raid_manager`, `adjustment_manager`) and two new ACP modules (`acp_raid_module`, `acp_adjustment_module`) compose alpha1's `dkp_ledger` primitives. One new event subscriber (`log_listener`) registers all bbDKP log types via `core.user_setup`. Log calls (`$phpbb_log->add()`) are added inline to every CRUD method including the alpha1 pool/event paths (backfill).

## 2. Goals and non-goals

### Goals
- ACP: admins can create / list / edit / delete raids with attendance, and create / list / edit / delete adjustments (single or bulk-recipient).
- Editing a raid's `raid_value` correctly reverses prior ledger postings and reposts at the new amount, **per spec §6.3**. Editing a per-attendee `value_override` does the same for that one attendee.
- Deleting a raid reverses every attendee ledger entry before removing metadata. Deleting an adjustment reverses every recipient entry.
- Every CRUD path writes to phpBB's `phpbb_log` via `$phpbb_log->add()`, using bbDKP log type names (DKPSYS_/EVENT_/RAID_/INDIVADJ_/PLAYERDKP_) registered via a new `core.user_setup` listener.
- Manager services take plain arrays for attendees / recipients — no `$request` coupling — so they remain callable from a future v2.4 ingest endpoint.

### Non-goals
- **No automated tests.** Local phpBB install lacks phpunit + test framework (per memory `feedback_phpbb_local_install_quirks.md`). Manual smoke checklist substitutes. Tests get backfilled (alpha1 + alpha2) once CI or local framework is restored.
- **No bulk-import / log-parse UI.** Multi-select character picker only. The Lua WoW mod (v2.4 per parent spec §12) is the planned bulk path; see [[bbdkp-lua-wow-mod]].
- **No loot, items, item catalog.** alpha3 territory.
- **No front-end pages.** beta1 territory.
- **No UCP.** beta2 territory.
- **No schema migrations.** alpha1 created `bb_dkp_raids`, `bb_dkp_raid_attendees`, `bb_dkp_adjustments`, `bb_dkp_adjustment_recipients`, `bb_dkp_ledger_link` with all required columns including audit (`added_by`, `added_at`, `updated_by`, `updated_at`).
- **No new permissions.** `a_bbdkp` / `m_bbdkp` from alpha1 already gate everything; `m_bbdkp` is the relevant grant for raid + adjustment actions per parent spec §7.4.

## 3. Decisions log

| ID | Decision | Why |
|---|---|---|
| A1 | Multi-select character picker for attendance (no paste-and-parse) | Lua WoW mod will own bulk-import path in v2.4. ACP UX stays simple. |
| A2 | Multi-select + single signed amount for bulk adjustments | Covers the dominant "give 50 DKP to everyone who attended" use case; per-recipient grid deferred unless requested. |
| A3 | Edit-raid-value cascades reverse+repost for attendees with `value_override IS NULL` only | Spec §6.3. Improves on legacy bbDKPMOD which rewrote `raid_detail.raid_value` in place and left balances stale (see §6 below). |
| A4 | Hard-delete on raid + adjustment (with reverse), not soft-disable | Audit history lives in `bb_dkp_ledger_link` regardless. Metadata deletion keeps the ACP list clean. |
| A5 | Log type registration ships in alpha2 + backfills alpha1 pool/event log calls | Includes-in-one-shot avoids leaving alpha1 actions unaudited. |
| A6 | Manager APIs take plain arrays for attendees/recipients | Decouples from `$request`. Future v2.4 ingest controller calls the same API path. |
| A7 | No alpha2 schema migration | alpha1 schema already covers everything; verified 2026-05-24. |
| A8 | Bulk-add adjustment generates one `group_key` (UUID-style) shared across all recipient rows | Matches parent spec §5.7 + legacy bbDKPMOD's `adjustment_group_key` semantics; enables future "edit-as-batch" UX without schema changes. |
| A9 | Idempotency for future v2.4 ingest is out of scope here, but noted in §10 | Raidtracker MOD had no idempotency guard; the v2.4 spec must add one. Worth flagging now. |

## 4. Architecture

```
ACP form submit
       │
       ▼
 acp_raid_module / acp_adjustment_module          ── catches typed exceptions, calls trigger_error()
       │
       ▼
 raid_manager / adjustment_manager                ── validates, opens transaction, writes metadata
       │                                             rows, calls dkp_ledger per attendee/recipient,
       ▼                                             writes $phpbb_log->add(), commits or rolls back
 dkp_ledger (alpha1)                              ── single posting surface to bbAccounts; all
       │                                             reverse() / post_raid_award() / post_adjustment()
       ▼                                             primitives already exist
 bbAccounts ledger (v1.1.0-alpha)                 ── canonical journal_entries + journal_lines
```

No new layer. alpha2 composes alpha1 primitives.

**Forward-compat seam:** the v2.4 Lua-mod ingest controller will sit at the same ACP-module layer (different entry point — an HTTP POST endpoint instead of an admin form) and call the identical `raid_manager->create_raid(...)` shape. Idempotency, auth, and payload-shape concerns belong to that controller, not to `raid_manager`.

## 5. Data model

### 5.1 No new tables

All required tables exist from alpha1:
- `bb_dkp_raids` — raid metadata
- `bb_dkp_raid_attendees` — per-player participation (surrogate `attendee_id` PK)
- `bb_dkp_adjustments` — adjustment header (one row per admin action, includes `group_key`)
- `bb_dkp_adjustment_recipients` — adjustment body (one row per recipient, surrogate `recipient_id` PK)
- `bb_dkp_ledger_link` — bridge to bbAccounts (`entity_type` ∈ {`raid_attendee`, `adjustment_recipient`, `loot`}; `entity_id` is the surrogate PK)

### 5.2 Audit columns

All four metadata tables carry `added_by`, `added_at`, `updated_by`, `updated_at`. Managers populate on insert/update.

### 5.3 Column-use clarifications (no schema changes)

- `bb_dkp_raid_attendees.join_time` / `leave_time`: nullable; **alpha2 always inserts NULL** (treated as "full raid"). Reserved for future time-bonus feature (v2.1). The Lua mod (v2.4) will populate these from raw join logs.
- `bb_dkp_raid_attendees.value_override`: nullable; NULL means "use raid.raid_value". Used by the cascade logic in §6.
- `bb_dkp_adjustments.group_key`: VARCHAR(64). alpha2 generates via `unique_id()` (phpBB's utility) per adjustment, shared across all recipients of that adjustment.
- `bb_dkp_ledger_link.reversal_of`: NULL for forward posts; link_id of the original entry when set. Used to find "live" forward posts during cascade (filter `reversal_of IS NULL`).

## 6. Services

### 6.1 `raid_manager`

```php
namespace avathar\bbdkp\service;

interface raid_manager_interface
{
    /**
     * @param int    $guild_id
     * @param int    $pool_id
     * @param int    $event_id
     * @param int    $raid_start   unix seconds
     * @param int    $raid_end     unix seconds, 0 = point-in-time
     * @param string $raid_value   DECIMAL string
     * @param string $raid_note    BBCode
     * @param array  $attendees    each: ['player_id' => int, 'value_override' => ?string]
     * @return int   raid_id
     */
    public function create_raid(int $guild_id, int $pool_id, int $event_id, int $raid_start, int $raid_end, string $raid_value, string $raid_note, array $attendees): int;

    /**
     * Editable fields: raid_end, raid_note, raid_value, event_id.
     * raid_start, pool_id, guild_id are NOT editable — they're the raid's identity.
     * Admins fix a wrongly-dated/wrongly-scoped raid by delete + re-add.
     * raid_value change cascades reverse+repost for attendees with value_override IS NULL.
     * event_id change is metadata-only.
     */
    public function update_raid(int $raid_id, array $fields): void;

    /** Editable: value_override (null or DECIMAL string). Cascades reverse+repost. */
    public function update_attendee(int $attendee_id, array $fields): void;

    /** @param array $attendees same shape as create_raid */
    public function add_attendees(int $raid_id, array $attendees): void;

    /** @param int[] $player_ids */
    public function remove_attendees(int $raid_id, array $player_ids): void;

    /** Reverses all attendee ledger entries, then deletes raid + attendees metadata. */
    public function delete_raid(int $raid_id): void;

    /** Lists raids with optional pool / event filters. Includes attendee count, not full list. */
    public function list_raids(int $pool_id = 0, int $event_id = 0, int $limit = 25, int $offset = 0): array;

    /** Returns raid row with `attendees` sub-array. */
    public function get_raid(int $raid_id): ?array;
}
```

### 6.2 `adjustment_manager`

```php
namespace avathar\bbdkp\service;

interface adjustment_manager_interface
{
    /**
     * @param int    $pool_id
     * @param string $reason
     * @param array  $recipients   each: ['player_id' => int, 'amount' => string (signed)]
     * @return int   adjustment_id
     */
    public function create_adjustment(int $pool_id, string $reason, array $recipients): int;

    /**
     * Editable fields: reason only.
     * pool_id, adjustment_date are NOT editable — re-creating preserves audit chronology.
     * Amount edits go via remove_recipients + add_recipients (reverse + repost).
     */
    public function update_adjustment(int $adjustment_id, array $fields): void;

    public function add_recipients(int $adjustment_id, array $recipients): void;

    /** @param int[] $recipient_ids */
    public function remove_recipients(int $adjustment_id, array $recipient_ids): void;

    /** Reverses all recipient ledger entries, then deletes adjustment + recipients metadata. */
    public function delete_adjustment(int $adjustment_id): void;

    public function list_adjustments(int $pool_id = 0, int $limit = 25, int $offset = 0): array;

    /** Returns adjustment row with `recipients` sub-array. */
    public function get_adjustment(int $adjustment_id): ?array;
}
```

### 6.3 `log_listener` (event subscriber)

`event/log_listener.php` subscribes to `core.user_setup` and loads `language/<lang>/logs.php` so phpBB's mod log renders bbDKP log type names. The language file ships:

```php
$lang = array_merge($lang, [
    'LOG_DKPSYS_ADDED'      => 'Added DKP pool « %s »',
    'LOG_DKPSYS_UPDATED'    => 'Updated DKP pool « %s »',
    'LOG_DKPSYS_DELETED'    => 'Deleted DKP pool « %s »',
    'LOG_EVENT_ADDED'       => 'Added DKP event « %s »',
    'LOG_EVENT_UPDATED'     => 'Updated DKP event « %s »',
    'LOG_EVENT_DELETED'     => 'Deleted DKP event « %s »',
    'LOG_RAID_ADDED'        => 'Added raid « %s » with %d attendees',
    'LOG_RAID_UPDATED'      => 'Updated raid #%d',
    'LOG_RAID_DELETED'      => 'Deleted raid #%d',
    'LOG_INDIVADJ_ADDED'    => 'Added adjustment « %s » for %d recipients',
    'LOG_INDIVADJ_UPDATED'  => 'Updated adjustment #%d',
    'LOG_INDIVADJ_DELETED'  => 'Deleted adjustment #%d',
    'LOG_PLAYERDKP_UPDATED' => 'Updated DKP for player #%d (raid #%d, %s)',
]);
```

(Names match parent spec §7.5 IDs 1-13, 23-25, 34. Decay/zerosum/sync log types deferred to v2.1+.)

### 6.4 Inline log call sites

Added in this release across all four manager services:

| Service | Method | Log type | Action arg |
|---|---|---|---|
| pool_manager | create_pool | LOG_DKPSYS_ADDED | pool_name |
| pool_manager | update_pool | LOG_DKPSYS_UPDATED | pool_name |
| pool_manager | delete_pool | LOG_DKPSYS_DELETED | pool_name |
| event_manager | create_event | LOG_EVENT_ADDED | event_name |
| event_manager | update_event | LOG_EVENT_UPDATED | event_name |
| event_manager | delete_event | LOG_EVENT_DELETED | event_name |
| raid_manager | create_raid | LOG_RAID_ADDED | event_name, attendee count |
| raid_manager | update_raid | LOG_RAID_UPDATED | raid_id |
| raid_manager | update_attendee | LOG_PLAYERDKP_UPDATED | player_id, raid_id, diff |
| raid_manager | delete_raid | LOG_RAID_DELETED | raid_id |
| adjustment_manager | create_adjustment | LOG_INDIVADJ_ADDED | reason, recipient count |
| adjustment_manager | update_adjustment | LOG_INDIVADJ_UPDATED | adjustment_id |
| adjustment_manager | delete_adjustment | LOG_INDIVADJ_DELETED | adjustment_id |

## 7. Data flow (key paths)

### 7.1 Create raid with N attendees
1. ACP form submit → `acp_raid_module` constructs `attendees` array
2. `raid_manager->create_raid(...)`:
   1. Validate (pool exists + enabled, event exists + belongs to pool, ≥1 attendee, no duplicate player_id, every player_id resolves to bb_players row in guild)
   2. `sql_transaction('begin')`
   3. INSERT `bb_dkp_raids` → `raid_id`
   4. For each attendee:
      - INSERT `bb_dkp_raid_attendees` → `attendee_id`
      - `effective_amount = value_override ?? raid_value`
      - `dkp_ledger->post_raid_award(attendee_id, pool_id, player_id, effective_amount)`
   5. `$phpbb_log->add('admin', $user_id, $ip, 'LOG_RAID_ADDED', time(), [$event_name, $attendee_count])`
   6. `sql_transaction('commit')`
3. ACP shows success message; redirects to list.

### 7.2 Edit `raid_value` (cascade)
1. `raid_manager->update_raid(raid_id, ['raid_value' => $new])`:
   1. Load raid, verify `$new` differs from current
   2. `sql_transaction('begin')`
   3. UPDATE `bb_dkp_raids` SET raid_value
   4. SELECT attendees WHERE raid_id=? AND value_override IS NULL
   5. For each affected attendee:
      - SELECT live link: `WHERE entity_type='raid_attendee' AND entity_id=$attendee_id AND reversal_of IS NULL`
      - `dkp_ledger->reverse($link_id)`
      - `dkp_ledger->post_raid_award($attendee_id, $pool_id, $player_id, $new)`
   6. `$phpbb_log->add('LOG_RAID_UPDATED', $raid_id)`
   7. `sql_transaction('commit')`

### 7.3 Edit per-attendee `value_override`
Same shape as 7.2 but scoped to one attendee. Logs `LOG_PLAYERDKP_UPDATED` with diff.

### 7.4 Delete raid
1. `raid_manager->delete_raid(raid_id)`:
   1. `sql_transaction('begin')`
   2. SELECT live link_ids for this raid's attendees
   3. For each: `dkp_ledger->reverse($link_id)` (link rows stay — audit)
   4. DELETE `bb_dkp_raid_attendees` WHERE raid_id=?
   5. DELETE `bb_dkp_raids` WHERE raid_id=?
   6. `$phpbb_log->add('LOG_RAID_DELETED', $raid_id)`
   7. `sql_transaction('commit')`

### 7.5 Create bulk adjustment
1. ACP form submit → multi-select players + signed amount + reason
2. `adjustment_manager->create_adjustment(pool_id, reason, recipients)`:
   1. Validate (pool exists + enabled, ≥1 recipient, no duplicate player_id, every amount ≠ 0, every player_id resolves)
   2. `sql_transaction('begin')`
   3. `group_key = unique_id()`
   4. INSERT `bb_dkp_adjustments` → `adjustment_id`
   5. For each recipient:
      - INSERT `bb_dkp_adjustment_recipients` (signed amount) → `recipient_id`
      - `dkp_ledger->post_adjustment($recipient_id, $pool_id, $player_id, $amount)` (ledger picks DR/CR from sign)
   6. `$phpbb_log->add('LOG_INDIVADJ_ADDED', [$reason, $recipient_count])`
   7. `sql_transaction('commit')`

### 7.6 Delete adjustment
Mirror of 7.4 over recipients.

## 8. Module and route surface

### 8.1 ACP modules (added)

```
ACP_BBGUILD_MAINPAGE
└── ACP_DKP                          (alpha1)
    ├── ACP_DKP_POOLS                (alpha1)
    ├── ACP_DKP_EVENTS               (alpha1)
    ├── ACP_DKP_RAIDS                (NEW — alpha2)
    └── ACP_DKP_ADJUSTMENTS          (NEW — alpha2)
```

Registered via migration `migrations/v200a2/bbdkp_v2_alpha2.php`:
- `module.add` for `ACP_DKP_RAIDS` (parent=`ACP_DKP`, module_class=`acp`, module_basename=`\avathar\bbdkp\acp\raid_module`)
- `module.add` for `ACP_DKP_ADJUSTMENTS` (same parent, basename `\avathar\bbdkp\acp\adjustment_module`)

### 8.2 ACP module file pattern (each)

```
acp/
  acp_raid_info.php          — module info (display name, available modes: list/add/edit)
  acp_raid_module.php        — main(): switch on $mode, call manager, render template
  acp_adjustment_info.php
  acp_adjustment_module.php
adm/style/
  acp_bbdkp_raid.html        — list + add/edit form (multi-select via existing phpBB user_search shape)
  acp_bbdkp_adjustment.html  — list + add/edit form (multi-select + signed amount + reason)
language/en/
  info_acp_raid.php
  info_acp_adjustment.php
  logs.php                   — log type translations (see §6.3)
```

### 8.3 Routes

None. Front-end routes are beta1 territory.

### 8.4 Permissions

No new permissions. `a_bbdkp` (admin) and `m_bbdkp` (mod) from alpha1 gate the new ACP modules per parent spec §7.4.

## 9. Files and folder layout

```
ext/avathar/bbdkp/
├── service/
│   ├── raid_manager_interface.php          NEW
│   ├── raid_manager.php                    NEW
│   ├── adjustment_manager_interface.php    NEW
│   └── adjustment_manager.php              NEW
├── event/
│   └── log_listener.php                    NEW
├── exception/
│   ├── raid_state_exception.php            NEW
│   └── adjustment_state_exception.php      NEW
├── acp/
│   ├── acp_raid_info.php                   NEW
│   ├── acp_raid_module.php                 NEW
│   ├── acp_adjustment_info.php             NEW
│   └── acp_adjustment_module.php           NEW
├── adm/style/
│   ├── acp_bbdkp_raid.html                 NEW
│   └── acp_bbdkp_adjustment.html           NEW
├── language/en/
│   ├── info_acp_raid.php                   NEW
│   ├── info_acp_adjustment.php             NEW
│   └── logs.php                            NEW
├── migrations/
│   └── v200a2/
│       └── bbdkp_v2_alpha2.php             NEW — module.add for raid + adjustment ACPs
└── config/
    └── services.yml                        EDIT — register raid_manager, adjustment_manager, log_listener
```

Modified alpha1 files (for log call backfill):
- `service/pool_manager.php` — add `$phpbb_log->add()` to create/update/delete
- `service/event_manager.php` — same
- `config/services.yml` — inject `phpbb_log` into both managers

Constructor changes: `pool_manager` + `event_manager` gain `\phpbb\log\log_interface` and `\phpbb\user` dependencies.

## 10. Error handling

Two new typed exceptions follow the alpha1 pattern (sit alongside `bbdkp\exception\runtime_exception` in the same namespace, extend `\RuntimeException` directly):

```php
namespace avathar\bbdkp\exception;

class raid_state_exception extends \RuntimeException {}
class adjustment_state_exception extends \RuntimeException {}
```

Exception messages are language keys (caller's `trigger_error()` looks them up).

| Source | Condition | Exception → message key |
|---|---|---|
| create_raid | empty attendees | `raid_state_exception('RAID_NO_ATTENDEES')` |
| create_raid | duplicate player_id | `raid_state_exception('RAID_DUPLICATE_ATTENDEE')` |
| create_raid | pool disabled | `raid_state_exception('RAID_POOL_DISABLED')` |
| create_raid | event doesn't belong to pool | `raid_state_exception('RAID_EVENT_MISMATCH')` |
| create_raid | unknown player_id | `raid_state_exception('RAID_UNKNOWN_PLAYER')` |
| update_raid | raid_id not found | `runtime_exception('RAID_NOT_FOUND')` |
| update_raid | cascade hits missing live link | `runtime_exception('LEDGER_LINK_MISSING')` — DB inconsistency, not user error |
| create_adjustment | empty recipients | `adjustment_state_exception('ADJ_NO_RECIPIENTS')` |
| create_adjustment | recipient amount = 0 | `adjustment_state_exception('ADJ_ZERO_AMOUNT')` |
| create_adjustment | duplicate player_id | `adjustment_state_exception('ADJ_DUPLICATE_RECIPIENT')` |
| create_adjustment | unknown player_id | `adjustment_state_exception('ADJ_UNKNOWN_PLAYER')` |
| create_adjustment | pool disabled | `adjustment_state_exception('ADJ_POOL_DISABLED')` |
| dkp_ledger->reverse | link already reversed | `runtime_exception('LEDGER_ALREADY_REVERSED')` (alpha1) |
| bbAccounts create_entry | unbalanced / unknown account | propagates; rolls back transaction |

ACP module `try/catch` pattern (matches alpha1):
```php
try {
    $this->raid_manager->create_raid(...);
    trigger_error($user->lang['ACP_DKP_RAID_ADDED'] . adm_back_link($this->u_action));
} catch (raid_state_exception $e) {
    trigger_error($user->lang[$e->getMessage()] . adm_back_link($this->u_action), E_USER_WARNING);
} catch (\Exception $e) {
    trigger_error($user->lang[$e->getMessage()] . adm_back_link($this->u_action), E_USER_WARNING);
}
```

Transaction discipline: every multi-row mutation in a manager wraps `$db->sql_transaction('begin')` + `commit`/`rollback`. ACP module never sees partial state.

Validation ordering (raid form): all pure validations run **before** the transaction opens. Transaction only wraps mutations.

## 11. Testing strategy

**Deferred.** Local phpBB lacks phpunit + working test framework. Manual smoke checklist for this release:

- Add raid with 1 attendee → standings shows +amount
- Add raid with 3 attendees (one with value_override) → check each balance is correct
- Edit raid_value from 50 → 75 → attendees with NULL override gain +25; attendee with override unchanged
- Edit one attendee's value_override → only that player's balance moves
- Delete raid → all attendee balances revert
- Bulk adjustment +50 to 5 chars → 5 recipient rows all sharing the adjustment header's `group_key`, 5 bbAccounts journal entries, 5 balance updates
- Bulk adjustment -50 → opposite direction, same group_key shape
- Delete adjustment → reverses all 5
- Disable a pool, try to add a raid against it → blocked with `RAID_POOL_DISABLED` (proper error, not blank page)
- Try to add raid with two of the same player_id → blocked with `RAID_DUPLICATE_ATTENDEE`
- Try to add adjustment with one zero-amount recipient → blocked with `ADJ_ZERO_AMOUNT`
- Confirm log entries appear in ACP → System → Admin log for every action above

Tests get backfilled (alpha1 + alpha2) in the alpha that ships after the test framework is restored.

## 12. Forward-compat with v2.4 Lua-mod ingest

The Raidtracker MOD (legacy) used three temp tables — RAIDINFO + PLAYERINFO + JOININFO — to stage parsed XML between "uploaded" and "committed". The v2.4 Lua mod will replace both that pipeline and the WoW addon.

The commit shape collapses to: `raid_manager->create_raid(pool, event, start, end, note, attendees[])` plus a future `loot_manager->add_loot(raid_id, items[])`. Exactly what `raid_manager->create_raid()` is being designed to take in this release.

**Design constraint enforced here:** manager methods take plain arrays and primitives — no `$request`, no form objects, no rendered HTML strings, no session coupling. An ingest controller (HTTP POST endpoint, JSON body, API-token auth) can construct the same array shape from a parsed payload and call the identical method.

**Idempotency gap noted:** Raidtracker had none — re-uploading the same XML duplicated raids. The v2.4 spec must add a content-hash or `(start_time, event_id, attendee_set)` uniqueness guard at the controller layer. **Not alpha2's concern.** Recorded here so it's not forgotten.

## 13. Improvements over legacy bbDKPMOD

- **Edit raid_value now reverses+reposts.** Legacy `RaidController.update_raid()` rewrote `bbdkp_raid_detail.raid_value` in place; member balances stayed stale until the next decay run (which most installs never had configured). Verified via audit 2026-05-24.
- **Logs go through phpBB's `phpbb_log` + bbGuild's log registry.** Legacy maintained its own `bbdkp_logs` table; we drop the redundancy.
- **Adjustments split adjustment ↔ recipient (one-to-many).** Legacy `bbdkp_adjustments` was one-row-per-recipient with a `group_key` to fake batching. Our schema makes the header/body split first-class, enabling future "edit-as-batch" UX without schema churn.
- **Audit columns are explicit.** alpha1 added `added_by` / `added_at` / `updated_by` / `updated_at` to every metadata table; managers populate consistently.

## 14. Open assumptions (verify during plan phase)

1. phpBB's `\phpbb\log\log_interface` is the correct DI service id (`log`) in phpBB 3.3.x. Verify in `phpBB/config/default/container/services.yml`.
2. `unique_id()` is available globally in phpBB 3.3.x for `group_key` generation. (It is in 3.3; verify import path.)
3. phpBB 3.3 ACP user_search widget pattern works inside our `acp_*_module.php` form for the multi-select — or whether we need to roll our own player-picker UI against `bb_players`. **Most likely we roll our own** since the picker needs to scope to current guild's `bb_players` rows, not `phpbb_users`. Verify during plan phase.
4. `bb_players` has an indexed `(guild_id, player_status)` so the picker query is cheap. Verify alpha1 migration.

## 15. Release plan

| Phase | Scope | Commit |
|---|---|---|
| 1 | log_listener + logs.php + backfill pool/event log calls | `feat: log type registration + alpha1 log backfill` |
| 2 | raid_manager interface + impl, raid_state_exception | `feat: raid_manager service` |
| 3 | acp_raid_module + acp_raid_info + adm template + info_acp_raid.php | `feat: raid ACP module — full CRUD` |
| 4 | adjustment_manager interface + impl, adjustment_state_exception | `feat: adjustment_manager service` |
| 5 | acp_adjustment_module + acp_adjustment_info + adm template + info_acp_adjustment.php | `feat: adjustment ACP module — full CRUD` |
| 6 | migration v200a2 (module.add for raid + adjustment ACPs) | `feat: alpha2 install migration` |
| 7 | composer.json version bump → 2.0.0-alpha2; CHANGELOG entry; sync + tag | `chore: release 2.0.0-alpha2` |

Each phase is independently shippable and testable manually.

## 16. Next steps

1. **User review of this spec.** Confirm or request changes.
2. **Invoke `writing-plans`** to produce the implementation plan at `contrib/plans/2026-05-24-bbdkp-v2-alpha2-plan.md`.
3. **Execute the plan.**
