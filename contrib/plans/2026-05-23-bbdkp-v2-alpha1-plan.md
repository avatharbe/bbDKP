# bbDKP v2.0.0-alpha1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `avathar/bbdkp` v2.0.0-alpha1 — a phpBB 3.3 extension that installs cleanly with all v2.0 schema in place, registers permissions and ACP modules, posts to bbAccounts via a single ledger facade, and exposes working Pool and Event ACP screens.

**Architecture:** bbAccounts is the canonical store for all DKP transactions. bbDKP owns only domain metadata tables (pools, events, raids, attendees, items, loot, adjustments, ledger-link) and a single `dkp_ledger` service that gates every write to bbAccounts. Pools auto-mint five chart-of-accounts entries each. Subledger source is `bb_players.player_id`. Hard dependencies on bbGuild ≥ 2.0.0-b1 and bbAccounts ≥ 1.0.0.

**Tech Stack:** phpBB 3.3, PHP 8.1+, Symfony DI 3.x, MySQL/MariaDB primary (PostgreSQL/MSSQL secondary via DBAL), PHPUnit via phpBB's test framework.

**Spec:** `contrib/specs/2026-05-23-bbdkp-v2-design.md`

**Git workflow for this plan:** bbDKP is being scaffolded from scratch and has no upstream history yet. For alpha1 we treat the **working copy** (`/Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/`) as the primary git workspace — `git init` happens in Task 3 and all task-level commits land there. At the very end (Task 39) we rsync the working copy into the dev-repo at `/Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP/` and push the first content + tag. From alpha2 onward the standard "edit in working copy → rsync to dev-repo → commit from dev-repo" workflow from memory `feedback_rt_working_copy.md` applies.

**Out of scope (later alphas/betas):**
- Raid + attendee ACP (alpha2)
- Adjustment ACP (alpha2)
- Item catalog + loot ACP (alpha3)
- Front-end pages (beta1)
- UCP "My DKP" (beta2)

---

## File structure (created in this plan)

```
ext/avathar/bbdkp/
├── composer.json
├── ext.php
├── config/
│   ├── services.yml
│   ├── tables.yml
│   └── routing.yml             ← empty in alpha1
├── migrations/
│   └── bbdkp_install.php       ← single squashed install
├── service/
│   ├── dkp_ledger_interface.php
│   ├── dkp_ledger.php
│   ├── pool_manager_interface.php
│   ├── pool_manager.php
│   ├── event_manager_interface.php
│   └── event_manager.php
├── acp/
│   ├── acp_pool_module.php
│   ├── acp_pool_info.php
│   ├── acp_event_module.php
│   └── acp_event_info.php
├── adm/style/
│   ├── acp_bbdkp_pool.html
│   └── acp_bbdkp_event.html
├── language/en/
│   ├── common.php
│   ├── permissions_bbdkp.php
│   ├── info_acp_pool.php
│   ├── info_acp_event.php
│   └── acp/
│       ├── pool.php
│       └── event.php
├── tests/
│   ├── service/
│   │   ├── dkp_ledger_test.php
│   │   ├── pool_manager_test.php
│   │   └── event_manager_test.php
│   ├── functional/
│   │   └── install_uninstall_test.php
│   └── fixtures/
│       └── bbdkp_basic.xml
├── CHANGELOG.md
├── README.md
└── license.txt
```

---

## Phase 0 — Prerequisites verification

### Task 1: Verify bbGuild and bbAccounts are installed in the test env

**Files:** none (verification only)

- [ ] **Step 1: Check both extensions are present and enabled**

Run:
```bash
ls /Users/Andreas/Sites/avathar/forum/ext/avathar/bbguild
ls /Users/Andreas/Sites/avathar/forum/ext/avathar/bbaccounts
```
Expected: both directories exist.

- [ ] **Step 2: Confirm bbGuild version ≥ 2.0.0-b1**

Run:
```bash
grep '"version"' /Users/Andreas/Sites/avathar/forum/ext/avathar/bbguild/composer.json
```
Expected: `"version": "2.0.0-b2"` or higher.

- [ ] **Step 3: Confirm bbAccounts version ≥ 1.0.0-alpha**

Run:
```bash
grep '"version"' /Users/Andreas/Sites/avathar/forum/ext/avathar/bbaccounts/composer.json
```
Expected: `"version": "1.0.0-alpha"` or higher.

- [ ] **Step 4: Confirm bbAccounts character subledger support is present (v1.1.0-alpha)**

This is the gating prerequisite from spec §6.6 #1 — DELIVERED in bbAccounts v1.1.0-alpha.

Run:
```bash
grep -n "'character'" /Users/Andreas/Sites/avathar/forum/ext/avathar/bbaccounts/service/ledger.php | head
```
Expected: `VALID_SUBLEDGER_TYPES = ['', 'customer', 'supplier', 'character']` matched.

Run:
```bash
grep -rn "subledger_player_id" /Users/Andreas/Sites/avathar/forum/ext/avathar/bbaccounts/migrations/ | head
```
Expected: at least one match in `v1_1_0_character_subledger.php`. If empty: STOP — bbAccounts hasn't been upgraded yet, run the bbAccounts plan first.

Run:
```bash
grep -n "anonymize_player_subledger\|get_subledger_account_balances_by_character\|get_subledger_balance_by_character" /Users/Andreas/Sites/avathar/forum/ext/avathar/bbaccounts/service/ledger.php
```
Expected: all three method definitions matched.

- [ ] **Step 5: Confirm bbAccounts per-subledger activity API status**

Spec §6.6 #2 — bbAccounts#13 (NOT required for alpha1).

Run:
```bash
grep -rE "get_subledger_activity|get_user_journal_activity" /Users/Andreas/Sites/avathar/forum/ext/avathar/bbaccounts/service/ 2>/dev/null | head
```
Expected: empty (API not yet delivered). This is fine for alpha1 — the activity API is only needed for bbDKP beta2 UCP work. Note the gap; do not block.

### Task 2: Verify bb_players.player_id exists in bbGuild schema

**Files:** none

- [ ] **Step 1: Locate bbGuild's players table definition**

Run:
```bash
grep -rE "bb_players|players_table" /Users/Andreas/Sites/avathar/forum/ext/avathar/bbguild/migrations/ | head
```
Expected: at least one match referencing `bb_players` table creation with `player_id` PK column.

- [ ] **Step 2: Confirm player_id type matches our spec assumption (UINT)**

Run:
```bash
grep -A 5 "bb_players" /Users/Andreas/Sites/avathar/forum/ext/avathar/bbguild/migrations/basics/*.php 2>/dev/null | grep -E "player_id|UINT" | head
```
Expected: `player_id => array('UINT', NULL, 'auto_increment')` or equivalent.

---

## Phase 1 — Extension skeleton

### Task 3: Create composer.json

**Files:**
- Create: `composer.json`

- [ ] **Step 1: Write composer.json**

```json
{
    "name": "avathar/bbdkp",
    "type": "phpbb-extension",
    "description": "DKP (Dragon Kill Points) extension for phpBB 3.3, integrating with bbGuild and bbAccounts",
    "homepage": "https://github.com/avatharbe/bbDKP",
    "version": "2.0.0-alpha1",
    "time": "2026-05-23",
    "license": "GPL-2.0-only",
    "authors": [
        {
            "name": "Andreas Vandenberghe",
            "email": "andreasvdberghe@gmail.com",
            "role": "Lead Developer"
        }
    ],
    "require": {
        "php": ">=8.1",
        "composer/installers": "~2.0"
    },
    "extra": {
        "display-name": "bbDKP",
        "soft-require": {
            "phpbb/phpbb": ">=3.3.11,<4.0.0@dev"
        }
    }
}
```

- [ ] **Step 2: Verify file syntax**

Run:
```bash
php -r 'json_decode(file_get_contents("/Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/composer.json")); echo json_last_error_msg();'
```
Expected: `No error`.

- [ ] **Step 3: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp init
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add composer.json
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "chore: add composer.json"
```

NOTE: Working copy is independent of the git repo at `/Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP`. Per established workflow (memory `feedback_rt_working_copy.md`), edit in the working copy and rsync to repo for commits. Tasks below assume edits land in the working copy; the rsync+commit cycle is left to the engineer once a logical chunk is ready.

### Task 4: Create ext.php with is_enableable() dependency check

**Files:**
- Create: `ext.php`

- [ ] **Step 1: Write ext.php**

```php
<?php
/**
 * bbDKP extension entrypoint.
 *
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace avathar\bbdkp;

class ext extends \phpbb\extension\base
{
    public function is_enableable()
    {
        // hard deps per spec §3 D7
        $required = [
            'avathar/bbguild' => '2.0.0-b1',
            'avathar/bbaccounts' => '1.0.0',
        ];

        $manager = $this->container->get('ext.manager');

        foreach ($required as $ext_name => $min_version)
        {
            if (!$manager->is_enabled($ext_name))
            {
                return false;
            }

            $md = $manager->create_extension_metadata_manager($ext_name);
            $meta = $md->get_metadata();
            $version = $meta['version'] ?? '0';

            if (version_compare($version, $min_version, '<'))
            {
                return false;
            }
        }

        return true;
    }
}
```

- [ ] **Step 2: Lint**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/ext.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add ext.php
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: add ext.php with hard dependency check"
```

### Task 5: Create config/tables.yml

**Files:**
- Create: `config/tables.yml`

- [ ] **Step 1: Write tables.yml with parameters for every bbDKP table**

```yaml
parameters:
    tables.bbdkp_pools: '%core.table_prefix%bb_dkp_pools'
    tables.bbdkp_events: '%core.table_prefix%bb_dkp_events'
    tables.bbdkp_raids: '%core.table_prefix%bb_dkp_raids'
    tables.bbdkp_raid_attendees: '%core.table_prefix%bb_dkp_raid_attendees'
    tables.bbdkp_items: '%core.table_prefix%bb_dkp_items'
    tables.bbdkp_loot: '%core.table_prefix%bb_dkp_loot'
    tables.bbdkp_adjustments: '%core.table_prefix%bb_dkp_adjustments'
    tables.bbdkp_adjustment_recipients: '%core.table_prefix%bb_dkp_adjustment_recipients'
    tables.bbdkp_ledger_link: '%core.table_prefix%bb_dkp_ledger_link'
```

- [ ] **Step 2: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add config/tables.yml
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: add table-name DI parameters"
```

### Task 6: Create config/services.yml stub

**Files:**
- Create: `config/services.yml`

- [ ] **Step 1: Write empty services.yml ready to receive service definitions**

```yaml
imports:
    - { resource: tables.yml }

services:
    _defaults:
        autowire: false
        public: false
```

- [ ] **Step 2: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add config/services.yml
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: add services.yml stub"
```

### Task 7: Create config/routing.yml stub

**Files:**
- Create: `config/routing.yml`

- [ ] **Step 1: Write empty routing.yml (no front routes in alpha1)**

```yaml
# Front-end routes will be added in beta1.
# Alpha1 has no front controllers.
```

- [ ] **Step 2: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add config/routing.yml
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: add empty routing.yml placeholder"
```

---

## Phase 2 — Install migration scaffold

### Task 8: Write failing functional test for clean install/uninstall

**Files:**
- Create: `tests/functional/install_uninstall_test.php`

- [ ] **Step 1: Write the test**

```php
<?php
/**
 * @group functional
 */

namespace avathar\bbdkp\tests\functional;

class install_uninstall_test extends \phpbb_database_test_case
{
    protected static function setup_extensions()
    {
        return ['avathar/bbdkp'];
    }

    public function getDataSet()
    {
        return $this->createXMLDataSet(__DIR__ . '/../fixtures/bbdkp_basic.xml');
    }

    public function test_version_config_set_after_install(): void
    {
        $config = $this->get_test_case_helpers()->get_test_extension_manager()
            ->get_extension('avathar/bbdkp');

        $row = $this->db()->sql_fetchrow(
            $this->db()->sql_query("SELECT config_value FROM " . CONFIG_TABLE
                . " WHERE config_name = 'bbdkp_version'")
        );

        $this->assertEquals('2.0.0-alpha1', $row['config_value']);
    }
}
```

- [ ] **Step 2: Create the fixture file**

Create `tests/fixtures/bbdkp_basic.xml`:

```xml
<?xml version="1.0" encoding="UTF-8" ?>
<dataset>
    <table name="phpbb_config">
        <column>config_name</column>
        <column>config_value</column>
        <column>is_dynamic</column>
    </table>
</dataset>
```

- [ ] **Step 3: Run the test, expect failure**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter install_uninstall_test
```
Expected: FAIL — no migration exists yet, `bbdkp_version` config is missing.

### Task 9: Create the install migration with version-only stub

**Files:**
- Create: `migrations/bbdkp_install.php`

- [ ] **Step 1: Write the migration**

```php
<?php
/**
 * Single squashed install for bbDKP v2.0.0-alpha1.
 *
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace avathar\bbdkp\migrations;

class bbdkp_install extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['bbdkp_version'])
            && version_compare($this->config['bbdkp_version'], '2.0.0-alpha1', '>=');
    }

    public static function depends_on()
    {
        // bbGuild + bbAccounts schema baseline migrations
        return [
            '\\phpbb\\db\\migration\\data\\v330\\v330',
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['bbdkp_version', '2.0.0-alpha1']],
        ];
    }

    public function revert_data()
    {
        return [
            ['config.remove', ['bbdkp_version']],
        ];
    }
}
```

- [ ] **Step 2: Lint**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/migrations/bbdkp_install.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Run the test, expect pass**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter install_uninstall_test
```
Expected: PASS.

- [ ] **Step 4: Test uninstall cycle**

In phpBB ACP: Customise → Manage extensions → bbDKP → Disable, then Delete Data. Confirm `bbdkp_version` config row is removed from `phpbb_config` table.

- [ ] **Step 5: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add migrations/ tests/
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: add install migration scaffold with version config"
```

---

## Phase 3 — Schema build-out

For each table, add it to `update_data()` in `migrations/bbdkp_install.php`, then add a test that asserts the table exists after install.

NOTE: We're adding tables INSIDE the same migration's `update_data()`, not as a new migration. The "Delete Data first" rule from memory `feedback_phpbb_inplace_migration_trap.md` applies if you've already installed alpha1 once — delete data before re-installing each time you grow the schema in this phase.

### Task 10: Add bb_dkp_pools table

**Files:**
- Modify: `migrations/bbdkp_install.php`
- Create: `tests/schema/pools_table_test.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace avathar\bbdkp\tests\schema;

class pools_table_test extends \phpbb_database_test_case
{
    protected static function setup_extensions()
    {
        return ['avathar/bbdkp'];
    }

    public function getDataSet()
    {
        return $this->createXMLDataSet(__DIR__ . '/../fixtures/bbdkp_basic.xml');
    }

    public function test_pools_table_exists(): void
    {
        $this->db()->sql_query("SELECT * FROM " . $this->get_table('bbdkp_pools') . " WHERE 1=0");
        $this->assertTrue(true, 'Pools table is queryable');
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter pools_table_test
```
Expected: FAIL — table doesn't exist.

- [ ] **Step 3: Add table creation to migration**

In `migrations/bbdkp_install.php`, replace `update_data()` with:

```php
public function update_data()
{
    return [
        ['config.add', ['bbdkp_version', '2.0.0-alpha1']],

        ['create_table', [$this->table_prefix . 'bb_dkp_pools', [
            'COLUMNS' => [
                'pool_id'        => ['UINT', null, 'auto_increment'],
                'guild_id'       => ['UINT', 0],
                'pool_name'      => ['VCHAR_UNI:255', ''],
                'pool_desc'      => ['VCHAR_UNI:255', ''],
                'pool_status'    => ['BOOL', 1],
                'pool_default'   => ['BOOL', 0],
                'added_by'       => ['UINT', 0],
                'added_at'       => ['TIMESTAMP', 0],
                'updated_by'     => ['UINT', 0],
                'updated_at'     => ['TIMESTAMP', 0],
            ],
            'PRIMARY_KEY' => 'pool_id',
            'KEYS' => [
                'guild_pool_name' => ['UNIQUE', ['guild_id', 'pool_name']],
                'idx_guild' => ['INDEX', 'guild_id'],
            ],
        ]]],
    ];
}
```

Add corresponding `drop_table` to `revert_data()`:

```php
public function revert_data()
{
    return [
        ['config.remove', ['bbdkp_version']],
        ['drop_tables', [[$this->table_prefix . 'bb_dkp_pools']]],
    ];
}
```

- [ ] **Step 4: Run test, expect pass**

In phpBB ACP: Delete Data on bbDKP (clears prior install), then re-enable. Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter pools_table_test
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add migrations/ tests/
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: add bb_dkp_pools table"
```

### Task 11: Add bb_dkp_events table

**Files:**
- Modify: `migrations/bbdkp_install.php`
- Create: `tests/schema/events_table_test.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace avathar\bbdkp\tests\schema;

class events_table_test extends \phpbb_database_test_case
{
    protected static function setup_extensions()
    {
        return ['avathar/bbdkp'];
    }

    public function getDataSet()
    {
        return $this->createXMLDataSet(__DIR__ . '/../fixtures/bbdkp_basic.xml');
    }

    public function test_events_table_exists(): void
    {
        $this->db()->sql_query("SELECT * FROM " . $this->get_table('bbdkp_events') . " WHERE 1=0");
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Run test, expect failure**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter events_table_test
```
Expected: FAIL.

- [ ] **Step 3: Add table to migration**

In `update_data()` append after pools `create_table`:

```php
['create_table', [$this->table_prefix . 'bb_dkp_events', [
    'COLUMNS' => [
        'event_id'       => ['UINT', null, 'auto_increment'],
        'pool_id'        => ['UINT', 0],
        'event_name'     => ['VCHAR_UNI:255', ''],
        'event_value'    => ['DECIMAL:11', 0],
        'event_color'    => ['VCHAR:8', ''],
        'event_icon'     => ['VCHAR:255', ''],
        'event_status'   => ['BOOL', 1],
        'added_by'       => ['UINT', 0],
        'added_at'       => ['TIMESTAMP', 0],
        'updated_by'     => ['UINT', 0],
        'updated_at'     => ['TIMESTAMP', 0],
    ],
    'PRIMARY_KEY' => 'event_id',
    'KEYS' => [
        'idx_pool' => ['INDEX', 'pool_id'],
    ],
]]],
```

Add `bb_dkp_events` to `drop_tables` array in `revert_data()`.

- [ ] **Step 4: Delete data, re-enable, run test**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter events_table_test
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add migrations/ tests/
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: add bb_dkp_events table"
```

### Task 12: Add bb_dkp_raids and bb_dkp_raid_attendees tables

**Files:**
- Modify: `migrations/bbdkp_install.php`
- Create: `tests/schema/raids_tables_test.php`

- [ ] **Step 1: Write failing test for both tables**

```php
<?php
namespace avathar\bbdkp\tests\schema;

class raids_tables_test extends \phpbb_database_test_case
{
    protected static function setup_extensions()
    {
        return ['avathar/bbdkp'];
    }

    public function getDataSet()
    {
        return $this->createXMLDataSet(__DIR__ . '/../fixtures/bbdkp_basic.xml');
    }

    public function test_raids_table_exists(): void
    {
        $this->db()->sql_query("SELECT * FROM " . $this->get_table('bbdkp_raids') . " WHERE 1=0");
        $this->assertTrue(true);
    }

    public function test_raid_attendees_table_exists(): void
    {
        $this->db()->sql_query("SELECT * FROM " . $this->get_table('bbdkp_raid_attendees') . " WHERE 1=0");
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Run, expect failure**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter raids_tables_test
```
Expected: FAIL.

- [ ] **Step 3: Add tables to migration**

In `update_data()` append:

```php
['create_table', [$this->table_prefix . 'bb_dkp_raids', [
    'COLUMNS' => [
        'raid_id'        => ['UINT', null, 'auto_increment'],
        'guild_id'       => ['UINT', 0],
        'pool_id'        => ['UINT', 0],
        'event_id'       => ['UINT', 0],
        'raid_start'     => ['TIMESTAMP', 0],
        'raid_end'       => ['TIMESTAMP', 0],         // 0 = no end set (NULL emulation)
        'raid_value'     => ['DECIMAL:11', 0],
        'raid_note'      => ['TEXT_UNI', ''],
        'added_by'       => ['UINT', 0],
        'added_at'       => ['TIMESTAMP', 0],
        'updated_by'     => ['UINT', 0],
        'updated_at'     => ['TIMESTAMP', 0],
    ],
    'PRIMARY_KEY' => 'raid_id',
    'KEYS' => [
        'idx_guild_pool' => ['INDEX', ['guild_id', 'pool_id']],
        'idx_event' => ['INDEX', 'event_id'],
        'idx_start' => ['INDEX', 'raid_start'],
    ],
]]],

['create_table', [$this->table_prefix . 'bb_dkp_raid_attendees', [
    'COLUMNS' => [
        'attendee_id'    => ['UINT', null, 'auto_increment'],
        'raid_id'        => ['UINT', 0],
        'player_id'      => ['UINT', 0],
        'join_time'      => ['TIMESTAMP', 0],         // 0 = full raid
        'leave_time'     => ['TIMESTAMP', 0],
        'value_override' => ['DECIMAL:11', 0],         // 0 = no override (use raid.raid_value)
    ],
    'PRIMARY_KEY' => 'attendee_id',
    'KEYS' => [
        'raid_player' => ['UNIQUE', ['raid_id', 'player_id']],
        'idx_player' => ['INDEX', 'player_id'],
    ],
]]],
```

Add both to `drop_tables` array.

- [ ] **Step 4: Delete data, re-enable, run test**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter raids_tables_test
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add migrations/ tests/
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: add bb_dkp_raids + bb_dkp_raid_attendees tables"
```

### Task 13: Add bb_dkp_items (catalog) and bb_dkp_loot (history) tables

**Files:**
- Modify: `migrations/bbdkp_install.php`
- Create: `tests/schema/loot_tables_test.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace avathar\bbdkp\tests\schema;

class loot_tables_test extends \phpbb_database_test_case
{
    protected static function setup_extensions()
    {
        return ['avathar/bbdkp'];
    }

    public function getDataSet()
    {
        return $this->createXMLDataSet(__DIR__ . '/../fixtures/bbdkp_basic.xml');
    }

    public function test_items_table_exists(): void
    {
        $this->db()->sql_query("SELECT * FROM " . $this->get_table('bbdkp_items') . " WHERE 1=0");
        $this->assertTrue(true);
    }

    public function test_loot_table_exists(): void
    {
        $this->db()->sql_query("SELECT * FROM " . $this->get_table('bbdkp_loot') . " WHERE 1=0");
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Run, expect failure**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter loot_tables_test
```
Expected: FAIL.

- [ ] **Step 3: Add tables**

```php
['create_table', [$this->table_prefix . 'bb_dkp_items', [
    'COLUMNS' => [
        'item_id'        => ['UINT', null, 'auto_increment'],
        'game_id'        => ['VCHAR:10', ''],
        'item_name'      => ['VCHAR_UNI:255', ''],
        'item_gameid'    => ['VCHAR:50', ''],
        'itempool_id'    => ['UINT', 0],              // 0 = none; future grouping
        'added_by'       => ['UINT', 0],
        'added_at'       => ['TIMESTAMP', 0],
        'updated_by'     => ['UINT', 0],
        'updated_at'     => ['TIMESTAMP', 0],
    ],
    'PRIMARY_KEY' => 'item_id',
    'KEYS' => [
        'game_name' => ['UNIQUE', ['game_id', 'item_name']],
        'idx_gameid' => ['INDEX', 'item_gameid'],
    ],
]]],

['create_table', [$this->table_prefix . 'bb_dkp_loot', [
    'COLUMNS' => [
        'loot_id'        => ['UINT', null, 'auto_increment'],
        'item_id'        => ['UINT', 0],
        'raid_id'        => ['UINT', 0],
        'player_id'      => ['UINT', 0],
        'value'          => ['DECIMAL:11', 0],
        'drop_date'      => ['TIMESTAMP', 0],
        'added_by'       => ['UINT', 0],
        'added_at'       => ['TIMESTAMP', 0],
        'updated_by'     => ['UINT', 0],
        'updated_at'     => ['TIMESTAMP', 0],
    ],
    'PRIMARY_KEY' => 'loot_id',
    'KEYS' => [
        'idx_raid' => ['INDEX', 'raid_id'],
        'idx_item' => ['INDEX', 'item_id'],
        'idx_player_date' => ['INDEX', ['player_id', 'drop_date']],
    ],
]]],
```

Add both to `drop_tables`.

- [ ] **Step 4: Delete data, re-enable, run test**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter loot_tables_test
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add migrations/ tests/
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: add bb_dkp_items + bb_dkp_loot tables"
```

### Task 14: Add bb_dkp_adjustments and bb_dkp_adjustment_recipients tables

**Files:**
- Modify: `migrations/bbdkp_install.php`
- Create: `tests/schema/adjustments_tables_test.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace avathar\bbdkp\tests\schema;

class adjustments_tables_test extends \phpbb_database_test_case
{
    protected static function setup_extensions()
    {
        return ['avathar/bbdkp'];
    }

    public function getDataSet()
    {
        return $this->createXMLDataSet(__DIR__ . '/../fixtures/bbdkp_basic.xml');
    }

    public function test_adjustments_table_exists(): void
    {
        $this->db()->sql_query("SELECT * FROM " . $this->get_table('bbdkp_adjustments') . " WHERE 1=0");
        $this->assertTrue(true);
    }

    public function test_adjustment_recipients_table_exists(): void
    {
        $this->db()->sql_query("SELECT * FROM " . $this->get_table('bbdkp_adjustment_recipients') . " WHERE 1=0");
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Run, expect failure**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter adjustments_tables_test
```
Expected: FAIL.

- [ ] **Step 3: Add tables**

```php
['create_table', [$this->table_prefix . 'bb_dkp_adjustments', [
    'COLUMNS' => [
        'adjustment_id'      => ['UINT', null, 'auto_increment'],
        'pool_id'            => ['UINT', 0],
        'adjustment_date'    => ['TIMESTAMP', 0],
        'adjustment_reason'  => ['VCHAR_UNI:255', ''],
        'group_key'          => ['VCHAR:64', ''],
        'added_by'           => ['UINT', 0],
        'added_at'           => ['TIMESTAMP', 0],
        'updated_by'         => ['UINT', 0],
        'updated_at'         => ['TIMESTAMP', 0],
    ],
    'PRIMARY_KEY' => 'adjustment_id',
    'KEYS' => [
        'idx_pool_date' => ['INDEX', ['pool_id', 'adjustment_date']],
        'idx_group_key' => ['INDEX', 'group_key'],
    ],
]]],

['create_table', [$this->table_prefix . 'bb_dkp_adjustment_recipients', [
    'COLUMNS' => [
        'recipient_id'   => ['UINT', null, 'auto_increment'],
        'adjustment_id'  => ['UINT', 0],
        'player_id'      => ['UINT', 0],
        'amount'         => ['DECIMAL:11', 0],
    ],
    'PRIMARY_KEY' => 'recipient_id',
    'KEYS' => [
        'adj_player' => ['UNIQUE', ['adjustment_id', 'player_id']],
        'idx_player' => ['INDEX', 'player_id'],
    ],
]]],
```

Add both to `drop_tables`.

- [ ] **Step 4: Delete data, re-enable, run test**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter adjustments_tables_test
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add migrations/ tests/
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: add bb_dkp_adjustments + bb_dkp_adjustment_recipients tables"
```

### Task 15: Add bb_dkp_ledger_link table

**Files:**
- Modify: `migrations/bbdkp_install.php`
- Create: `tests/schema/ledger_link_table_test.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace avathar\bbdkp\tests\schema;

class ledger_link_table_test extends \phpbb_database_test_case
{
    protected static function setup_extensions()
    {
        return ['avathar/bbdkp'];
    }

    public function getDataSet()
    {
        return $this->createXMLDataSet(__DIR__ . '/../fixtures/bbdkp_basic.xml');
    }

    public function test_ledger_link_table_exists(): void
    {
        $this->db()->sql_query("SELECT * FROM " . $this->get_table('bbdkp_ledger_link') . " WHERE 1=0");
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Run, expect failure**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter ledger_link_table_test
```
Expected: FAIL.

- [ ] **Step 3: Add table**

```php
['create_table', [$this->table_prefix . 'bb_dkp_ledger_link', [
    'COLUMNS' => [
        'link_id'            => ['UINT', null, 'auto_increment'],
        'entity_type'        => ['VCHAR:32', ''],   // 'raid_attendee' | 'loot' | 'adjustment_recipient'
        'entity_id'          => ['UINT', 0],
        'journal_entry_id'   => ['UINT', 0],
        'reversal_of'        => ['UINT', 0],        // 0 = forward post; otherwise = link_id reversed
        'posted_at'          => ['TIMESTAMP', 0],
    ],
    'PRIMARY_KEY' => 'link_id',
    'KEYS' => [
        'idx_entity' => ['INDEX', ['entity_type', 'entity_id']],
        'idx_je' => ['INDEX', 'journal_entry_id'],
        'idx_reversal' => ['INDEX', 'reversal_of'],
    ],
]]],
```

Add to `drop_tables`.

- [ ] **Step 4: Delete data, re-enable, run test**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter ledger_link_table_test
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add migrations/ tests/
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: add bb_dkp_ledger_link table"
```

---

## Phase 4 — Permissions, ACP module skeleton, language files

### Task 16: Add four bbDKP permissions

**Files:**
- Modify: `migrations/bbdkp_install.php`
- Create: `language/en/permissions_bbdkp.php`
- Create: `tests/functional/permissions_test.php`

- [ ] **Step 1: Write failing test**

```php
<?php
namespace avathar\bbdkp\tests\functional;

class permissions_test extends \phpbb_database_test_case
{
    protected static function setup_extensions()
    {
        return ['avathar/bbdkp'];
    }

    public function getDataSet()
    {
        return $this->createXMLDataSet(__DIR__ . '/../fixtures/bbdkp_basic.xml');
    }

    /**
     * @dataProvider permission_provider
     */
    public function test_permission_exists(string $perm): void
    {
        $row = $this->db()->sql_fetchrow($this->db()->sql_query(
            "SELECT auth_option_id FROM " . ACL_OPTIONS_TABLE
            . " WHERE auth_option = '" . $this->db()->sql_escape($perm) . "'"
        ));
        $this->assertNotEmpty($row, "permission $perm should be registered");
    }

    public function permission_provider(): array
    {
        return [
            ['a_bbdkp'],
            ['m_bbdkp'],
            ['u_bbdkp_view'],
            ['u_bbdkp_view_others'],
        ];
    }
}
```

- [ ] **Step 2: Run, expect failure**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter permissions_test
```
Expected: FAIL.

- [ ] **Step 3: Add permission language file**

`language/en/permissions_bbdkp.php`:

```php
<?php
/**
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

if (!defined('IN_PHPBB')) { exit; }

if (empty($lang) || !is_array($lang)) { $lang = []; }

$lang = array_merge($lang, [
    'ACL_A_BBDKP'            => 'bbDKP: Can manage all DKP settings',
    'ACL_M_BBDKP'            => 'bbDKP: Can add/edit raids, loot, adjustments',
    'ACL_U_BBDKP_VIEW'       => 'bbDKP: Can view DKP standings and history',
    'ACL_U_BBDKP_VIEW_OTHERS' => 'bbDKP: Can view DKP pages of other characters',
]);
```

- [ ] **Step 4: Add permissions to migration**

Append to `update_data()`:

```php
['permission.add', ['a_bbdkp', true]],
['permission.add', ['m_bbdkp', true]],
['permission.add', ['u_bbdkp_view', true]],
['permission.add', ['u_bbdkp_view_others', true]],

['permission.permission_set', ['ROLE_ADMIN_FULL', 'a_bbdkp']],
['permission.permission_set', ['ROLE_MOD_FULL', 'm_bbdkp']],
['permission.permission_set', ['ROLE_USER_STANDARD', 'u_bbdkp_view']],
['permission.permission_set', ['ROLE_USER_FULL', 'u_bbdkp_view']],
['permission.permission_set', ['ROLE_USER_FULL', 'u_bbdkp_view_others']],
['permission.permission_set', ['GUESTS', 'u_bbdkp_view', 'group']],
['permission.permission_set', ['REGISTERED', 'u_bbdkp_view', 'group']],
```

Append to `revert_data()` (BEFORE the drop_tables call):

```php
['permission.remove', ['a_bbdkp']],
['permission.remove', ['m_bbdkp']],
['permission.remove', ['u_bbdkp_view']],
['permission.remove', ['u_bbdkp_view_others']],
```

- [ ] **Step 5: Delete data, re-enable, run test**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter permissions_test
```
Expected: PASS.

- [ ] **Step 6: Verify language label in ACP**

In phpBB ACP: Permissions → Manage all permissions. Confirm the four labels appear with `bbDKP:` prefix.

- [ ] **Step 7: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add migrations/ language/ tests/
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: add bbDKP permissions"
```

### Task 17: Create base language file

**Files:**
- Create: `language/en/common.php`

- [ ] **Step 1: Write the file**

```php
<?php
/**
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

if (!defined('IN_PHPBB')) { exit; }

if (empty($lang) || !is_array($lang)) { $lang = []; }

$lang = array_merge($lang, [
    'BBDKP'                  => 'bbDKP',
    'BBDKP_VERSION'          => 'bbDKP Version',
    'BBDKP_DEP_MISSING'      => 'bbDKP requires bbGuild ≥ 2.0.0-b1 and bbAccounts ≥ 1.0.0 to be installed and enabled.',
]);
```

- [ ] **Step 2: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add language/en/common.php
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: add base language file"
```

### Task 18: Register ACP_DKP category + pool module placeholder

**Files:**
- Modify: `migrations/bbdkp_install.php`
- Create: `acp/acp_pool_info.php`
- Create: `language/en/info_acp_pool.php`

- [ ] **Step 1: Write info_acp_pool.php (language)**

`language/en/info_acp_pool.php`:

```php
<?php
if (!defined('IN_PHPBB')) { exit; }
if (empty($lang) || !is_array($lang)) { $lang = []; }

$lang = array_merge($lang, [
    'ACP_DKP'                => 'bbDKP',
    'ACP_DKP_POOLS'          => 'DKP Pools',
    'ACP_DKP_POOLS_EXPLAIN'  => 'Define and manage DKP pools (separate point economies).',
]);
```

- [ ] **Step 2: Write acp_pool_info.php**

`acp/acp_pool_info.php`:

```php
<?php
/**
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace avathar\bbdkp\acp;

class acp_pool_info
{
    public function module()
    {
        return [
            'filename' => '\\avathar\\bbdkp\\acp\\acp_pool_module',
            'title'    => 'ACP_DKP_POOLS',
            'modes'    => [
                'list' => [
                    'title' => 'ACP_DKP_POOLS',
                    'auth'  => 'ext_avathar/bbdkp && acl_a_bbdkp',
                    'cat'   => ['ACP_DKP'],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 3: Add module registration to migration**

Append to `update_data()`:

```php
// Top-level category under ACP_BBGUILD_MAINPAGE (bbGuild's category)
['module.add', ['acp', 'ACP_BBGUILD_MAINPAGE', [
    'module_basename' => '',
    'module_langname' => 'ACP_DKP',
    'module_mode'     => '',
    'module_auth'     => 'ext_avathar/bbdkp && acl_a_bbdkp',
]]],

// Pool module
['module.add', ['acp', 'ACP_DKP', [
    'module_basename' => '\\avathar\\bbdkp\\acp\\acp_pool_module',
    'module_langname' => 'ACP_DKP_POOLS',
    'module_mode'     => 'list',
    'module_auth'     => 'ext_avathar/bbdkp && acl_a_bbdkp',
]]],
```

Append to `revert_data()` (BEFORE permission.remove calls):

```php
['module.remove', ['acp', false, 'ACP_DKP_POOLS']],
['module.remove', ['acp', false, 'ACP_DKP']],
```

- [ ] **Step 4: Create placeholder acp_pool_module.php (real impl comes in Task 26)**

`acp/acp_pool_module.php`:

```php
<?php
/**
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace avathar\bbdkp\acp;

class acp_pool_module
{
    public $u_action;
    public $tpl_name;
    public $page_title;

    public function main($id, $mode)
    {
        global $template;

        $this->tpl_name   = 'acp_bbdkp_pool';
        $this->page_title = 'ACP_DKP_POOLS';

        $template->assign_var('U_ACTION', $this->u_action);
    }
}
```

- [ ] **Step 5: Create placeholder template**

`adm/style/acp_bbdkp_pool.html`:

```html
<!-- INCLUDE overall_header.html -->

<h1>{L_ACP_DKP_POOLS}</h1>
<p>{L_ACP_DKP_POOLS_EXPLAIN}</p>
<p>Pool management UI will be added in Task 26.</p>

<!-- INCLUDE overall_footer.html -->
```

- [ ] **Step 6: Delete data, re-enable**

In phpBB ACP: bbDKP → Delete Data, then Enable. Confirm "bbDKP" appears under ACP_BBGUILD_MAINPAGE with a "DKP Pools" child showing the placeholder page.

- [ ] **Step 7: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add migrations/ acp/ adm/ language/
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: register ACP_DKP category and pool module placeholder"
```

### Task 19: Add event module placeholder (mirror of pool)

**Files:**
- Modify: `migrations/bbdkp_install.php`
- Create: `acp/acp_event_info.php`
- Create: `acp/acp_event_module.php`
- Create: `adm/style/acp_bbdkp_event.html`
- Create: `language/en/info_acp_event.php`

- [ ] **Step 1: Write info_acp_event.php**

`language/en/info_acp_event.php`:

```php
<?php
if (!defined('IN_PHPBB')) { exit; }
if (empty($lang) || !is_array($lang)) { $lang = []; }

$lang = array_merge($lang, [
    'ACP_DKP_EVENTS'         => 'DKP Events',
    'ACP_DKP_EVENTS_EXPLAIN' => 'Define raid encounter types and their default DKP values.',
]);
```

- [ ] **Step 2: Write acp_event_info.php**

```php
<?php
namespace avathar\bbdkp\acp;

class acp_event_info
{
    public function module()
    {
        return [
            'filename' => '\\avathar\\bbdkp\\acp\\acp_event_module',
            'title'    => 'ACP_DKP_EVENTS',
            'modes'    => [
                'list' => [
                    'title' => 'ACP_DKP_EVENTS',
                    'auth'  => 'ext_avathar/bbdkp && acl_a_bbdkp',
                    'cat'   => ['ACP_DKP'],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 3: Write acp_event_module.php placeholder**

```php
<?php
namespace avathar\bbdkp\acp;

class acp_event_module
{
    public $u_action;
    public $tpl_name;
    public $page_title;

    public function main($id, $mode)
    {
        global $template;
        $this->tpl_name   = 'acp_bbdkp_event';
        $this->page_title = 'ACP_DKP_EVENTS';
        $template->assign_var('U_ACTION', $this->u_action);
    }
}
```

- [ ] **Step 4: Write template placeholder**

`adm/style/acp_bbdkp_event.html`:

```html
<!-- INCLUDE overall_header.html -->

<h1>{L_ACP_DKP_EVENTS}</h1>
<p>{L_ACP_DKP_EVENTS_EXPLAIN}</p>
<p>Event management UI will be added in Task 30.</p>

<!-- INCLUDE overall_footer.html -->
```

- [ ] **Step 5: Add module registration to migration**

Append to `update_data()` (after pool module):

```php
['module.add', ['acp', 'ACP_DKP', [
    'module_basename' => '\\avathar\\bbdkp\\acp\\acp_event_module',
    'module_langname' => 'ACP_DKP_EVENTS',
    'module_mode'     => 'list',
    'module_auth'     => 'ext_avathar/bbdkp && acl_a_bbdkp',
]]],
```

Append to `revert_data()` (before category removal):

```php
['module.remove', ['acp', false, 'ACP_DKP_EVENTS']],
```

- [ ] **Step 6: Delete data, re-enable, verify UI**

Confirm "DKP Events" appears next to "DKP Pools" under bbDKP category.

- [ ] **Step 7: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add migrations/ acp/ adm/ language/
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: register event ACP module placeholder"
```

---

## Phase 5 — dkp_ledger service (the core)

### Task 20: Define dkp_ledger interface

**Files:**
- Create: `service/dkp_ledger_interface.php`

- [ ] **Step 1: Write the interface**

```php
<?php
/**
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace avathar\bbdkp\service;

interface dkp_ledger_interface
{
    /**
     * Mint the 5 chart-of-accounts entries for a new pool.
     *
     * @param int $pool_id
     * @return array<string, int> map of role => account_id, with keys:
     *   'player_wallets', 'raid_attendance', 'loot_proceeds', 'adjust_credit', 'adjust_debit'
     */
    public function mint_pool_accounts(int $pool_id): array;

    /**
     * Archive (soft-disable) accounts for a pool without deleting them.
     */
    public function archive_pool_accounts(int $pool_id): void;

    /**
     * Hard-delete accounts for a pool. Throws if any journal entries reference them.
     */
    public function delete_pool_accounts(int $pool_id): void;

    /**
     * Post a raid award. DR raid_attendance / CR player_wallets.
     * Records a forward link in bb_dkp_ledger_link.
     *
     * @param int $attendee_id row PK in bb_dkp_raid_attendees
     * @param int $pool_id
     * @param int $player_id
     * @param string $amount decimal string (e.g. '75.00')
     * @return int link_id
     */
    public function post_raid_award(int $attendee_id, int $pool_id, int $player_id, string $amount): int;

    /**
     * Post a loot purchase. DR player_wallets / CR loot_proceeds.
     *
     * @param int $loot_id
     * @param int $pool_id
     * @param int $player_id buyer
     * @param string $value
     * @return int link_id
     */
    public function post_loot_purchase(int $loot_id, int $pool_id, int $player_id, string $value): int;

    /**
     * Post an adjustment line. Direction depends on amount sign.
     *
     * @param int $recipient_id row PK in bb_dkp_adjustment_recipients
     * @param int $pool_id
     * @param int $player_id
     * @param string $amount signed decimal string
     * @return int link_id
     */
    public function post_adjustment(int $recipient_id, int $pool_id, int $player_id, string $amount): int;

    /**
     * Reverse a previously posted entry by link_id. Posts the inverse journal entry
     * and writes a new link row with reversal_of=<original>.
     *
     * @return int the new link_id of the reversal
     */
    public function reverse(int $link_id): int;
}
```

- [ ] **Step 2: Lint**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/service/dkp_ledger_interface.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add service/
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: define dkp_ledger interface"
```

### Task 21: Write failing test for mint_pool_accounts

**Files:**
- Create: `tests/service/dkp_ledger_test.php`

- [ ] **Step 1: Write the test**

```php
<?php
namespace avathar\bbdkp\tests\service;

class dkp_ledger_test extends \phpbb_database_test_case
{
    protected static function setup_extensions()
    {
        return ['avathar/bbguild', 'avathar/bbaccounts', 'avathar/bbdkp'];
    }

    public function getDataSet()
    {
        return $this->createXMLDataSet(__DIR__ . '/../fixtures/bbdkp_basic.xml');
    }

    protected function ledger(): \avathar\bbdkp\service\dkp_ledger_interface
    {
        global $phpbb_container;
        return $phpbb_container->get('avathar.bbdkp.dkp_ledger');
    }

    public function test_mint_pool_accounts_creates_five_accounts(): void
    {
        $pool_id = 42;
        $accounts = $this->ledger()->mint_pool_accounts($pool_id);

        $this->assertCount(5, $accounts);
        $this->assertArrayHasKey('player_wallets', $accounts);
        $this->assertArrayHasKey('raid_attendance', $accounts);
        $this->assertArrayHasKey('loot_proceeds', $accounts);
        $this->assertArrayHasKey('adjust_credit', $accounts);
        $this->assertArrayHasKey('adjust_debit', $accounts);
    }

    public function test_mint_pool_accounts_names_follow_convention(): void
    {
        $pool_id = 7;
        $this->ledger()->mint_pool_accounts($pool_id);

        // Check via bbAccounts' ledger service (the canonical service id).
        // We filter accounts by subledger_type to find the per-pool wallet
        // account; the other 4 accounts have subledger_type='' so we look
        // them up by code prefix using list_accounts() + array filter.
        global $phpbb_container;
        $bbaccounts = $phpbb_container->get('avathar.bbaccounts.service.ledger');
        $all = $bbaccounts->list_accounts();
        $ours = array_values(array_filter(
            $all,
            static fn ($a) => strpos($a['account_code'], "dkp_p{$pool_id}_") === 0
        ));

        $this->assertCount(5, $ours);
        $codes = array_column($ours, 'account_code');
        sort($codes);
        $this->assertEquals([
            "dkp_p{$pool_id}_ac",   // adjust_credit
            "dkp_p{$pool_id}_ad",   // adjust_debit
            "dkp_p{$pool_id}_lp",   // loot_proceeds
            "dkp_p{$pool_id}_ra",   // raid_attendance
            "dkp_p{$pool_id}_w",    // player_wallets
        ], $codes);

        // The wallets account must have subledger_type='character'; the
        // other four are non-subledger.
        $wallets = array_values(array_filter(
            $ours,
            static fn ($a) => $a['account_code'] === "dkp_p{$pool_id}_w"
        ));
        $this->assertSame('character', $wallets[0]['subledger_type']);
    }
}
```

NOTE: bbAccounts uses `account_code` (VCHAR:20) as the unique key — full descriptive names like `dkp_pool_7_player_wallets` exceed 20 characters. The plan uses short codes `dkp_p<id>_w/ra/lp/ac/ad` (max 14 chars even for pool_id=999999) and longer human-readable `account_name` values (e.g. "DKP Pool 7 — Player Wallets").

- [ ] **Step 2: Run, expect failure**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter dkp_ledger_test
```
Expected: FAIL — service `avathar.bbdkp.dkp_ledger` not defined.

### Task 22: Implement dkp_ledger.mint_pool_accounts

**Files:**
- Create: `service/dkp_ledger.php`
- Modify: `config/services.yml`

- [ ] **Step 1: Re-verify the bbAccounts API surface (already explored in spec §6.6)**

Run:
```bash
grep -nE "public function (create_account|create_entry|reverse_entry|list_accounts|get_subledger_balance_by_character|get_subledger_account_balances_by_character|anonymize_player_subledger)" /Users/Andreas/Sites/avathar/forum/ext/avathar/bbaccounts/service/ledger.php
```
Expected: all seven methods present in the file. Signatures used below:
- `create_account(string $code, string $name, string $type, string $currency='POINTS', int $parent_id=0, string $subledger_type='', bool $is_active=true): int`
- `create_entry(int $entry_date, string $description, array $lines, string $reference_type='manual', int $reference_id=0, string $reference_source='', int $created_by=0): int`
  - Each line: `['account_id', 'debit', 'credit', 'subledger_user_id', 'subledger_player_id', 'memo']`
- `reverse_entry(int $journal_id, string $description='', int $created_by=0): int`
- `list_accounts(string $account_type='', ?string $subledger_type=null): array`
- `get_subledger_balance_by_character(int $account_id, int $player_id, int $as_of=0): string`
- `get_subledger_account_balances_by_character(int $player_id, int $from=0, int $to=0): array`
- `anonymize_player_subledger(int $player_id, int $replacement=0): int`

The bbAccounts service id is `avathar.bbaccounts.service.ledger`.

- [ ] **Step 2: Write dkp_ledger.php implementation**

```php
<?php
/**
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace avathar\bbdkp\service;

use avathar\bbaccounts\service\ledger as bbaccounts_ledger;

class dkp_ledger implements dkp_ledger_interface
{
	/** @var \phpbb\db\driver\driver_interface */
	private $db;

	/** @var bbaccounts_ledger */
	private $bbaccounts;

	/** @var string bb_dkp_ledger_link table name */
	private $table_ledger_link;

	/**
	 * Per-pool account roles. Account codes use short form `dkp_p<id>_<r>`
	 * to fit bbAccounts' VCHAR:20 `account_code` constraint even for large
	 * pool ids. Account names are human-readable.
	 *
	 * `subledger_type='character'` only on `player_wallets`; the other 4
	 * accounts are non-subledger expense/revenue.
	 */
	private const ROLES = [
		'player_wallets'  => ['suffix' => 'w',  'type' => 'liability', 'subledger' => 'character', 'name_suffix' => 'Player Wallets'],
		'raid_attendance' => ['suffix' => 'ra', 'type' => 'expense',   'subledger' => '',          'name_suffix' => 'Raid Attendance'],
		'loot_proceeds'   => ['suffix' => 'lp', 'type' => 'revenue',   'subledger' => '',          'name_suffix' => 'Loot Proceeds'],
		'adjust_credit'   => ['suffix' => 'ac', 'type' => 'expense',   'subledger' => '',          'name_suffix' => 'Adjustment Credits'],
		'adjust_debit'   => ['suffix' => 'ad', 'type' => 'revenue',   'subledger' => '',          'name_suffix' => 'Adjustment Debits'],
	];

	public function __construct(
		\phpbb\db\driver\driver_interface $db,
		bbaccounts_ledger $bbaccounts,
		string $table_ledger_link
	)
	{
		$this->db = $db;
		$this->bbaccounts = $bbaccounts;
		$this->table_ledger_link = $table_ledger_link;
	}

	public function mint_pool_accounts(int $pool_id): array
	{
		$result = [];

		foreach (self::ROLES as $role => $cfg)
		{
			$code = sprintf('dkp_p%d_%s', $pool_id, $cfg['suffix']);
			$name = sprintf('DKP Pool %d — %s', $pool_id, $cfg['name_suffix']);

			$account_id = $this->bbaccounts->create_account(
				$code,
				$name,
				$cfg['type'],
				'POINTS',
				0,
				$cfg['subledger']
			);
			$result[$role] = (int) $account_id;
		}

		return $result;
	}

	/**
	 * Soft-disable is a no-op at the bbAccounts layer in v1.1.0-alpha —
	 * bbAccounts does not expose a public "deactivate by code" method.
	 * bbDKP's own `bb_dkp_pools.pool_status = 0` flag is what hides the
	 * pool from UI dropdowns; the bbAccounts accounts stay queryable so
	 * historical reads keep working. File a bbAccounts follow-up if a
	 * true deactivation is needed later.
	 */
	public function archive_pool_accounts(int $pool_id): void
	{
		// no-op (see docblock)
	}

	/**
	 * Hard-delete is not supported in v1.1.0-alpha — bbAccounts has no
	 * public account-delete API and the immutable-journal posture means
	 * we cannot remove accounts that have referenced lines. Throws so the
	 * caller (`pool_manager::delete_pool`) knows to surface a clean error
	 * to the operator.
	 */
	public function delete_pool_accounts(int $pool_id): void
	{
		throw new \RuntimeException(
			'Pool hard-delete is not supported in bbDKP v2.0.0-alpha1: bbAccounts does not expose '
			. 'an account-delete API. Use disable instead.'
		);
	}

	public function post_raid_award(int $attendee_id, int $pool_id, int $player_id, string $amount): int
	{
		$expense_id = $this->lookup_account_id($pool_id, 'ra');
		$wallet_id  = $this->lookup_account_id($pool_id, 'w');

		$je_id = $this->bbaccounts->create_entry(
			time(),
			sprintf('Raid award (attendee #%d)', $attendee_id),
			[
				['account_id' => $expense_id, 'debit' => $amount, 'credit' => '0.00',   'subledger_user_id' => 0, 'subledger_player_id' => 0,          'memo' => ''],
				['account_id' => $wallet_id,  'debit' => '0.00',  'credit' => $amount,  'subledger_user_id' => 0, 'subledger_player_id' => $player_id, 'memo' => ''],
			],
			'auto',
			$attendee_id,
			'bbdkp.raid_attendee'
		);

		return $this->write_link('raid_attendee', $attendee_id, $je_id);
	}

	public function post_loot_purchase(int $loot_id, int $pool_id, int $player_id, string $value): int
	{
		$wallet_id   = $this->lookup_account_id($pool_id, 'w');
		$proceeds_id = $this->lookup_account_id($pool_id, 'lp');

		$je_id = $this->bbaccounts->create_entry(
			time(),
			sprintf('Loot purchase (loot #%d)', $loot_id),
			[
				['account_id' => $wallet_id,   'debit' => $value, 'credit' => '0.00', 'subledger_user_id' => 0, 'subledger_player_id' => $player_id, 'memo' => ''],
				['account_id' => $proceeds_id, 'debit' => '0.00', 'credit' => $value, 'subledger_user_id' => 0, 'subledger_player_id' => 0,          'memo' => ''],
			],
			'auto',
			$loot_id,
			'bbdkp.loot'
		);

		return $this->write_link('loot', $loot_id, $je_id);
	}

	public function post_adjustment(int $recipient_id, int $pool_id, int $player_id, string $amount): int
	{
		$wallet_id = $this->lookup_account_id($pool_id, 'w');
		$abs = ltrim($amount, '-');

		if ($amount !== '' && $amount[0] === '-')
		{
			$counter_id = $this->lookup_account_id($pool_id, 'ad');
			$lines = [
				['account_id' => $wallet_id,  'debit' => $abs,   'credit' => '0.00', 'subledger_user_id' => 0, 'subledger_player_id' => $player_id, 'memo' => ''],
				['account_id' => $counter_id, 'debit' => '0.00', 'credit' => $abs,   'subledger_user_id' => 0, 'subledger_player_id' => 0,          'memo' => ''],
			];
		}
		else
		{
			$counter_id = $this->lookup_account_id($pool_id, 'ac');
			$lines = [
				['account_id' => $counter_id, 'debit' => $abs,   'credit' => '0.00', 'subledger_user_id' => 0, 'subledger_player_id' => 0,          'memo' => ''],
				['account_id' => $wallet_id,  'debit' => '0.00', 'credit' => $abs,   'subledger_user_id' => 0, 'subledger_player_id' => $player_id, 'memo' => ''],
			];
		}

		$je_id = $this->bbaccounts->create_entry(
			time(),
			sprintf('Adjustment (recipient #%d)', $recipient_id),
			$lines,
			'auto',
			$recipient_id,
			'bbdkp.adjustment_recipient'
		);

		return $this->write_link('adjustment_recipient', $recipient_id, $je_id);
	}

	public function reverse(int $link_id): int
	{
		$row = $this->fetch_link($link_id);
		if (!$row)
		{
			throw new \RuntimeException("link {$link_id} not found");
		}
		if ((int) $row['reversal_of'] !== 0)
		{
			throw new \RuntimeException("link {$link_id} is itself a reversal");
		}

		$je_id = $this->bbaccounts->reverse_entry((int) $row['journal_entry_id']);

		$sql_ary = [
			'entity_type'      => $row['entity_type'],
			'entity_id'        => (int) $row['entity_id'],
			'journal_entry_id' => $je_id,
			'reversal_of'      => $link_id,
			'posted_at'        => time(),
		];
		$this->db->sql_query(
			'INSERT INTO ' . $this->table_ledger_link . ' ' . $this->db->sql_build_array('INSERT', $sql_ary)
		);

		return (int) $this->db->sql_nextid();
	}

	/**
	 * Resolve a per-pool account code to its account_id by scanning the
	 * bbAccounts account list. Cheap enough for alpha1; can be cached if
	 * profiling shows it matters.
	 */
	private function lookup_account_id(int $pool_id, string $suffix): int
	{
		$wanted = sprintf('dkp_p%d_%s', $pool_id, $suffix);
		foreach ($this->bbaccounts->list_accounts() as $row)
		{
			if ($row['account_code'] === $wanted)
			{
				return (int) $row['account_id'];
			}
		}
		throw new \RuntimeException("bbAccounts account '{$wanted}' not found (was the pool minted?)");
	}

	private function write_link(string $entity_type, int $entity_id, int $je_id): int
	{
		$sql_ary = [
			'entity_type'      => $entity_type,
			'entity_id'        => $entity_id,
			'journal_entry_id' => $je_id,
			'reversal_of'      => 0,
			'posted_at'        => time(),
		];
		$this->db->sql_query(
			'INSERT INTO ' . $this->table_ledger_link . ' ' . $this->db->sql_build_array('INSERT', $sql_ary)
		);

		return (int) $this->db->sql_nextid();
	}

	private function fetch_link(int $link_id): ?array
	{
		$sql = 'SELECT * FROM ' . $this->table_ledger_link . ' WHERE link_id = ' . (int) $link_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row ?: null;
	}
}
```

- [ ] **Step 3: Register the service in services.yml**

Append to `config/services.yml`:

```yaml
    avathar.bbdkp.dkp_ledger:
        class: avathar\bbdkp\service\dkp_ledger
        arguments:
            - '@dbal.conn'
            - '@avathar.bbaccounts.service.ledger'
            - '%tables.bbdkp_ledger_link%'
        public: true
```

- [ ] **Step 4: Lint**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/service/dkp_ledger.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 5: Run test for mint_pool_accounts**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter dkp_ledger_test::test_mint_pool_accounts
```
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add service/ config/services.yml tests/service/
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: implement dkp_ledger.mint_pool_accounts"
```

### Task 23: Test and verify post_raid_award

**Files:**
- Modify: `tests/service/dkp_ledger_test.php`

- [ ] **Step 1: Add posting test**

Append to `dkp_ledger_test.php`:

```php
    public function test_post_raid_award_writes_balanced_je_and_link(): void
    {
        $pool_id = 1;
        $player_id = 100;
        $accounts = $this->ledger()->mint_pool_accounts($pool_id);

        $link_id = $this->ledger()->post_raid_award(
            attendee_id: 999,
            pool_id: $pool_id,
            player_id: $player_id,
            amount: '50.00'
        );

        $this->assertGreaterThan(0, $link_id);

        // Per-character balance via bbAccounts' character-keyed read API.
        global $phpbb_container;
        $bbaccounts = $phpbb_container->get('avathar.bbaccounts.service.ledger');
        $balance = $bbaccounts->get_subledger_balance_by_character(
            $accounts['player_wallets'],
            $player_id
        );
        // Wallets is liability (credit-normal) → normal_balance returns cr - dr = 50 - 0 = 50.
        $this->assertEquals('50.00', $balance);
    }
```

- [ ] **Step 2: Run, expect PASS** (impl already done in task 22)

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter dkp_ledger_test::test_post_raid_award
```
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add tests/
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "test: verify post_raid_award balanced posting"
```

### Task 24: Test reverse() restores balance

**Files:**
- Modify: `tests/service/dkp_ledger_test.php`

- [ ] **Step 1: Add reversal test**

```php
    public function test_reverse_returns_subledger_balance_to_zero(): void
    {
        $pool_id = 2;
        $player_id = 200;
        $accounts = $this->ledger()->mint_pool_accounts($pool_id);

        $link_id = $this->ledger()->post_raid_award(1, $pool_id, $player_id, '75.00');

        global $phpbb_container;
        $bbaccounts = $phpbb_container->get('avathar.bbaccounts.service.ledger');
        $this->assertEquals(
            '75.00',
            $bbaccounts->get_subledger_balance_by_character($accounts['player_wallets'], $player_id)
        );

        $reversal_link_id = $this->ledger()->reverse($link_id);
        $this->assertGreaterThan($link_id, $reversal_link_id);

        $this->assertEquals(
            '0.00',
            $bbaccounts->get_subledger_balance_by_character($accounts['player_wallets'], $player_id)
        );
    }

    public function test_reverse_throws_on_reversal_link(): void
    {
        $pool_id = 3;
        $this->ledger()->mint_pool_accounts($pool_id);

        $link_id = $this->ledger()->post_raid_award(1, $pool_id, 300, '10.00');
        $reversal_id = $this->ledger()->reverse($link_id);

        $this->expectException(\RuntimeException::class);
        $this->ledger()->reverse($reversal_id);
    }
```

- [ ] **Step 2: Run, expect PASS**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter dkp_ledger_test
```
Expected: PASS all four ledger tests.

- [ ] **Step 3: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add tests/
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "test: verify reverse() restores subledger balance"
```

### Task 25: Test post_adjustment with both positive and negative amounts

**Files:**
- Modify: `tests/service/dkp_ledger_test.php`

- [ ] **Step 1: Add adjustment tests**

```php
    public function test_post_adjustment_positive_credits_wallet(): void
    {
        $pool_id = 4;
        $player_id = 400;
        $accounts = $this->ledger()->mint_pool_accounts($pool_id);

        $this->ledger()->post_adjustment(1, $pool_id, $player_id, '15.00');

        global $phpbb_container;
        $bbaccounts = $phpbb_container->get('avathar.bbaccounts.service.ledger');
        $this->assertEquals(
            '15.00',
            $bbaccounts->get_subledger_balance_by_character($accounts['player_wallets'], $player_id)
        );
    }

    public function test_post_adjustment_negative_debits_wallet(): void
    {
        $pool_id = 5;
        $player_id = 500;
        $accounts = $this->ledger()->mint_pool_accounts($pool_id);

        $this->ledger()->post_adjustment(1, $pool_id, $player_id, '-25.00');

        global $phpbb_container;
        $bbaccounts = $phpbb_container->get('avathar.bbaccounts.service.ledger');
        $this->assertEquals(
            '-25.00',
            $bbaccounts->get_subledger_balance_by_character($accounts['player_wallets'], $player_id)
        );
    }
```

- [ ] **Step 2: Run, expect PASS**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter dkp_ledger_test
```
Expected: PASS all six tests.

- [ ] **Step 3: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add tests/
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "test: verify post_adjustment for positive and negative amounts"
```

---

## Phase 6 — pool_manager + pool ACP

### Task 26: Define pool_manager interface

**Files:**
- Create: `service/pool_manager_interface.php`

- [ ] **Step 1: Write the interface**

```php
<?php
/**
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace avathar\bbdkp\service;

interface pool_manager_interface
{
    /**
     * Create a new pool, auto-minting its 5 bbAccounts entries.
     *
     * @return int pool_id
     */
    public function create_pool(int $guild_id, string $name, string $desc = ''): int;

    /**
     * Rename / re-describe a pool. Account names stay frozen.
     */
    public function update_pool(int $pool_id, array $fields): void;

    /**
     * Soft-disable a pool (status = 0). Accounts stay; history preserved.
     */
    public function disable_pool(int $pool_id): void;

    /**
     * Hard-delete a pool. Fails if any journal entry references its accounts.
     */
    public function delete_pool(int $pool_id): void;

    /**
     * Set this pool as the default for its guild (exactly-one constraint enforced).
     */
    public function set_default(int $pool_id): void;

    public function list_pools(int $guild_id = 0): array;

    public function get_pool(int $pool_id): ?array;
}
```

- [ ] **Step 2: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add service/pool_manager_interface.php
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: define pool_manager interface"
```

### Task 27: Write failing tests for pool_manager.create_pool

**Files:**
- Create: `tests/service/pool_manager_test.php`

- [ ] **Step 1: Write the test**

```php
<?php
namespace avathar\bbdkp\tests\service;

class pool_manager_test extends \phpbb_database_test_case
{
    protected static function setup_extensions()
    {
        return ['avathar/bbguild', 'avathar/bbaccounts', 'avathar/bbdkp'];
    }

    public function getDataSet()
    {
        return $this->createXMLDataSet(__DIR__ . '/../fixtures/bbdkp_basic.xml');
    }

    protected function pools(): \avathar\bbdkp\service\pool_manager_interface
    {
        global $phpbb_container;
        return $phpbb_container->get('avathar.bbdkp.pool_manager');
    }

    public function test_create_pool_returns_id_and_persists_row(): void
    {
        $id = $this->pools()->create_pool(1, 'Main Raid', 'High-level content');
        $this->assertGreaterThan(0, $id);

        $row = $this->pools()->get_pool($id);
        $this->assertEquals('Main Raid', $row['pool_name']);
        $this->assertEquals(1, $row['guild_id']);
        $this->assertEquals(1, $row['pool_status']);
    }

    public function test_create_pool_mints_bbaccounts_entries(): void
    {
        $id = $this->pools()->create_pool(1, 'Alt Raid');

        global $phpbb_container;
        $bbaccounts = $phpbb_container->get('avathar.bbaccounts.service.ledger');
        $ours = array_values(array_filter(
            $bbaccounts->list_accounts(),
            static fn ($a) => strpos($a['account_code'], "dkp_p{$id}_") === 0
        ));
        $this->assertCount(5, $ours);
    }

    public function test_create_pool_rejects_duplicate_name_in_guild(): void
    {
        $this->pools()->create_pool(1, 'Same Name');
        $this->expectException(\RuntimeException::class);
        $this->pools()->create_pool(1, 'Same Name');
    }

    public function test_create_pool_allows_same_name_in_different_guild(): void
    {
        $a = $this->pools()->create_pool(1, 'Shared Name');
        $b = $this->pools()->create_pool(2, 'Shared Name');
        $this->assertNotEquals($a, $b);
    }
}
```

- [ ] **Step 2: Run, expect failure**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter pool_manager_test
```
Expected: FAIL — service not defined.

### Task 28: Implement pool_manager and register service

**Files:**
- Create: `service/pool_manager.php`
- Modify: `config/services.yml`

- [ ] **Step 1: Write pool_manager.php**

```php
<?php
/**
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace avathar\bbdkp\service;

class pool_manager implements pool_manager_interface
{
    /** @var \phpbb\db\driver\driver_interface */
    private $db;

    /** @var dkp_ledger_interface */
    private $ledger;

    /** @var \phpbb\user */
    private $user;

    /** @var string */
    private $table_pools;

    public function __construct(
        \phpbb\db\driver\driver_interface $db,
        dkp_ledger_interface $ledger,
        \phpbb\user $user,
        string $table_pools
    ) {
        $this->db = $db;
        $this->ledger = $ledger;
        $this->user = $user;
        $this->table_pools = $table_pools;
    }

    public function create_pool(int $guild_id, string $name, string $desc = ''): int
    {
        // unique(guild_id, pool_name) at DB level; pre-check for nicer error
        if ($this->name_in_use($guild_id, $name))
        {
            throw new \RuntimeException("Pool name '{$name}' already exists in guild {$guild_id}");
        }

        $now = time();
        $uid = (int) $this->user->data['user_id'];

        $sql_ary = [
            'guild_id'     => $guild_id,
            'pool_name'    => $name,
            'pool_desc'    => $desc,
            'pool_status'  => 1,
            'pool_default' => 0,
            'added_by'     => $uid,
            'added_at'     => $now,
            'updated_by'   => $uid,
            'updated_at'   => $now,
        ];

        $this->db->sql_query('INSERT INTO ' . $this->table_pools . ' '
            . $this->db->sql_build_array('INSERT', $sql_ary));
        $pool_id = (int) $this->db->sql_nextid();

        $this->ledger->mint_pool_accounts($pool_id);

        return $pool_id;
    }

    public function update_pool(int $pool_id, array $fields): void
    {
        $allowed = ['pool_name', 'pool_desc', 'pool_status'];
        $sql_ary = array_intersect_key($fields, array_flip($allowed));

        if (empty($sql_ary))
        {
            return;
        }

        $sql_ary['updated_at'] = time();
        $sql_ary['updated_by'] = (int) $this->user->data['user_id'];

        $this->db->sql_query('UPDATE ' . $this->table_pools . ' SET '
            . $this->db->sql_build_array('UPDATE', $sql_ary)
            . ' WHERE pool_id = ' . (int) $pool_id);
    }

    public function disable_pool(int $pool_id): void
    {
        $this->update_pool($pool_id, ['pool_status' => 0]);
        $this->ledger->archive_pool_accounts($pool_id);
    }

    public function delete_pool(int $pool_id): void
    {
        // In alpha1, delete_pool_accounts always throws because bbAccounts
        // v1.1.0-alpha has no public account-delete API. The ACP module
        // surfaces this as POOL_DELETE_BLOCKED, prompting the operator to
        // disable instead. A future bbAccounts ticket can lift this
        // restriction; until then, hard-delete is effectively unavailable.
        $this->ledger->delete_pool_accounts($pool_id);

        $this->db->sql_query('DELETE FROM ' . $this->table_pools
            . ' WHERE pool_id = ' . (int) $pool_id);
    }

    public function set_default(int $pool_id): void
    {
        $pool = $this->get_pool($pool_id);
        if (!$pool)
        {
            throw new \RuntimeException("Pool $pool_id not found");
        }

        $this->db->sql_query('UPDATE ' . $this->table_pools
            . ' SET pool_default = 0 WHERE guild_id = ' . (int) $pool['guild_id']);
        $this->db->sql_query('UPDATE ' . $this->table_pools
            . ' SET pool_default = 1 WHERE pool_id = ' . (int) $pool_id);
    }

    public function list_pools(int $guild_id = 0): array
    {
        $sql = 'SELECT * FROM ' . $this->table_pools;
        if ($guild_id > 0)
        {
            $sql .= ' WHERE guild_id = ' . (int) $guild_id;
        }
        $sql .= ' ORDER BY pool_default DESC, pool_name ASC';

        $result = $this->db->sql_query($sql);
        $rows = $this->db->sql_fetchrowset($result);
        $this->db->sql_freeresult($result);
        return $rows;
    }

    public function get_pool(int $pool_id): ?array
    {
        $result = $this->db->sql_query('SELECT * FROM ' . $this->table_pools
            . ' WHERE pool_id = ' . (int) $pool_id);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        return $row ?: null;
    }

    private function name_in_use(int $guild_id, string $name): bool
    {
        $sql = 'SELECT 1 FROM ' . $this->table_pools
            . ' WHERE guild_id = ' . (int) $guild_id
            . " AND pool_name = '" . $this->db->sql_escape($name) . "'";
        $result = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        return (bool) $row;
    }
}
```

- [ ] **Step 2: Register the service**

Append to `config/services.yml`:

```yaml
    avathar.bbdkp.pool_manager:
        class: avathar\bbdkp\service\pool_manager
        arguments:
            - '@dbal.conn'
            - '@avathar.bbdkp.dkp_ledger'
            - '@user'
            - '%tables.bbdkp_pools%'
        public: true
```

- [ ] **Step 3: Lint**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/service/pool_manager.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Run tests, expect PASS**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter pool_manager_test
```
Expected: 4 tests pass.

- [ ] **Step 5: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add service/ config/services.yml
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: implement pool_manager service"
```

### Task 29: Build pool ACP module — list mode

**Files:**
- Modify: `acp/acp_pool_module.php`
- Modify: `adm/style/acp_bbdkp_pool.html`
- Modify: `language/en/info_acp_pool.php`

- [ ] **Step 1: Extend language file**

`language/en/info_acp_pool.php`:

```php
<?php
if (!defined('IN_PHPBB')) { exit; }
if (empty($lang) || !is_array($lang)) { $lang = []; }

$lang = array_merge($lang, [
    'ACP_DKP'                => 'bbDKP',
    'ACP_DKP_POOLS'          => 'DKP Pools',
    'ACP_DKP_POOLS_EXPLAIN'  => 'Define and manage DKP pools (separate point economies). Creating a pool auto-creates 5 accounts in bbAccounts.',

    'POOL_ADD'               => 'Add new pool',
    'POOL_NAME'              => 'Pool name',
    'POOL_DESC'              => 'Description',
    'POOL_STATUS'            => 'Status',
    'POOL_DEFAULT'           => 'Default',
    'POOL_GUILD'             => 'Guild',
    'POOL_ACTIONS'           => 'Actions',
    'POOL_EDIT'              => 'Edit',
    'POOL_DISABLE'           => 'Disable',
    'POOL_DELETE'            => 'Delete',
    'POOL_SET_DEFAULT'       => 'Make default',
    'POOL_ACTIVE'            => 'Active',
    'POOL_INACTIVE'          => 'Disabled',

    'POOL_CREATED'           => 'Pool "%s" created successfully.',
    'POOL_UPDATED'           => 'Pool "%s" updated.',
    'POOL_DISABLED'          => 'Pool "%s" disabled.',
    'POOL_DELETED'           => 'Pool "%s" deleted.',
    'POOL_DEFAULT_SET'       => 'Pool "%s" set as default.',
    'POOL_DELETE_BLOCKED'    => 'Pool hard-delete is not supported in this version of bbDKP — please disable the pool instead.',
    'POOL_DUPLICATE_NAME'    => 'A pool with this name already exists in the selected guild.',

    'POOL_CONFIRM_DELETE'    => 'Are you sure you want to delete this pool? This cannot be undone.',
    'POOL_CONFIRM_DISABLE'   => 'Are you sure you want to disable this pool?',
]);
```

- [ ] **Step 2: Implement acp_pool_module.php**

```php
<?php
/**
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace avathar\bbdkp\acp;

class acp_pool_module
{
    public $u_action;
    public $tpl_name;
    public $page_title;

    public function main($id, $mode)
    {
        global $phpbb_container, $request, $template, $user, $lang;

        $user->add_lang_ext('avathar/bbdkp', ['common', 'info_acp_pool']);

        $pools = $phpbb_container->get('avathar.bbdkp.pool_manager');

        $action = $request->variable('action', '');
        $pool_id = $request->variable('pool_id', 0);

        $this->tpl_name   = 'acp_bbdkp_pool';
        $this->page_title = 'ACP_DKP_POOLS';

        try {
            switch ($action)
            {
                case 'add':
                case 'edit':
                    $this->handle_form($pools, $pool_id, $request, $template, $user);
                    break;

                case 'disable':
                    $pool = $pools->get_pool($pool_id);
                    if ($pool && confirm_box(true))
                    {
                        $pools->disable_pool($pool_id);
                        trigger_error(sprintf($user->lang['POOL_DISABLED'], $pool['pool_name'])
                            . adm_back_link($this->u_action));
                    }
                    elseif ($pool)
                    {
                        confirm_box(false, sprintf($user->lang['POOL_CONFIRM_DISABLE']),
                            build_hidden_fields(['action' => 'disable', 'pool_id' => $pool_id]));
                    }
                    break;

                case 'delete':
                    $pool = $pools->get_pool($pool_id);
                    if ($pool && confirm_box(true))
                    {
                        try {
                            $pools->delete_pool($pool_id);
                            trigger_error(sprintf($user->lang['POOL_DELETED'], $pool['pool_name'])
                                . adm_back_link($this->u_action));
                        } catch (\RuntimeException $e) {
                            trigger_error($user->lang['POOL_DELETE_BLOCKED']
                                . adm_back_link($this->u_action), E_USER_WARNING);
                        }
                    }
                    elseif ($pool)
                    {
                        confirm_box(false, $user->lang['POOL_CONFIRM_DELETE'],
                            build_hidden_fields(['action' => 'delete', 'pool_id' => $pool_id]));
                    }
                    break;

                case 'set_default':
                    $pool = $pools->get_pool($pool_id);
                    if ($pool)
                    {
                        $pools->set_default($pool_id);
                        trigger_error(sprintf($user->lang['POOL_DEFAULT_SET'], $pool['pool_name'])
                            . adm_back_link($this->u_action));
                    }
                    break;
            }
        } catch (\RuntimeException $e) {
            trigger_error($e->getMessage() . adm_back_link($this->u_action), E_USER_WARNING);
        }

        $this->render_list($pools, $template);
        $template->assign_var('U_ACTION', $this->u_action);
    }

    private function render_list($pools, $template): void
    {
        foreach ($pools->list_pools() as $row)
        {
            $template->assign_block_vars('pools', [
                'POOL_ID'    => (int) $row['pool_id'],
                'GUILD_ID'   => (int) $row['guild_id'],
                'NAME'       => $row['pool_name'],
                'DESC'       => $row['pool_desc'],
                'STATUS'     => (int) $row['pool_status'],
                'IS_DEFAULT' => (bool) $row['pool_default'],
                'U_EDIT'     => $this->u_action . '&amp;action=edit&amp;pool_id=' . (int) $row['pool_id'],
                'U_DISABLE'  => $this->u_action . '&amp;action=disable&amp;pool_id=' . (int) $row['pool_id'],
                'U_DELETE'   => $this->u_action . '&amp;action=delete&amp;pool_id=' . (int) $row['pool_id'],
                'U_DEFAULT'  => $this->u_action . '&amp;action=set_default&amp;pool_id=' . (int) $row['pool_id'],
            ]);
        }
    }

    private function handle_form($pools, int $pool_id, $request, $template, $user): void
    {
        $is_edit = $pool_id > 0;
        $existing = $is_edit ? $pools->get_pool($pool_id) : null;

        if ($request->is_set_post('submit'))
        {
            $guild_id = $request->variable('guild_id', 0);
            $name = $request->variable('pool_name', '', true);
            $desc = $request->variable('pool_desc', '', true);

            if ($name === '')
            {
                trigger_error('Name required' . adm_back_link($this->u_action), E_USER_WARNING);
            }

            if ($is_edit)
            {
                $pools->update_pool($pool_id, ['pool_name' => $name, 'pool_desc' => $desc]);
                trigger_error(sprintf($user->lang['POOL_UPDATED'], $name)
                    . adm_back_link($this->u_action));
            }
            else
            {
                $new_id = $pools->create_pool($guild_id, $name, $desc);
                trigger_error(sprintf($user->lang['POOL_CREATED'], $name)
                    . adm_back_link($this->u_action));
            }
        }

        $template->assign_vars([
            'S_FORM'        => true,
            'S_IS_EDIT'     => $is_edit,
            'POOL_NAME'     => $existing['pool_name'] ?? '',
            'POOL_DESC'     => $existing['pool_desc'] ?? '',
            'POOL_GUILD_ID' => $existing['guild_id'] ?? 0,
            'U_BACK'        => $this->u_action,
        ]);
    }
}
```

- [ ] **Step 3: Write the template**

`adm/style/acp_bbdkp_pool.html`:

```html
<!-- INCLUDE overall_header.html -->

<a id="maincontent"></a>

<h1>{L_ACP_DKP_POOLS}</h1>
<p>{L_ACP_DKP_POOLS_EXPLAIN}</p>

<!-- IF S_FORM -->
    <form id="pool_form" method="post" action="{U_ACTION}">
        <fieldset>
            <legend><!-- IF S_IS_EDIT -->{L_POOL_EDIT}<!-- ELSE -->{L_POOL_ADD}<!-- ENDIF --></legend>
            <dl>
                <dt><label for="guild_id">{L_POOL_GUILD}:</label></dt>
                <dd><input type="number" id="guild_id" name="guild_id" value="{POOL_GUILD_ID}" required <!-- IF S_IS_EDIT -->disabled<!-- ENDIF --></dd>
            </dl>
            <dl>
                <dt><label for="pool_name">{L_POOL_NAME}:</label></dt>
                <dd><input type="text" id="pool_name" name="pool_name" value="{POOL_NAME}" maxlength="255" required></dd>
            </dl>
            <dl>
                <dt><label for="pool_desc">{L_POOL_DESC}:</label></dt>
                <dd><input type="text" id="pool_desc" name="pool_desc" value="{POOL_DESC}" maxlength="255"></dd>
            </dl>
            <p class="submit-buttons">
                <input type="submit" name="submit" value="{L_SUBMIT}" class="button1">
                &nbsp; <a href="{U_BACK}">{L_CANCEL}</a>
            </p>
            {S_FORM_TOKEN}
        </fieldset>
    </form>
<!-- ELSE -->
    <p><a href="{U_ACTION}&amp;action=add" class="button1">{L_POOL_ADD}</a></p>

    <table class="table1">
        <thead>
            <tr>
                <th>{L_POOL_NAME}</th>
                <th>{L_POOL_GUILD}</th>
                <th>{L_POOL_DESC}</th>
                <th>{L_POOL_STATUS}</th>
                <th>{L_POOL_DEFAULT}</th>
                <th>{L_POOL_ACTIONS}</th>
            </tr>
        </thead>
        <tbody>
        <!-- BEGIN pools -->
            <tr>
                <td>{pools.NAME}</td>
                <td>{pools.GUILD_ID}</td>
                <td>{pools.DESC}</td>
                <td><!-- IF pools.STATUS -->{L_POOL_ACTIVE}<!-- ELSE -->{L_POOL_INACTIVE}<!-- ENDIF --></td>
                <td><!-- IF pools.IS_DEFAULT -->✓<!-- ENDIF --></td>
                <td>
                    <a href="{pools.U_EDIT}">{L_POOL_EDIT}</a> |
                    <!-- IF not pools.IS_DEFAULT --><a href="{pools.U_DEFAULT}">{L_POOL_SET_DEFAULT}</a> | <!-- ENDIF -->
                    <!-- IF pools.STATUS --><a href="{pools.U_DISABLE}">{L_POOL_DISABLE}</a> | <!-- ENDIF -->
                    <a href="{pools.U_DELETE}">{L_POOL_DELETE}</a>
                </td>
            </tr>
        <!-- BEGINELSE -->
            <tr><td colspan="6"><em>No pools yet.</em></td></tr>
        <!-- END pools -->
        </tbody>
    </table>
<!-- ENDIF -->

<!-- INCLUDE overall_footer.html -->
```

- [ ] **Step 4: Manual ACP smoke test**

In phpBB ACP: bbDKP → DKP Pools.
- Click "Add new pool", fill guild_id=1, name="Smoke Test", submit.
- Confirm row appears in list.
- Confirm 5 entries `dkp_pool_<id>_*` exist in bbAccounts.
- Edit, disable, set-default actions all work without errors.

- [ ] **Step 5: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add acp/ adm/ language/
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: pool ACP module with full CRUD"
```

---

## Phase 7 — event_manager + event ACP

### Task 30: Define event_manager interface

**Files:**
- Create: `service/event_manager_interface.php`

- [ ] **Step 1: Write the interface**

```php
<?php
/**
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace avathar\bbdkp\service;

interface event_manager_interface
{
    /**
     * Create a new event under a pool.
     *
     * @param int $pool_id
     * @param string $name
     * @param string $default_value decimal string, e.g. '50.00'
     * @param string $color hex string with leading #, e.g. '#FFCC00'
     * @param string $icon optional image path
     * @return int event_id
     */
    public function create_event(
        int $pool_id,
        string $name,
        string $default_value = '0.00',
        string $color = '',
        string $icon = ''
    ): int;

    public function update_event(int $event_id, array $fields): void;

    /** Soft-disable: status = 0. Events stay queryable for historical raids. */
    public function disable_event(int $event_id): void;

    /** Hard-delete. Fails if any raids reference this event. */
    public function delete_event(int $event_id): void;

    /** @return array<int, array> events filtered by pool_id (0 = all) */
    public function list_events(int $pool_id = 0): array;

    public function get_event(int $event_id): ?array;
}
```

- [ ] **Step 2: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add service/event_manager_interface.php
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: define event_manager interface"
```

### Task 31: Write failing tests for event_manager

**Files:**
- Create: `tests/service/event_manager_test.php`

- [ ] **Step 1: Write the tests**

```php
<?php
namespace avathar\bbdkp\tests\service;

class event_manager_test extends \phpbb_database_test_case
{
    protected static function setup_extensions()
    {
        return ['avathar/bbguild', 'avathar/bbaccounts', 'avathar/bbdkp'];
    }

    public function getDataSet()
    {
        return $this->createXMLDataSet(__DIR__ . '/../fixtures/bbdkp_basic.xml');
    }

    protected function events(): \avathar\bbdkp\service\event_manager_interface
    {
        global $phpbb_container;
        return $phpbb_container->get('avathar.bbdkp.event_manager');
    }

    protected function pools(): \avathar\bbdkp\service\pool_manager_interface
    {
        global $phpbb_container;
        return $phpbb_container->get('avathar.bbdkp.pool_manager');
    }

    public function test_create_event_persists_with_defaults(): void
    {
        $pool_id = $this->pools()->create_pool(1, 'P-EV1');

        $id = $this->events()->create_event($pool_id, 'Onyxia', '50.00');
        $this->assertGreaterThan(0, $id);

        $row = $this->events()->get_event($id);
        $this->assertEquals('Onyxia', $row['event_name']);
        $this->assertEquals('50.00', $row['event_value']);
        $this->assertEquals(1, $row['event_status']);
        $this->assertEquals($pool_id, $row['pool_id']);
    }

    public function test_list_events_filters_by_pool(): void
    {
        $p1 = $this->pools()->create_pool(1, 'P-EV2');
        $p2 = $this->pools()->create_pool(1, 'P-EV3');

        $this->events()->create_event($p1, 'A', '10');
        $this->events()->create_event($p1, 'B', '10');
        $this->events()->create_event($p2, 'C', '10');

        $this->assertCount(2, $this->events()->list_events($p1));
        $this->assertCount(1, $this->events()->list_events($p2));
        $this->assertCount(3, $this->events()->list_events(0));
    }

    public function test_disable_event_sets_status_zero(): void
    {
        $p = $this->pools()->create_pool(1, 'P-EV4');
        $e = $this->events()->create_event($p, 'X', '20');

        $this->events()->disable_event($e);
        $this->assertEquals(0, $this->events()->get_event($e)['event_status']);
    }
}
```

- [ ] **Step 2: Run, expect failure**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter event_manager_test
```
Expected: FAIL — service not defined.

### Task 32: Implement event_manager

**Files:**
- Create: `service/event_manager.php`
- Modify: `config/services.yml`

- [ ] **Step 1: Write event_manager.php**

```php
<?php
/**
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace avathar\bbdkp\service;

class event_manager implements event_manager_interface
{
    /** @var \phpbb\db\driver\driver_interface */
    private $db;

    /** @var \phpbb\user */
    private $user;

    /** @var string */
    private $table_events;

    /** @var string */
    private $table_raids;

    public function __construct(
        \phpbb\db\driver\driver_interface $db,
        \phpbb\user $user,
        string $table_events,
        string $table_raids
    ) {
        $this->db = $db;
        $this->user = $user;
        $this->table_events = $table_events;
        $this->table_raids = $table_raids;
    }

    public function create_event(
        int $pool_id,
        string $name,
        string $default_value = '0.00',
        string $color = '',
        string $icon = ''
    ): int {
        $now = time();
        $uid = (int) $this->user->data['user_id'];

        $sql_ary = [
            'pool_id'      => $pool_id,
            'event_name'   => $name,
            'event_value'  => $default_value,
            'event_color'  => $color,
            'event_icon'   => $icon,
            'event_status' => 1,
            'added_by'     => $uid,
            'added_at'     => $now,
            'updated_by'   => $uid,
            'updated_at'   => $now,
        ];

        $this->db->sql_query('INSERT INTO ' . $this->table_events . ' '
            . $this->db->sql_build_array('INSERT', $sql_ary));

        return (int) $this->db->sql_nextid();
    }

    public function update_event(int $event_id, array $fields): void
    {
        $allowed = ['event_name', 'event_value', 'event_color', 'event_icon', 'event_status'];
        $sql_ary = array_intersect_key($fields, array_flip($allowed));

        if (empty($sql_ary))
        {
            return;
        }

        $sql_ary['updated_at'] = time();
        $sql_ary['updated_by'] = (int) $this->user->data['user_id'];

        $this->db->sql_query('UPDATE ' . $this->table_events . ' SET '
            . $this->db->sql_build_array('UPDATE', $sql_ary)
            . ' WHERE event_id = ' . (int) $event_id);
    }

    public function disable_event(int $event_id): void
    {
        $this->update_event($event_id, ['event_status' => 0]);
    }

    public function delete_event(int $event_id): void
    {
        // Fail if any raid references this event
        $sql = 'SELECT 1 FROM ' . $this->table_raids
            . ' WHERE event_id = ' . (int) $event_id;
        $result = $this->db->sql_query_limit($sql, 1);
        $has_raids = (bool) $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if ($has_raids)
        {
            throw new \RuntimeException("Cannot delete event $event_id — it has raids referencing it. Disable instead.");
        }

        $this->db->sql_query('DELETE FROM ' . $this->table_events
            . ' WHERE event_id = ' . (int) $event_id);
    }

    public function list_events(int $pool_id = 0): array
    {
        $sql = 'SELECT * FROM ' . $this->table_events;
        if ($pool_id > 0)
        {
            $sql .= ' WHERE pool_id = ' . (int) $pool_id;
        }
        $sql .= ' ORDER BY event_status DESC, event_name ASC';

        $result = $this->db->sql_query($sql);
        $rows = $this->db->sql_fetchrowset($result);
        $this->db->sql_freeresult($result);
        return $rows;
    }

    public function get_event(int $event_id): ?array
    {
        $result = $this->db->sql_query('SELECT * FROM ' . $this->table_events
            . ' WHERE event_id = ' . (int) $event_id);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        return $row ?: null;
    }
}
```

- [ ] **Step 2: Register service**

Append to `config/services.yml`:

```yaml
    avathar.bbdkp.event_manager:
        class: avathar\bbdkp\service\event_manager
        arguments:
            - '@dbal.conn'
            - '@user'
            - '%tables.bbdkp_events%'
            - '%tables.bbdkp_raids%'
        public: true
```

- [ ] **Step 3: Lint**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/service/event_manager.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 4: Run tests, expect PASS**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --filter event_manager_test
```
Expected: 3 tests pass.

- [ ] **Step 5: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add service/ config/services.yml
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: implement event_manager service"
```

### Task 33: Build event ACP module — full CRUD

**Files:**
- Modify: `acp/acp_event_module.php`
- Modify: `adm/style/acp_bbdkp_event.html`
- Modify: `language/en/info_acp_event.php`

- [ ] **Step 1: Extend language file**

`language/en/info_acp_event.php`:

```php
<?php
if (!defined('IN_PHPBB')) { exit; }
if (empty($lang) || !is_array($lang)) { $lang = []; }

$lang = array_merge($lang, [
    'ACP_DKP_EVENTS'         => 'DKP Events',
    'ACP_DKP_EVENTS_EXPLAIN' => 'Raid encounter types and their default DKP values. Each event belongs to one DKP pool.',

    'EVENT_ADD'              => 'Add new event',
    'EVENT_NAME'             => 'Name',
    'EVENT_VALUE'            => 'Default DKP value',
    'EVENT_COLOR'            => 'Color',
    'EVENT_ICON'             => 'Icon',
    'EVENT_POOL'             => 'Pool',
    'EVENT_STATUS'           => 'Status',
    'EVENT_ACTIONS'          => 'Actions',
    'EVENT_EDIT'             => 'Edit',
    'EVENT_DISABLE'          => 'Disable',
    'EVENT_DELETE'           => 'Delete',
    'EVENT_ACTIVE'           => 'Active',
    'EVENT_INACTIVE'         => 'Disabled',

    'EVENT_CREATED'          => 'Event "%s" created.',
    'EVENT_UPDATED'          => 'Event "%s" updated.',
    'EVENT_DISABLED'         => 'Event "%s" disabled.',
    'EVENT_DELETED'          => 'Event "%s" deleted.',
    'EVENT_DELETE_BLOCKED'   => 'Cannot delete event — raids still reference it.',

    'EVENT_CONFIRM_DELETE'   => 'Are you sure you want to delete this event?',
    'EVENT_CONFIRM_DISABLE'  => 'Are you sure you want to disable this event?',

    'FILTER_BY_POOL'         => 'Filter by pool',
    'ALL_POOLS'              => 'All pools',
]);
```

- [ ] **Step 2: Implement acp_event_module.php**

```php
<?php
/**
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace avathar\bbdkp\acp;

class acp_event_module
{
    public $u_action;
    public $tpl_name;
    public $page_title;

    public function main($id, $mode)
    {
        global $phpbb_container, $request, $template, $user;

        $user->add_lang_ext('avathar/bbdkp', ['common', 'info_acp_event']);

        $events = $phpbb_container->get('avathar.bbdkp.event_manager');
        $pools  = $phpbb_container->get('avathar.bbdkp.pool_manager');

        $action = $request->variable('action', '');
        $event_id = $request->variable('event_id', 0);
        $filter_pool = $request->variable('filter_pool', 0);

        $this->tpl_name   = 'acp_bbdkp_event';
        $this->page_title = 'ACP_DKP_EVENTS';

        try {
            switch ($action)
            {
                case 'add':
                case 'edit':
                    $this->handle_form($events, $pools, $event_id, $request, $template, $user);
                    break;

                case 'disable':
                    $ev = $events->get_event($event_id);
                    if ($ev && confirm_box(true))
                    {
                        $events->disable_event($event_id);
                        trigger_error(sprintf($user->lang['EVENT_DISABLED'], $ev['event_name'])
                            . adm_back_link($this->u_action));
                    }
                    elseif ($ev)
                    {
                        confirm_box(false, $user->lang['EVENT_CONFIRM_DISABLE'],
                            build_hidden_fields(['action' => 'disable', 'event_id' => $event_id]));
                    }
                    break;

                case 'delete':
                    $ev = $events->get_event($event_id);
                    if ($ev && confirm_box(true))
                    {
                        try {
                            $events->delete_event($event_id);
                            trigger_error(sprintf($user->lang['EVENT_DELETED'], $ev['event_name'])
                                . adm_back_link($this->u_action));
                        } catch (\RuntimeException $e) {
                            trigger_error($user->lang['EVENT_DELETE_BLOCKED']
                                . adm_back_link($this->u_action), E_USER_WARNING);
                        }
                    }
                    elseif ($ev)
                    {
                        confirm_box(false, $user->lang['EVENT_CONFIRM_DELETE'],
                            build_hidden_fields(['action' => 'delete', 'event_id' => $event_id]));
                    }
                    break;
            }
        } catch (\RuntimeException $e) {
            trigger_error($e->getMessage() . adm_back_link($this->u_action), E_USER_WARNING);
        }

        $this->render_list($events, $pools, $template, $filter_pool);
        $template->assign_vars([
            'U_ACTION'    => $this->u_action,
            'FILTER_POOL' => $filter_pool,
        ]);
    }

    private function render_list($events, $pools, $template, int $filter_pool): void
    {
        // Pool list for filter dropdown and lookup
        $pool_map = [];
        foreach ($pools->list_pools() as $p)
        {
            $pool_map[(int) $p['pool_id']] = $p['pool_name'];
            $template->assign_block_vars('pool_options', [
                'ID'       => (int) $p['pool_id'],
                'NAME'     => $p['pool_name'],
                'SELECTED' => $filter_pool === (int) $p['pool_id'],
            ]);
        }

        foreach ($events->list_events($filter_pool) as $row)
        {
            $pid = (int) $row['pool_id'];
            $template->assign_block_vars('events', [
                'EVENT_ID'   => (int) $row['event_id'],
                'NAME'       => $row['event_name'],
                'VALUE'      => $row['event_value'],
                'COLOR'      => $row['event_color'],
                'ICON'       => $row['event_icon'],
                'POOL_NAME'  => $pool_map[$pid] ?? '?',
                'STATUS'     => (int) $row['event_status'],
                'U_EDIT'     => $this->u_action . '&amp;action=edit&amp;event_id=' . (int) $row['event_id'],
                'U_DISABLE'  => $this->u_action . '&amp;action=disable&amp;event_id=' . (int) $row['event_id'],
                'U_DELETE'   => $this->u_action . '&amp;action=delete&amp;event_id=' . (int) $row['event_id'],
            ]);
        }
    }

    private function handle_form($events, $pools, int $event_id, $request, $template, $user): void
    {
        $is_edit = $event_id > 0;
        $existing = $is_edit ? $events->get_event($event_id) : null;

        if ($request->is_set_post('submit'))
        {
            $pool_id = $request->variable('pool_id', 0);
            $name = $request->variable('event_name', '', true);
            $value = $request->variable('event_value', '0.00');
            $color = $request->variable('event_color', '');
            $icon = $request->variable('event_icon', '');

            if ($name === '')
            {
                trigger_error('Name required' . adm_back_link($this->u_action), E_USER_WARNING);
            }

            if ($is_edit)
            {
                $events->update_event($event_id, [
                    'event_name'  => $name,
                    'event_value' => $value,
                    'event_color' => $color,
                    'event_icon'  => $icon,
                ]);
                trigger_error(sprintf($user->lang['EVENT_UPDATED'], $name)
                    . adm_back_link($this->u_action));
            }
            else
            {
                $events->create_event($pool_id, $name, $value, $color, $icon);
                trigger_error(sprintf($user->lang['EVENT_CREATED'], $name)
                    . adm_back_link($this->u_action));
            }
        }

        foreach ($pools->list_pools() as $p)
        {
            $template->assign_block_vars('form_pool_options', [
                'ID'       => (int) $p['pool_id'],
                'NAME'     => $p['pool_name'],
                'SELECTED' => isset($existing['pool_id']) && (int) $existing['pool_id'] === (int) $p['pool_id'],
            ]);
        }

        $template->assign_vars([
            'S_FORM'           => true,
            'S_IS_EDIT'        => $is_edit,
            'EVENT_NAME'       => $existing['event_name'] ?? '',
            'EVENT_VALUE'      => $existing['event_value'] ?? '0.00',
            'EVENT_COLOR'      => $existing['event_color'] ?? '',
            'EVENT_ICON'       => $existing['event_icon'] ?? '',
            'U_BACK'           => $this->u_action,
        ]);
    }
}
```

- [ ] **Step 3: Write the template**

`adm/style/acp_bbdkp_event.html`:

```html
<!-- INCLUDE overall_header.html -->

<a id="maincontent"></a>

<h1>{L_ACP_DKP_EVENTS}</h1>
<p>{L_ACP_DKP_EVENTS_EXPLAIN}</p>

<!-- IF S_FORM -->
    <form id="event_form" method="post" action="{U_ACTION}">
        <fieldset>
            <legend><!-- IF S_IS_EDIT -->{L_EVENT_EDIT}<!-- ELSE -->{L_EVENT_ADD}<!-- ENDIF --></legend>
            <dl>
                <dt><label for="pool_id">{L_EVENT_POOL}:</label></dt>
                <dd>
                    <select name="pool_id" id="pool_id" <!-- IF S_IS_EDIT -->disabled<!-- ENDIF --> required>
                    <!-- BEGIN form_pool_options -->
                        <option value="{form_pool_options.ID}" <!-- IF form_pool_options.SELECTED -->selected<!-- ENDIF -->>{form_pool_options.NAME}</option>
                    <!-- END form_pool_options -->
                    </select>
                </dd>
            </dl>
            <dl>
                <dt><label for="event_name">{L_EVENT_NAME}:</label></dt>
                <dd><input type="text" id="event_name" name="event_name" value="{EVENT_NAME}" maxlength="255" required></dd>
            </dl>
            <dl>
                <dt><label for="event_value">{L_EVENT_VALUE}:</label></dt>
                <dd><input type="number" id="event_value" name="event_value" value="{EVENT_VALUE}" step="0.01" min="0"></dd>
            </dl>
            <dl>
                <dt><label for="event_color">{L_EVENT_COLOR}:</label></dt>
                <dd><input type="text" id="event_color" name="event_color" value="{EVENT_COLOR}" maxlength="8" placeholder="#RRGGBB"></dd>
            </dl>
            <dl>
                <dt><label for="event_icon">{L_EVENT_ICON}:</label></dt>
                <dd><input type="text" id="event_icon" name="event_icon" value="{EVENT_ICON}" maxlength="255"></dd>
            </dl>
            <p class="submit-buttons">
                <input type="submit" name="submit" value="{L_SUBMIT}" class="button1">
                &nbsp; <a href="{U_BACK}">{L_CANCEL}</a>
            </p>
            {S_FORM_TOKEN}
        </fieldset>
    </form>
<!-- ELSE -->
    <p><a href="{U_ACTION}&amp;action=add" class="button1">{L_EVENT_ADD}</a></p>

    <form method="get" action="{U_ACTION}">
        <label for="filter_pool">{L_FILTER_BY_POOL}:</label>
        <select id="filter_pool" name="filter_pool" onchange="this.form.submit()">
            <option value="0">{L_ALL_POOLS}</option>
        <!-- BEGIN pool_options -->
            <option value="{pool_options.ID}" <!-- IF pool_options.SELECTED -->selected<!-- ENDIF -->>{pool_options.NAME}</option>
        <!-- END pool_options -->
        </select>
    </form>

    <table class="table1">
        <thead>
            <tr>
                <th>{L_EVENT_NAME}</th>
                <th>{L_EVENT_POOL}</th>
                <th>{L_EVENT_VALUE}</th>
                <th>{L_EVENT_STATUS}</th>
                <th>{L_EVENT_ACTIONS}</th>
            </tr>
        </thead>
        <tbody>
        <!-- BEGIN events -->
            <tr>
                <td>{events.NAME}</td>
                <td>{events.POOL_NAME}</td>
                <td>{events.VALUE}</td>
                <td><!-- IF events.STATUS -->{L_EVENT_ACTIVE}<!-- ELSE -->{L_EVENT_INACTIVE}<!-- ENDIF --></td>
                <td>
                    <a href="{events.U_EDIT}">{L_EVENT_EDIT}</a> |
                    <!-- IF events.STATUS --><a href="{events.U_DISABLE}">{L_EVENT_DISABLE}</a> | <!-- ENDIF -->
                    <a href="{events.U_DELETE}">{L_EVENT_DELETE}</a>
                </td>
            </tr>
        <!-- BEGINELSE -->
            <tr><td colspan="5"><em>No events yet.</em></td></tr>
        <!-- END events -->
        </tbody>
    </table>
<!-- ENDIF -->

<!-- INCLUDE overall_footer.html -->
```

- [ ] **Step 4: Manual ACP smoke test**

In phpBB ACP: bbDKP → DKP Events.
- Add new event, select pool, name "Onyxia", value "50.00", submit.
- Confirm row appears.
- Edit, disable, delete actions work.
- Filter by pool works.

- [ ] **Step 5: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add acp/ adm/ language/
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "feat: event ACP module with full CRUD"
```

---

## Phase 8 — Documentation + alpha1 release prep

### Task 34: Write README.md

**Files:**
- Create: `README.md`

- [ ] **Step 1: Write the README**

```markdown
# bbDKP — DKP Points System for phpBB 3.3

bbDKP is a Dragon Kill Points (DKP) extension for phpBB 3.3, integrating with
[bbGuild](https://github.com/avatharbe/bbguild) for guild/character management
and [bbAccounts](https://github.com/avatharbe/bbAccounts) for canonical
double-entry point storage.

This is a ground-up rewrite of the 2016 bbDKPMOD v1.4.6 (phpBB 3.0 MOD), now
modelling DKP transactions as journal entries in a real accounting ledger
instead of denormalised per-player balance columns.

## Status

**v2.0.0-alpha1** — foundation only. Pool and event management work; raids,
loot, and adjustments land in subsequent alphas.

See `contrib/specs/2026-05-23-bbdkp-v2-design.md` for the full design.

## Requirements

- phpBB 3.3.11 or higher
- PHP 8.1+
- avathar/bbguild ≥ 2.0.0-b1
- avathar/bbaccounts ≥ 1.0.0

## Installation

1. Place this extension at `ext/avathar/bbdkp/`.
2. In phpBB ACP: Customise → Manage extensions → bbDKP → Enable.
3. If the enable button is greyed out, check that bbGuild and bbAccounts are
   installed and enabled at the required versions.

## License

GPL-2.0-only. See `license.txt`.
```

- [ ] **Step 2: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add README.md
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "docs: add README"
```

### Task 35: Write CHANGELOG.md

**Files:**
- Create: `CHANGELOG.md`

- [ ] **Step 1: Write the changelog**

```markdown
# Changelog

All notable changes to bbDKP v2.x will be documented in this file. Format
follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [2.0.0-alpha1] — 2026-05-23

### Added
- Initial scaffolding: composer.json, ext.php with dependency check.
- All v2.0 schema tables in a single squashed install migration:
  pools, events, raids, raid_attendees, items, loot, adjustments,
  adjustment_recipients, ledger_link.
- Four permissions (`a_bbdkp`, `m_bbdkp`, `u_bbdkp_view`, `u_bbdkp_view_others`).
- ACP_DKP category registered under bbGuild's `ACP_BBGUILD_MAINPAGE`.
- `dkp_ledger` service — the single writer to bbAccounts. Mints 5 accounts
  per pool, posts balanced journal entries for raid awards, loot purchases,
  and adjustments. Supports reversal via immutable reversal entries.
- `pool_manager` service + ACP module with full CRUD, including
  enable/disable, set-default, and delete-with-guard against existing
  journal entries.
- `event_manager` service + ACP module with full CRUD and pool filter.

### Notes
- v1.4.6 bbDKPMOD data is NOT migrated. Clean-slate install only.
- Raid, loot, and adjustment ACPs are deferred to v2.0.0-alpha2 and -alpha3.
- Front-end pages and UCP "My DKP" are deferred to v2.0.0-beta1 and -beta2.

[2.0.0-alpha1]: https://github.com/avatharbe/bbDKP/releases/tag/v2.0.0-alpha1
```

- [ ] **Step 2: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add CHANGELOG.md
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "docs: add CHANGELOG for alpha1"
```

### Task 36: Add license.txt

**Files:**
- Create: `license.txt`

- [ ] **Step 1: Copy GPL-2.0 text**

Run:
```bash
curl -fsSL "https://www.gnu.org/licenses/old-licenses/gpl-2.0.txt" \
  -o /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/license.txt
```
Expected: file exists, ~18 KB.

- [ ] **Step 2: Verify**

Run:
```bash
head -3 /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/license.txt
```
Expected: starts with "GNU GENERAL PUBLIC LICENSE".

- [ ] **Step 3: Commit**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add license.txt
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "docs: add GPL-2.0 license"
```

### Task 37: Apply phpBB coding standards check

**Files:** none (CI check)

- [ ] **Step 1: Run phpcs against the working copy**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && \
  ./phpBB/vendor/bin/phpcs --standard=./phpBB/build/code_sniffer/ruleset.xml \
  --extensions=php ext/avathar/bbdkp
```
Expected: 0 errors.

If errors: fix per feedback memory `feedback_phpbb_coding_standards.md`
(BSD/Allman braces, tabs-only indentation, type-hint spacing).

- [ ] **Step 2: Commit any fixes**

```bash
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp add .
git -C /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp commit -m "chore: phpcs cleanup"
```

### Task 38: Run full test suite

**Files:** none (verification)

- [ ] **Step 1: Run all bbDKP tests**

Run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit --group=bbdkp 2>&1 | tee /tmp/bbdkp-test.log
```

If group tagging hasn't been done, run:
```bash
cd /Users/Andreas/Sites/avathar/forum && phpunit \
  ext/avathar/bbdkp/tests 2>&1 | tee /tmp/bbdkp-test.log
```
Expected: 100% pass.

- [ ] **Step 2: Confirm coverage of the four schema tests, three permissions test, six dkp_ledger tests, four pool_manager tests, three event_manager tests, and one install_uninstall test**

Run:
```bash
grep -E "^OK|tests, " /tmp/bbdkp-test.log
```
Expected: at least 21 tests reported.

### Task 39: Sync working copy to git repo and tag alpha1

**Files:** none (deployment)

- [ ] **Step 1: Rsync working copy → repo dir**

Run:
```bash
rsync -a --delete \
  --exclude='.git' \
  --exclude='.DS_Store' \
  /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/ \
  /Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP/
```

- [ ] **Step 2: Inspect repo before pushing**

Run:
```bash
cd /Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP \
  && git status && git log --oneline -10
```
Expected: clean tree (changes already committed in the working copy's history).
Note: if the working-copy `.git` was a fresh init, the commit log won't match
the upstream repo. In that case, this rsync is a "first content" push — review
carefully and decide whether to use a single squashed commit or replay the
working-copy history into the upstream repo.

- [ ] **Step 3: Tag and push**

Run:
```bash
cd /Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP \
  && git tag v2.0.0-alpha1 \
  && git push origin main \
  && git push origin v2.0.0-alpha1
```
Expected: tag pushed to GitHub. Verify at
`https://github.com/avatharbe/bbDKP/releases/tag/v2.0.0-alpha1`.

---

## Spec coverage check (self-review after writing the plan)

This plan covers the following spec sections completely:

- §1 Summary — extension exists with the right architecture (Tasks 3–7).
- §2 Goals — alpha1 in-scope: scaffolding ✓, schema ✓, pool/event management ✓.
- §3 Decisions D1, D2, D3, D4, D7, D8, D12 implemented in Tasks 9–22, 28.
- §3 Decisions D5, D6, D14 are scope statements honored throughout.
- §4 Architecture — services + tables + posting rule all built (Tasks 20–32).
- §5 Data model §5.1–§5.9 — every table created (Tasks 10–15).
- §6.1 Chart of accounts — minted by `dkp_ledger` (Task 22).
- §6.2 Posting verbs — all three posting + reverse (Tasks 22–25).
- §6.3 Edit flow — reverse + repost pattern testable via reverse() tests.
- §6.6 Prerequisites — checked in Phase 0.
- §7.1 ACP modules — pool + event registered (Tasks 18, 19, 29, 33).
- §7.4 Permissions — all four registered (Task 16).
- §8 Files & folders — created across the plan.
- §10 Migrations — single squashed install (Tasks 9–18).
- §11 Testing strategy — service-layer + functional tests added throughout.

Spec sections deferred to later plans (alpha2+):

- §5.3–§5.4 raid/attendee write paths (alpha2).
- §5.5–§5.6 loot write paths (alpha3).
- §5.7–§5.8 adjustment write paths (alpha2).
- §6.4 standings query implementation (beta1 reads).
- §6.5 per-player history page (beta2 UCP).
- §7.2 UCP module (beta2).
- §7.3 front-end routes (beta1).
- §7.5 log type registration (alpha2 — paired with raid/loot/adjustment CRUD).
- §9 item seed extension point (alpha3 — paired with item catalog).
- §12 release plan — alpha1 is THIS plan; subsequent plans cover the rest.

No spec requirement is left unaddressed by this plan or a clearly identified later one.
