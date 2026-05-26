# bbDKP v2.0.0-alpha2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `avathar/bbdkp` v2.0.0-alpha2 — adds raid CRUD with attendance, adjustment CRUD with bulk-add, and bbDKP log type registration (with backfilled log calls in alpha1 pool/event services).

**Architecture:** Two new services (`raid_manager`, `adjustment_manager`) compose alpha1's `dkp_ledger` primitives. Two new ACP modules (`acp_raid_module`, `acp_adjustment_module`). One new event subscriber (`log_listener`) registers log type translations on `core.user_setup`. Every CRUD method writes to `$phpbb_log->add()`. No schema migration (alpha1 covered all tables); one data migration registers the two new ACP modes.

**Tech Stack:** phpBB 3.3.x, PHP 8.1+, Symfony DI 3.x, MySQL/MariaDB primary.

**Spec:** `contrib/specs/2026-05-24-bbdkp-v2-alpha2-design.md`

**Git workflow:** Edit in working copy `/Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/`; rsync to dev repo `/Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP/` and commit there. Every phase ends with a sync + commit. Memory `feedback_rt_working_copy.md` documents this workflow.

**Coding standard:** BSD/Allman braces, tabs only for indent, GPL-2.0 file header matching alpha1 services. No tests are written in this plan (deferred per spec §11; local phpBB lacks phpunit framework).

---

## File structure (created in this plan)

```
ext/avathar/bbdkp/
├── event/
│   └── log_listener.php                       NEW
├── exception/
│   ├── raid_state_exception.php               NEW
│   └── adjustment_state_exception.php         NEW
├── service/
│   ├── raid_manager_interface.php             NEW
│   ├── raid_manager.php                       NEW
│   ├── adjustment_manager_interface.php       NEW
│   ├── adjustment_manager.php                 NEW
│   ├── pool_manager.php                       EDIT  (add log calls + log_interface dep)
│   └── event_manager.php                      EDIT  (add log calls + log_interface dep)
├── acp/
│   ├── acp_raid_info.php                      NEW
│   ├── acp_raid_module.php                    NEW
│   ├── acp_adjustment_info.php                NEW
│   └── acp_adjustment_module.php              NEW
├── adm/style/
│   ├── acp_bbdkp_raid.html                    NEW
│   └── acp_bbdkp_adjustment.html              NEW
├── language/en/
│   ├── logs.php                               NEW
│   ├── info_acp_raid.php                      NEW
│   ├── info_acp_adjustment.php                NEW
│   └── common.php                             EDIT  (add raid/adj action message keys)
├── migrations/
│   └── v200a2/
│       └── bbdkp_v2_alpha2.php                NEW
├── config/
│   └── services.yml                           EDIT  (inject @log + @user where needed; register new services + listener)
├── CHANGELOG.md                               EDIT  (alpha2 entry)
└── composer.json                              EDIT  (version → 2.0.0-alpha2; time bump)
```

---

## Phase 1 — Log type registration + alpha1 backfill

This phase makes every CRUD action — including alpha1's existing pool and event flows — write a `phpbb_log` row with a bbDKP log type. Goal: when alpha2 ends, the moderation log accurately reflects every admin action since enable.

### Task 1: Create `language/en/logs.php`

**Files:**
- Create: `ext/avathar/bbdkp/language/en/logs.php`

- [ ] **Step 1: Create the file with all bbDKP log type translations**

Run:
```bash
ls /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/language/en
```
Expected: shows `common.php`, `info_acp_event.php`, `info_acp_pool.php`, `permissions_bbdkp.php`.

Write file `ext/avathar/bbdkp/language/en/logs.php`:
```php
<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

$lang = array_merge($lang, [
	'LOG_DKPSYS_ADDED'      => '<strong>bbDKP</strong>: added DKP pool « %s »',
	'LOG_DKPSYS_UPDATED'    => '<strong>bbDKP</strong>: updated DKP pool « %s »',
	'LOG_DKPSYS_DELETED'    => '<strong>bbDKP</strong>: deleted DKP pool « %s »',
	'LOG_EVENT_ADDED'       => '<strong>bbDKP</strong>: added event « %s »',
	'LOG_EVENT_UPDATED'     => '<strong>bbDKP</strong>: updated event « %s »',
	'LOG_EVENT_DELETED'     => '<strong>bbDKP</strong>: deleted event « %s »',
	'LOG_RAID_ADDED'        => '<strong>bbDKP</strong>: added raid « %s » with %d attendees',
	'LOG_RAID_UPDATED'      => '<strong>bbDKP</strong>: updated raid #%d',
	'LOG_RAID_DELETED'      => '<strong>bbDKP</strong>: deleted raid #%d',
	'LOG_INDIVADJ_ADDED'    => '<strong>bbDKP</strong>: added adjustment « %s » for %d recipient(s)',
	'LOG_INDIVADJ_UPDATED'  => '<strong>bbDKP</strong>: updated adjustment #%d',
	'LOG_INDIVADJ_DELETED'  => '<strong>bbDKP</strong>: deleted adjustment #%d',
	'LOG_PLAYERDKP_UPDATED' => '<strong>bbDKP</strong>: updated DKP for player #%d (raid #%d, %s)',
]);
```

- [ ] **Step 2: Verify file parses**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/language/en/logs.php
```
Expected: `No syntax errors detected`.

### Task 2: Create the `log_listener` event subscriber

**Files:**
- Create: `ext/avathar/bbdkp/event/log_listener.php`

- [ ] **Step 1: Create the listener class**

Write file `ext/avathar/bbdkp/event/log_listener.php`:
```php
<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Loads bbDKP log type translations so phpBB's mod log renders them
 * by name. Registered as a Symfony event subscriber and wired via
 * services.yml with the `kernel.event_subscriber` tag.
 */
class log_listener implements EventSubscriberInterface
{
	/** @var \phpbb\user */
	private $user;

	public function __construct(\phpbb\user $user)
	{
		$this->user = $user;
	}

	public static function getSubscribedEvents()
	{
		return [
			'core.user_setup' => 'load_language',
		];
	}

	public function load_language($event)
	{
		$lang_set_ext = $event['lang_set_ext'];
		$lang_set_ext[] = [
			'ext_name' => 'avathar/bbdkp',
			'lang_set' => 'logs',
		];
		$event['lang_set_ext'] = $lang_set_ext;
	}
}
```

- [ ] **Step 2: Verify lints**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/event/log_listener.php
```
Expected: `No syntax errors detected`.

### Task 3: Register `log_listener` + adjusted `pool_manager` / `event_manager` deps in services.yml

**Files:**
- Modify: `ext/avathar/bbdkp/config/services.yml`

- [ ] **Step 1: Replace the services file with the alpha2 version**

Read current contents:
```bash
cat /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/config/services.yml
```

Replace the entire file `ext/avathar/bbdkp/config/services.yml` with:
```yaml
imports:
    - { resource: tables.yml }

services:
    _defaults:
        autowire: false
        public: false

    avathar.bbdkp.dkp_ledger:
        class: avathar\bbdkp\service\dkp_ledger
        arguments:
            - '@dbal.conn'
            - '@avathar.bbaccounts.service.ledger'
            - '%tables.bbdkp_ledger_link%'
        public: true

    avathar.bbdkp.pool_manager:
        class: avathar\bbdkp\service\pool_manager
        arguments:
            - '@dbal.conn'
            - '@avathar.bbdkp.dkp_ledger'
            - '@user'
            - '@log'
            - '%tables.bbdkp_pools%'
        public: true

    avathar.bbdkp.event_manager:
        class: avathar\bbdkp\service\event_manager
        arguments:
            - '@dbal.conn'
            - '@user'
            - '@log'
            - '%tables.bbdkp_events%'
            - '%tables.bbdkp_raids%'
        public: true

    avathar.bbdkp.raid_manager:
        class: avathar\bbdkp\service\raid_manager
        arguments:
            - '@dbal.conn'
            - '@avathar.bbdkp.dkp_ledger'
            - '@user'
            - '@log'
            - '%tables.bbdkp_raids%'
            - '%tables.bbdkp_raid_attendees%'
            - '%tables.bbdkp_pools%'
            - '%tables.bbdkp_events%'
            - '%tables.bbdkp_ledger_link%'
        public: true

    avathar.bbdkp.adjustment_manager:
        class: avathar\bbdkp\service\adjustment_manager
        arguments:
            - '@dbal.conn'
            - '@avathar.bbdkp.dkp_ledger'
            - '@user'
            - '@log'
            - '%tables.bbdkp_adjustments%'
            - '%tables.bbdkp_adjustment_recipients%'
            - '%tables.bbdkp_pools%'
            - '%tables.bbdkp_ledger_link%'
        public: true

    avathar.bbdkp.event.log_listener:
        class: avathar\bbdkp\event\log_listener
        arguments:
            - '@user'
        tags:
            - { name: event.listener }
```

- [ ] **Step 2: Verify YAML parses**

Run:
```bash
php -r "var_dump(yaml_parse_file('/Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/config/services.yml'));" 2>&1 | head -5
```
If PECL yaml extension isn't loaded, fall back to:
```bash
php -r "require '/Users/Andreas/Sites/avathar/forum/phpBB/vendor/autoload.php'; echo Symfony\Component\Yaml\Yaml::dump(Symfony\Component\Yaml\Yaml::parseFile('/Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/config/services.yml'));" | head -5
```
Expected: prints YAML keys (services key visible). No fatal errors.

### Task 4: Backfill `pool_manager` log calls

**Files:**
- Modify: `ext/avathar/bbdkp/service/pool_manager.php`

- [ ] **Step 1: Add `log_interface` constructor dependency and call sites**

Replace the entire file `ext/avathar/bbdkp/service/pool_manager.php` with:
```php
<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
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

	/** @var \phpbb\log\log_interface */
	private $log;

	/** @var string */
	private $table_pools;

	public function __construct(
		\phpbb\db\driver\driver_interface $db,
		dkp_ledger_interface $ledger,
		\phpbb\user $user,
		\phpbb\log\log_interface $log,
		string $table_pools
	)
	{
		$this->db = $db;
		$this->ledger = $ledger;
		$this->user = $user;
		$this->log = $log;
		$this->table_pools = $table_pools;
	}

	public function create_pool(int $guild_id, string $name, string $desc = ''): int
	{
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

		$this->log->add('admin', $uid, $this->user->ip, 'LOG_DKPSYS_ADDED', false, [$name]);

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

		$now = time();
		$uid = (int) $this->user->data['user_id'];
		$sql_ary['updated_at'] = $now;
		$sql_ary['updated_by'] = $uid;

		$this->db->sql_query('UPDATE ' . $this->table_pools . ' SET '
			. $this->db->sql_build_array('UPDATE', $sql_ary)
			. ' WHERE pool_id = ' . (int) $pool_id);

		$pool = $this->get_pool($pool_id);
		if ($pool)
		{
			$this->log->add('admin', $uid, $this->user->ip, 'LOG_DKPSYS_UPDATED', false, [$pool['pool_name']]);
		}
	}

	public function disable_pool(int $pool_id): void
	{
		$this->update_pool($pool_id, ['pool_status' => 0]);
		$this->ledger->archive_pool_accounts($pool_id);
	}

	public function delete_pool(int $pool_id): void
	{
		$pool = $this->get_pool($pool_id);

		$this->ledger->delete_pool_accounts($pool_id);

		$this->db->sql_query('DELETE FROM ' . $this->table_pools
			. ' WHERE pool_id = ' . (int) $pool_id);

		if ($pool)
		{
			$uid = (int) $this->user->data['user_id'];
			$this->log->add('admin', $uid, $this->user->ip, 'LOG_DKPSYS_DELETED', false, [$pool['pool_name']]);
		}
	}

	public function set_default(int $pool_id): void
	{
		$pool = $this->get_pool($pool_id);
		if (!$pool)
		{
			throw new \RuntimeException("Pool {$pool_id} not found");
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

- [ ] **Step 2: Verify lints**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/service/pool_manager.php
```
Expected: `No syntax errors detected`.

### Task 5: Backfill `event_manager` log calls

**Files:**
- Modify: `ext/avathar/bbdkp/service/event_manager.php`

- [ ] **Step 1: Read existing file to find the disable + delete + list methods**

Run:
```bash
grep -n "public function" /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/service/event_manager.php
```
Expected: lists create_event, update_event, disable_event, delete_event, list_events, get_event.

- [ ] **Step 2: Edit constructor and method bodies**

Apply these edits to `ext/avathar/bbdkp/service/event_manager.php`:

(a) Replace the class properties block (immediately under `class event_manager implements event_manager_interface {`) with:
```php
	/** @var \phpbb\db\driver\driver_interface */
	private $db;

	/** @var \phpbb\user */
	private $user;

	/** @var \phpbb\log\log_interface */
	private $log;

	/** @var string */
	private $table_events;

	/** @var string */
	private $table_raids;
```

(b) Replace the constructor with:
```php
	public function __construct(
		\phpbb\db\driver\driver_interface $db,
		\phpbb\user $user,
		\phpbb\log\log_interface $log,
		string $table_events,
		string $table_raids
	)
	{
		$this->db = $db;
		$this->user = $user;
		$this->log = $log;
		$this->table_events = $table_events;
		$this->table_raids = $table_raids;
	}
```

(c) At the **end** of `create_event` (immediately before `return (int) $this->db->sql_nextid();`), capture the inserted id and add a log call. Replace:
```php
		$this->db->sql_query('INSERT INTO ' . $this->table_events . ' '
			. $this->db->sql_build_array('INSERT', $sql_ary));

		return (int) $this->db->sql_nextid();
```
with:
```php
		$this->db->sql_query('INSERT INTO ' . $this->table_events . ' '
			. $this->db->sql_build_array('INSERT', $sql_ary));
		$event_id = (int) $this->db->sql_nextid();

		$this->log->add('admin', $uid, $this->user->ip, 'LOG_EVENT_ADDED', false, [$name]);

		return $event_id;
```

(d) At the **end** of `update_event` (immediately after the UPDATE SQL), insert:
```php
		$event = $this->get_event($event_id);
		if ($event)
		{
			$this->log->add('admin', (int) $this->user->data['user_id'], $this->user->ip,
				'LOG_EVENT_UPDATED', false, [$event['event_name']]);
		}
```

(e) Replace the entire `delete_event` method with this version that loads the event row before the DELETE (so we can include `event_name` in the log call), uses a language-key exception message instead of the alpha1 hardcoded English string, and writes the log entry after the DELETE succeeds:
```php
	public function delete_event(int $event_id): void
	{
		$event = $this->get_event($event_id);

		// Block delete if any raid references this event — history wins
		// over schema cleanup. Operators can disable the event instead.
		$sql = 'SELECT 1 FROM ' . $this->table_raids
			. ' WHERE event_id = ' . (int) $event_id;
		$result = $this->db->sql_query_limit($sql, 1);
		$has_raids = (bool) $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($has_raids)
		{
			throw new \RuntimeException('EVENT_DELETE_BLOCKED');
		}

		$this->db->sql_query('DELETE FROM ' . $this->table_events
			. ' WHERE event_id = ' . (int) $event_id);

		if ($event)
		{
			$this->log->add('admin', (int) $this->user->data['user_id'], $this->user->ip,
				'LOG_EVENT_DELETED', false, [$event['event_name']]);
		}
	}
```

(f) Add `EVENT_DELETE_BLOCKED` to `language/en/common.php`:
```php
'EVENT_DELETE_BLOCKED' => 'This event cannot be deleted because raids reference it. Disable the event instead.',
```
The ACP module's existing `catch (\RuntimeException $e)` block will render this via `$user->lang[$e->getMessage()]`. The alpha1 code threw with the English text baked into the message — this is a small improvement that lets the message be translated.

- [ ] **Step 3: Verify lints**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/service/event_manager.php
```
Expected: `No syntax errors detected`.

### Task 6: Sync + commit phase 1

- [ ] **Step 1: Sync to dev repo**

Run:
```bash
rsync -a --exclude='.git' --exclude='.DS_Store' \
  /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/ \
  /Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP/
```

- [ ] **Step 2: Commit**

Run:
```bash
cd /Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP \
  && git add -A \
  && git commit -m "feat: log type registration + alpha1 log backfill

Adds language/en/logs.php with 13 bbDKP log type translations
(DKPSYS_*, EVENT_*, RAID_*, INDIVADJ_*, PLAYERDKP_*), an event/log_listener.php
subscriber that loads them on core.user_setup, and inline \$phpbb_log->add()
calls in pool_manager + event_manager so every alpha1 admin action now writes
to phpbb_log. log_interface dependency injected via services.yml."
```
Expected: commit message printed; no errors.

---

## Phase 2 — `raid_manager` service

This phase builds the service that owns raid + attendee CRUD, including the reverse+repost cascade on `raid_value` edits.

### Task 7: Create `raid_state_exception`

**Files:**
- Create: `ext/avathar/bbdkp/exception/raid_state_exception.php`

- [ ] **Step 1: Check whether the exception/ directory exists**

Run:
```bash
ls /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/exception 2>/dev/null \
  || mkdir -p /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/exception
```

- [ ] **Step 2: Create the exception class**

Write `ext/avathar/bbdkp/exception/raid_state_exception.php`:
```php
<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\exception;

/**
 * Thrown by raid_manager when a raid mutation is rejected for a
 * state/validation reason that the user can act on. The exception
 * message is the language key the ACP module will render.
 *
 * Reserved keys:
 *   RAID_NO_ATTENDEES, RAID_DUPLICATE_ATTENDEE, RAID_POOL_DISABLED,
 *   RAID_EVENT_MISMATCH, RAID_UNKNOWN_PLAYER, RAID_NOT_FOUND.
 */
class raid_state_exception extends \RuntimeException
{
}
```

- [ ] **Step 3: Verify lints**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/exception/raid_state_exception.php
```
Expected: `No syntax errors detected`.

### Task 8: Create `raid_manager_interface`

**Files:**
- Create: `ext/avathar/bbdkp/service/raid_manager_interface.php`

- [ ] **Step 1: Create the interface**

Write `ext/avathar/bbdkp/service/raid_manager_interface.php`:
```php
<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\service;

/**
 * Domain service for raid + attendee CRUD.
 *
 * Composes dkp_ledger primitives. Validates input, opens a transaction,
 * writes metadata rows, posts to bbAccounts via dkp_ledger, writes
 * \phpbb_log entries, commits or rolls back. ACP modules never write to
 * bb_dkp_raids or bb_dkp_raid_attendees directly.
 */
interface raid_manager_interface
{
	/**
	 * Create a new raid with N attendees.
	 *
	 * @param int    $guild_id
	 * @param int    $pool_id
	 * @param int    $event_id
	 * @param int    $raid_start   unix seconds
	 * @param int    $raid_end     unix seconds; 0 = point-in-time
	 * @param string $raid_value   decimal string (e.g. '50.00')
	 * @param string $raid_note    BBCode
	 * @param array  $attendees    each: ['player_id' => int, 'value_override' => ?string]
	 *
	 * @return int raid_id
	 *
	 * @throws \avathar\bbdkp\exception\raid_state_exception
	 *         RAID_NO_ATTENDEES, RAID_DUPLICATE_ATTENDEE, RAID_POOL_DISABLED,
	 *         RAID_EVENT_MISMATCH, RAID_UNKNOWN_PLAYER
	 */
	public function create_raid(
		int $guild_id,
		int $pool_id,
		int $event_id,
		int $raid_start,
		int $raid_end,
		string $raid_value,
		string $raid_note,
		array $attendees
	): int;

	/**
	 * Update a raid's metadata.
	 *
	 * Editable: raid_end, raid_note, raid_value, event_id.
	 * raid_start, pool_id, guild_id are intentionally immutable.
	 *
	 * raid_value changes cascade: every attendee with value_override IS NULL
	 * has its prior ledger entry reversed and a new entry posted at the new
	 * amount.
	 *
	 * @throws \avathar\bbdkp\exception\raid_state_exception RAID_NOT_FOUND
	 */
	public function update_raid(int $raid_id, array $fields): void;

	/**
	 * Update one attendee row. Editable: value_override.
	 *
	 * value_override change cascades reverse + post for that one attendee.
	 *
	 * @param array $fields  ['value_override' => ?string]   null clears override
	 */
	public function update_attendee(int $attendee_id, array $fields): void;

	/**
	 * Append attendees to an existing raid. Each is posted on insert.
	 *
	 * @param array $attendees same shape as create_raid
	 * @throws \avathar\bbdkp\exception\raid_state_exception RAID_DUPLICATE_ATTENDEE, RAID_UNKNOWN_PLAYER
	 */
	public function add_attendees(int $raid_id, array $attendees): void;

	/**
	 * Remove attendees from a raid. Each removal reverses the prior posting
	 * and deletes the bb_dkp_raid_attendees row (ledger_link rows persist).
	 *
	 * @param int[] $player_ids
	 */
	public function remove_attendees(int $raid_id, array $player_ids): void;

	/**
	 * Hard-delete a raid: reverses every attendee's ledger entry, deletes
	 * the bb_dkp_raid_attendees rows, deletes the bb_dkp_raids row.
	 * ledger_link rows persist for audit.
	 */
	public function delete_raid(int $raid_id): void;

	/**
	 * @return array list of raid rows (no attendees subarray) ordered by raid_start DESC
	 */
	public function list_raids(int $pool_id = 0, int $event_id = 0, int $limit = 25, int $offset = 0): array;

	/**
	 * @return array|null raid row with `attendees` subarray, or null
	 */
	public function get_raid(int $raid_id): ?array;
}
```

- [ ] **Step 2: Verify lints**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/service/raid_manager_interface.php
```
Expected: `No syntax errors detected`.

### Task 9: Implement `raid_manager` — constructor, create + validation

**Files:**
- Create: `ext/avathar/bbdkp/service/raid_manager.php`

- [ ] **Step 1: Create the implementation file with the constructor and create_raid**

Write `ext/avathar/bbdkp/service/raid_manager.php`:
```php
<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\service;

use avathar\bbdkp\exception\raid_state_exception;

class raid_manager implements raid_manager_interface
{
	/** @var \phpbb\db\driver\driver_interface */
	private $db;

	/** @var dkp_ledger_interface */
	private $ledger;

	/** @var \phpbb\user */
	private $user;

	/** @var \phpbb\log\log_interface */
	private $log;

	/** @var string */
	private $table_raids;

	/** @var string */
	private $table_attendees;

	/** @var string */
	private $table_pools;

	/** @var string */
	private $table_events;

	/** @var string */
	private $table_ledger_link;

	public function __construct(
		\phpbb\db\driver\driver_interface $db,
		dkp_ledger_interface $ledger,
		\phpbb\user $user,
		\phpbb\log\log_interface $log,
		string $table_raids,
		string $table_attendees,
		string $table_pools,
		string $table_events,
		string $table_ledger_link
	)
	{
		$this->db = $db;
		$this->ledger = $ledger;
		$this->user = $user;
		$this->log = $log;
		$this->table_raids = $table_raids;
		$this->table_attendees = $table_attendees;
		$this->table_pools = $table_pools;
		$this->table_events = $table_events;
		$this->table_ledger_link = $table_ledger_link;
	}

	public function create_raid(
		int $guild_id,
		int $pool_id,
		int $event_id,
		int $raid_start,
		int $raid_end,
		string $raid_value,
		string $raid_note,
		array $attendees
	): int
	{
		$this->validate_attendees($attendees);
		$this->assert_pool_enabled($pool_id);
		$this->assert_event_belongs_to_pool($event_id, $pool_id);
		$this->assert_players_in_guild(array_column($attendees, 'player_id'), $guild_id);

		$now = time();
		$uid = (int) $this->user->data['user_id'];

		$this->db->sql_transaction('begin');
		try
		{
			$raid_ary = [
				'guild_id'   => $guild_id,
				'pool_id'    => $pool_id,
				'event_id'   => $event_id,
				'raid_start' => $raid_start,
				'raid_end'   => $raid_end,
				'raid_value' => $raid_value,
				'raid_note'  => $raid_note,
				'added_by'   => $uid,
				'added_at'   => $now,
				'updated_by' => $uid,
				'updated_at' => $now,
			];
			$this->db->sql_query('INSERT INTO ' . $this->table_raids . ' '
				. $this->db->sql_build_array('INSERT', $raid_ary));
			$raid_id = (int) $this->db->sql_nextid();

			foreach ($attendees as $att)
			{
				$attendee_id = $this->insert_attendee($raid_id, $att, $now, $uid);
				$amount = $att['value_override'] ?? $raid_value;
				$this->ledger->post_raid_award($attendee_id, $pool_id, (int) $att['player_id'], (string) $amount);
			}

			$event_name = $this->fetch_event_name($event_id);
			$this->log->add('admin', $uid, $this->user->ip, 'LOG_RAID_ADDED', $now,
				[$event_name, count($attendees)]);

			$this->db->sql_transaction('commit');
			return $raid_id;
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			throw $e;
		}
	}

	// ----- helpers (private) ------------------------------------------------

	private function insert_attendee(int $raid_id, array $att, int $now, int $uid): int
	{
		$att_ary = [
			'raid_id'        => $raid_id,
			'player_id'      => (int) $att['player_id'],
			'value_override' => isset($att['value_override']) ? (string) $att['value_override'] : null,
			'join_time'      => 0,
			'leave_time'     => 0,
			'added_by'       => $uid,
			'added_at'       => $now,
			'updated_by'     => $uid,
			'updated_at'     => $now,
		];
		// value_override is nullable; use sql_build_array which understands NULLs.
		$this->db->sql_query('INSERT INTO ' . $this->table_attendees . ' '
			. $this->db->sql_build_array('INSERT', $att_ary));
		return (int) $this->db->sql_nextid();
	}

	private function validate_attendees(array $attendees): void
	{
		if (empty($attendees))
		{
			throw new raid_state_exception('RAID_NO_ATTENDEES');
		}

		$seen = [];
		foreach ($attendees as $att)
		{
			$pid = (int) ($att['player_id'] ?? 0);
			if ($pid === 0)
			{
				throw new raid_state_exception('RAID_UNKNOWN_PLAYER');
			}
			if (isset($seen[$pid]))
			{
				throw new raid_state_exception('RAID_DUPLICATE_ATTENDEE');
			}
			$seen[$pid] = true;
		}
	}

	private function assert_pool_enabled(int $pool_id): void
	{
		$result = $this->db->sql_query('SELECT pool_status FROM ' . $this->table_pools
			. ' WHERE pool_id = ' . $pool_id);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row || (int) $row['pool_status'] !== 1)
		{
			throw new raid_state_exception('RAID_POOL_DISABLED');
		}
	}

	private function assert_event_belongs_to_pool(int $event_id, int $pool_id): void
	{
		$result = $this->db->sql_query('SELECT pool_id FROM ' . $this->table_events
			. ' WHERE event_id = ' . $event_id);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row || (int) $row['pool_id'] !== $pool_id)
		{
			throw new raid_state_exception('RAID_EVENT_MISMATCH');
		}
	}

	private function assert_players_in_guild(array $player_ids, int $guild_id): void
	{
		if (empty($player_ids))
		{
			return;
		}
		$ids_sql = implode(',', array_map('intval', $player_ids));
		$sql = 'SELECT player_id FROM ' . $this->players_table()
			. " WHERE player_id IN ({$ids_sql}) AND player_guild_id = " . $guild_id;

		$result = $this->db->sql_query($sql);
		$found = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$found[(int) $row['player_id']] = true;
		}
		$this->db->sql_freeresult($result);

		foreach ($player_ids as $pid)
		{
			if (!isset($found[(int) $pid]))
			{
				throw new raid_state_exception('RAID_UNKNOWN_PLAYER');
			}
		}
	}

	private function players_table(): string
	{
		// Resolve bb_players from phpBB core's table prefix.
		// The same prefix that builds our own tables also builds bbGuild's.
		// %tables.bbdkp_pools% looks like "prefix_bb_dkp_pools"; strip
		// the bbDKP-specific suffix to get the raw prefix.
		$marker = 'bb_dkp_pools';
		$pos = strrpos($this->table_pools, $marker);
		if ($pos === false)
		{
			return 'phpbb_bb_players';
		}
		return substr($this->table_pools, 0, $pos) . 'bb_players';
	}

	private function fetch_event_name(int $event_id): string
	{
		$result = $this->db->sql_query('SELECT event_name FROM ' . $this->table_events
			. ' WHERE event_id = ' . $event_id);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row ? (string) $row['event_name'] : ('#' . $event_id);
	}

	// ----- the rest of the API is added in subsequent tasks -----------------

	public function update_raid(int $raid_id, array $fields): void
	{
		throw new \RuntimeException('update_raid not yet implemented — see Task 10');
	}

	public function update_attendee(int $attendee_id, array $fields): void
	{
		throw new \RuntimeException('update_attendee not yet implemented — see Task 10');
	}

	public function add_attendees(int $raid_id, array $attendees): void
	{
		throw new \RuntimeException('add_attendees not yet implemented — see Task 11');
	}

	public function remove_attendees(int $raid_id, array $player_ids): void
	{
		throw new \RuntimeException('remove_attendees not yet implemented — see Task 11');
	}

	public function delete_raid(int $raid_id): void
	{
		throw new \RuntimeException('delete_raid not yet implemented — see Task 11');
	}

	public function list_raids(int $pool_id = 0, int $event_id = 0, int $limit = 25, int $offset = 0): array
	{
		throw new \RuntimeException('list_raids not yet implemented — see Task 12');
	}

	public function get_raid(int $raid_id): ?array
	{
		throw new \RuntimeException('get_raid not yet implemented — see Task 12');
	}
}
```

- [ ] **Step 2: Verify lints**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/service/raid_manager.php
```
Expected: `No syntax errors detected`.

### Task 10: Implement `update_raid` (with cascade) and `update_attendee`

**Files:**
- Modify: `ext/avathar/bbdkp/service/raid_manager.php`

- [ ] **Step 1: Replace the stubs with real implementations**

Edit `ext/avathar/bbdkp/service/raid_manager.php`: replace the two stubs from Task 9 (`update_raid` and `update_attendee`) with:
```php
	public function update_raid(int $raid_id, array $fields): void
	{
		$raid = $this->get_raid_row($raid_id);
		if (!$raid)
		{
			throw new raid_state_exception('RAID_NOT_FOUND');
		}

		$allowed = ['raid_end', 'raid_note', 'raid_value', 'event_id'];
		$update = array_intersect_key($fields, array_flip($allowed));
		if (empty($update))
		{
			return;
		}

		$now = time();
		$uid = (int) $this->user->data['user_id'];
		$value_changed = isset($update['raid_value']) && (string) $update['raid_value'] !== (string) $raid['raid_value'];

		$this->db->sql_transaction('begin');
		try
		{
			if (isset($update['event_id']))
			{
				$this->assert_event_belongs_to_pool((int) $update['event_id'], (int) $raid['pool_id']);
			}

			$update['updated_at'] = $now;
			$update['updated_by'] = $uid;

			$this->db->sql_query('UPDATE ' . $this->table_raids . ' SET '
				. $this->db->sql_build_array('UPDATE', $update)
				. ' WHERE raid_id = ' . $raid_id);

			if ($value_changed)
			{
				$this->cascade_value_change($raid_id, (int) $raid['pool_id'], (string) $update['raid_value']);
			}

			$this->log->add('admin', $uid, $this->user->ip, 'LOG_RAID_UPDATED', $now, [$raid_id]);

			$this->db->sql_transaction('commit');
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			throw $e;
		}
	}

	public function update_attendee(int $attendee_id, array $fields): void
	{
		$row = $this->get_attendee_row($attendee_id);
		if (!$row)
		{
			throw new raid_state_exception('RAID_NOT_FOUND');
		}

		if (!array_key_exists('value_override', $fields))
		{
			return;
		}

		$raid = $this->get_raid_row((int) $row['raid_id']);
		$old_effective = (string) ($row['value_override'] ?? $raid['raid_value']);
		$new_override = $fields['value_override'];
		$new_effective = (string) ($new_override ?? $raid['raid_value']);

		if ($old_effective === $new_effective)
		{
			// No ledger change — just persist metadata if override flipped null<->same value
			$this->persist_attendee_override($attendee_id, $new_override);
			return;
		}

		$now = time();
		$uid = (int) $this->user->data['user_id'];

		$this->db->sql_transaction('begin');
		try
		{
			$link_id = $this->find_live_link('raid_attendee', $attendee_id);
			if ($link_id === 0)
			{
				throw new \RuntimeException('LEDGER_LINK_MISSING');
			}
			$this->ledger->reverse($link_id);
			$this->ledger->post_raid_award($attendee_id, (int) $raid['pool_id'], (int) $row['player_id'], $new_effective);

			$this->persist_attendee_override($attendee_id, $new_override);

			$this->log->add('admin', $uid, $this->user->ip, 'LOG_PLAYERDKP_UPDATED', $now,
				[(int) $row['player_id'], (int) $row['raid_id'], $new_effective]);

			$this->db->sql_transaction('commit');
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			throw $e;
		}
	}

	private function cascade_value_change(int $raid_id, int $pool_id, string $new_value): void
	{
		$sql = 'SELECT attendee_id, player_id FROM ' . $this->table_attendees
			. ' WHERE raid_id = ' . $raid_id
			. ' AND value_override IS NULL';
		$result = $this->db->sql_query($sql);
		while ($att = $this->db->sql_fetchrow($result))
		{
			$attendee_id = (int) $att['attendee_id'];
			$player_id = (int) $att['player_id'];
			$link_id = $this->find_live_link('raid_attendee', $attendee_id);
			if ($link_id === 0)
			{
				$this->db->sql_freeresult($result);
				throw new \RuntimeException('LEDGER_LINK_MISSING');
			}
			$this->ledger->reverse($link_id);
			$this->ledger->post_raid_award($attendee_id, $pool_id, $player_id, $new_value);
		}
		$this->db->sql_freeresult($result);
	}

	private function find_live_link(string $entity_type, int $entity_id): int
	{
		// "Live" = the most recent forward post that has not itself been reversed.
		// A row in bb_dkp_ledger_link is a reversal if reversal_of IS NOT NULL.
		// A forward link L is "live" iff no other link R exists with reversal_of = L.
		$type = $this->db->sql_escape($entity_type);
		$sql = 'SELECT a.link_id
			FROM ' . $this->table_ledger_link . " a
			WHERE a.entity_type = '{$type}'
			AND a.entity_id = " . $entity_id . '
			AND a.reversal_of IS NULL
			AND NOT EXISTS (
				SELECT 1 FROM ' . $this->table_ledger_link . ' r
				WHERE r.reversal_of = a.link_id
			)
			ORDER BY a.link_id DESC';
		$result = $this->db->sql_query_limit($sql, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row ? (int) $row['link_id'] : 0;
	}

	private function get_raid_row(int $raid_id): ?array
	{
		$result = $this->db->sql_query('SELECT * FROM ' . $this->table_raids
			. ' WHERE raid_id = ' . $raid_id);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row ?: null;
	}

	private function get_attendee_row(int $attendee_id): ?array
	{
		$result = $this->db->sql_query('SELECT * FROM ' . $this->table_attendees
			. ' WHERE attendee_id = ' . $attendee_id);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row ?: null;
	}

	private function persist_attendee_override(int $attendee_id, $value_override): void
	{
		$now = time();
		$uid = (int) $this->user->data['user_id'];
		if ($value_override === null)
		{
			$this->db->sql_query('UPDATE ' . $this->table_attendees
				. ' SET value_override = NULL'
				. ', updated_at = ' . $now
				. ', updated_by = ' . $uid
				. ' WHERE attendee_id = ' . $attendee_id);
		}
		else
		{
			$sql_ary = [
				'value_override' => (string) $value_override,
				'updated_at'     => $now,
				'updated_by'     => $uid,
			];
			$this->db->sql_query('UPDATE ' . $this->table_attendees . ' SET '
				. $this->db->sql_build_array('UPDATE', $sql_ary)
				. ' WHERE attendee_id = ' . $attendee_id);
		}
	}
```

- [ ] **Step 2: Verify lints**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/service/raid_manager.php
```
Expected: `No syntax errors detected`.

### Task 11: Implement `add_attendees`, `remove_attendees`, `delete_raid`

**Files:**
- Modify: `ext/avathar/bbdkp/service/raid_manager.php`

- [ ] **Step 1: Replace the three stubs with full implementations**

In `ext/avathar/bbdkp/service/raid_manager.php`, replace the three remaining stubs (`add_attendees`, `remove_attendees`, `delete_raid`) with:
```php
	public function add_attendees(int $raid_id, array $attendees): void
	{
		$raid = $this->get_raid_row($raid_id);
		if (!$raid)
		{
			throw new raid_state_exception('RAID_NOT_FOUND');
		}

		$this->validate_attendees($attendees);

		// Reject duplicates against existing attendees.
		$existing = $this->existing_player_ids($raid_id);
		foreach ($attendees as $att)
		{
			if (isset($existing[(int) $att['player_id']]))
			{
				throw new raid_state_exception('RAID_DUPLICATE_ATTENDEE');
			}
		}

		$this->assert_players_in_guild(array_column($attendees, 'player_id'), (int) $raid['guild_id']);

		$now = time();
		$uid = (int) $this->user->data['user_id'];

		$this->db->sql_transaction('begin');
		try
		{
			foreach ($attendees as $att)
			{
				$attendee_id = $this->insert_attendee($raid_id, $att, $now, $uid);
				$amount = $att['value_override'] ?? $raid['raid_value'];
				$this->ledger->post_raid_award($attendee_id, (int) $raid['pool_id'], (int) $att['player_id'], (string) $amount);
			}

			$this->log->add('admin', $uid, $this->user->ip, 'LOG_RAID_UPDATED', $now, [$raid_id]);
			$this->db->sql_transaction('commit');
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			throw $e;
		}
	}

	public function remove_attendees(int $raid_id, array $player_ids): void
	{
		if (empty($player_ids))
		{
			return;
		}

		$raid = $this->get_raid_row($raid_id);
		if (!$raid)
		{
			throw new raid_state_exception('RAID_NOT_FOUND');
		}

		$ids_sql = implode(',', array_map('intval', $player_ids));
		$sql = 'SELECT attendee_id, player_id FROM ' . $this->table_attendees
			. ' WHERE raid_id = ' . $raid_id
			. " AND player_id IN ({$ids_sql})";
		$result = $this->db->sql_query($sql);
		$targets = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		if (empty($targets))
		{
			return;
		}

		$now = time();
		$uid = (int) $this->user->data['user_id'];

		$this->db->sql_transaction('begin');
		try
		{
			foreach ($targets as $att)
			{
				$attendee_id = (int) $att['attendee_id'];
				$link_id = $this->find_live_link('raid_attendee', $attendee_id);
				if ($link_id > 0)
				{
					$this->ledger->reverse($link_id);
				}
				$this->db->sql_query('DELETE FROM ' . $this->table_attendees
					. ' WHERE attendee_id = ' . $attendee_id);
			}

			$this->log->add('admin', $uid, $this->user->ip, 'LOG_RAID_UPDATED', $now, [$raid_id]);
			$this->db->sql_transaction('commit');
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			throw $e;
		}
	}

	public function delete_raid(int $raid_id): void
	{
		$raid = $this->get_raid_row($raid_id);
		if (!$raid)
		{
			throw new raid_state_exception('RAID_NOT_FOUND');
		}

		$now = time();
		$uid = (int) $this->user->data['user_id'];

		$this->db->sql_transaction('begin');
		try
		{
			$result = $this->db->sql_query('SELECT attendee_id FROM ' . $this->table_attendees
				. ' WHERE raid_id = ' . $raid_id);
			while ($att = $this->db->sql_fetchrow($result))
			{
				$attendee_id = (int) $att['attendee_id'];
				$link_id = $this->find_live_link('raid_attendee', $attendee_id);
				if ($link_id > 0)
				{
					$this->ledger->reverse($link_id);
				}
			}
			$this->db->sql_freeresult($result);

			$this->db->sql_query('DELETE FROM ' . $this->table_attendees
				. ' WHERE raid_id = ' . $raid_id);
			$this->db->sql_query('DELETE FROM ' . $this->table_raids
				. ' WHERE raid_id = ' . $raid_id);

			$this->log->add('admin', $uid, $this->user->ip, 'LOG_RAID_DELETED', $now, [$raid_id]);
			$this->db->sql_transaction('commit');
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			throw $e;
		}
	}

	private function existing_player_ids(int $raid_id): array
	{
		$out = [];
		$result = $this->db->sql_query('SELECT player_id FROM ' . $this->table_attendees
			. ' WHERE raid_id = ' . $raid_id);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$out[(int) $row['player_id']] = true;
		}
		$this->db->sql_freeresult($result);
		return $out;
	}
```

- [ ] **Step 2: Verify lints**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/service/raid_manager.php
```
Expected: `No syntax errors detected`.

### Task 12: Implement `list_raids` and `get_raid`

**Files:**
- Modify: `ext/avathar/bbdkp/service/raid_manager.php`

- [ ] **Step 1: Replace the two remaining stubs**

Replace the last two stubs (`list_raids`, `get_raid`) with:
```php
	public function list_raids(int $pool_id = 0, int $event_id = 0, int $limit = 25, int $offset = 0): array
	{
		$where = [];
		if ($pool_id > 0)
		{
			$where[] = 'r.pool_id = ' . $pool_id;
		}
		if ($event_id > 0)
		{
			$where[] = 'r.event_id = ' . $event_id;
		}
		$where_sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

		$sql = 'SELECT r.*, e.event_name,
			(SELECT COUNT(*) FROM ' . $this->table_attendees . ' a WHERE a.raid_id = r.raid_id) AS attendee_count
			FROM ' . $this->table_raids . ' r
			LEFT JOIN ' . $this->table_events . ' e ON e.event_id = r.event_id'
			. $where_sql
			. ' ORDER BY r.raid_start DESC';

		$result = $this->db->sql_query_limit($sql, $limit, $offset);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function get_raid(int $raid_id): ?array
	{
		$raid = $this->get_raid_row($raid_id);
		if (!$raid)
		{
			return null;
		}

		$result = $this->db->sql_query('SELECT * FROM ' . $this->table_attendees
			. ' WHERE raid_id = ' . $raid_id
			. ' ORDER BY attendee_id ASC');
		$raid['attendees'] = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		return $raid;
	}
```

- [ ] **Step 2: Verify lints**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/service/raid_manager.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Sync + commit phase 2**

Run:
```bash
rsync -a --exclude='.git' --exclude='.DS_Store' \
  /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/ \
  /Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP/

cd /Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP \
  && git add -A \
  && git commit -m "feat: raid_manager service

Domain service for raid + attendee CRUD over alpha1's dkp_ledger primitives.

create_raid: validates (non-empty attendees, no duplicates, pool enabled,
event belongs to pool, players in guild), inserts raid + attendees in one
transaction, posts each attendee via dkp_ledger->post_raid_award.

update_raid: editable raid_end / raid_note / raid_value / event_id. Cascades
reverse+repost for attendees with value_override IS NULL when raid_value
changes (spec §6.3 — fixes legacy bbDKPMOD's silent in-place rewrite).

update_attendee: per-row value_override cascade.

add_attendees / remove_attendees: append or revoke. Removals reverse the
matching live link before deleting the metadata row.

delete_raid: reverses every attendee's live link, deletes attendee + raid
metadata. ledger_link rows persist for audit.

list_raids / get_raid: read paths. get_raid returns attendees subarray.

Exception class raid_state_exception covers user-actionable validation
failures; runtime errors propagate as RuntimeException."
```

---

## Phase 3 — Raid ACP module

This phase exposes `raid_manager` through a phpBB ACP module.

### Task 13: Create `info_acp_raid.php` language file

**Files:**
- Create: `ext/avathar/bbdkp/language/en/info_acp_raid.php`

- [ ] **Step 1: Create the file**

Write `ext/avathar/bbdkp/language/en/info_acp_raid.php`:
```php
<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

$lang = array_merge($lang, [
	'ACP_DKP_RAIDS'              => 'Raids',
	'ACP_DKP_RAIDS_EXPLAIN'      => 'Create, edit and delete raids. Each raid awards DKP to its attendees through bbAccounts. Editing the raid value automatically reverses and reposts the affected entries.',

	'RAID_ADD'                   => 'Add raid',
	'RAID_EDIT'                  => 'Edit raid',
	'RAID_DELETE'                => 'Delete raid',
	'RAID_POOL'                  => 'Pool',
	'RAID_EVENT'                 => 'Event',
	'RAID_START'                 => 'Start (unix seconds)',
	'RAID_END'                   => 'End (unix seconds, 0 = none)',
	'RAID_VALUE'                 => 'Default DKP value',
	'RAID_NOTE'                  => 'Note (BBCode)',
	'RAID_ATTENDEES'             => 'Attendees',
	'RAID_ATTENDEE_VALUE'        => 'Override value',
	'RAID_NONE'                  => 'No raids recorded yet.',
	'RAID_ACTIONS'               => 'Actions',
	'RAID_CREATED'               => 'Raid created (#%d).',
	'RAID_UPDATED'               => 'Raid #%d updated.',
	'RAID_DELETED'               => 'Raid #%d deleted.',
	'RAID_CONFIRM_DELETE'        => 'Delete this raid and reverse all of its DKP postings?',

	// Exception → friendly text
	'RAID_NO_ATTENDEES'          => 'A raid needs at least one attendee.',
	'RAID_DUPLICATE_ATTENDEE'    => 'A player cannot attend the same raid twice.',
	'RAID_POOL_DISABLED'         => 'This pool is disabled. Re-enable it before adding raids.',
	'RAID_EVENT_MISMATCH'        => 'The selected event does not belong to the selected pool.',
	'RAID_UNKNOWN_PLAYER'        => 'One of the attendees is not a valid player in this guild.',
	'RAID_NOT_FOUND'             => 'That raid no longer exists.',
	'LEDGER_LINK_MISSING'        => 'Internal error: no live ledger link found for an attendee. The raid metadata and the ledger are out of sync — contact a developer.',
]);
```

- [ ] **Step 2: Verify lints**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/language/en/info_acp_raid.php
```
Expected: `No syntax errors detected`.

### Task 14: Create `acp_raid_info.php`

**Files:**
- Create: `ext/avathar/bbdkp/acp/acp_raid_info.php`

- [ ] **Step 1: Create the file**

Write `ext/avathar/bbdkp/acp/acp_raid_info.php`:
```php
<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\acp;

class acp_raid_info
{
	public function module()
	{
		return [
			'filename' => '\\avathar\\bbdkp\\acp\\acp_raid_module',
			'title'    => 'ACP_DKP_RAIDS',
			'modes'    => [
				'list' => [
					'title' => 'ACP_DKP_RAIDS',
					'auth'  => 'ext_avathar/bbdkp && acl_a_bbdkp',
					'cat'   => ['ACP_DKP'],
				],
			],
		];
	}
}
```

- [ ] **Step 2: Verify lints**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/acp/acp_raid_info.php
```
Expected: `No syntax errors detected`.

### Task 15: Create `acp_raid_module.php`

**Files:**
- Create: `ext/avathar/bbdkp/acp/acp_raid_module.php`

- [ ] **Step 1: Write the ACP module class**

Write `ext/avathar/bbdkp/acp/acp_raid_module.php`:
```php
<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\acp;

use avathar\bbdkp\exception\raid_state_exception;

class acp_raid_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	public function main($id, $mode)
	{
		global $phpbb_container, $request, $template, $user, $db;

		$user->add_lang_ext('avathar/bbdkp', ['common', 'info_acp_raid', 'logs']);

		$raids   = $phpbb_container->get('avathar.bbdkp.raid_manager');
		$pools   = $phpbb_container->get('avathar.bbdkp.pool_manager');
		$events  = $phpbb_container->get('avathar.bbdkp.event_manager');

		$action  = $request->variable('action', '');
		$raid_id = $request->variable('raid_id', 0);

		$this->tpl_name   = 'acp_bbdkp_raid';
		$this->page_title = 'ACP_DKP_RAIDS';

		try
		{
			switch ($action)
			{
				case 'add':
				case 'edit':
					$this->handle_form($raids, $pools, $events, $raid_id, $request, $template, $user, $db);
					break;

				case 'delete':
					$raid = $raids->get_raid($raid_id);
					if ($raid && confirm_box(true))
					{
						$raids->delete_raid($raid_id);
						trigger_error(sprintf($user->lang['RAID_DELETED'], $raid_id)
							. adm_back_link($this->u_action));
					}
					elseif ($raid)
					{
						confirm_box(false, $user->lang['RAID_CONFIRM_DELETE'],
							build_hidden_fields(['action' => 'delete', 'raid_id' => $raid_id]));
					}
					break;
			}
		}
		catch (raid_state_exception $e)
		{
			trigger_error($user->lang[$e->getMessage()]
				. adm_back_link($this->u_action), E_USER_WARNING);
		}
		catch (\RuntimeException $e)
		{
			$key = $e->getMessage();
			$msg = isset($user->lang[$key]) ? $user->lang[$key] : $key;
			trigger_error($msg . adm_back_link($this->u_action), E_USER_WARNING);
		}

		$this->render_list($raids, $template);
		$template->assign_var('U_ACTION', $this->u_action);
	}

	private function render_list($raids, $template): void
	{
		foreach ($raids->list_raids() as $row)
		{
			$template->assign_block_vars('raids', [
				'RAID_ID'         => (int) $row['raid_id'],
				'EVENT_NAME'      => $row['event_name'] ?? ('#' . $row['event_id']),
				'POOL_ID'         => (int) $row['pool_id'],
				'RAID_START'      => (int) $row['raid_start'],
				'RAID_END'        => (int) $row['raid_end'],
				'RAID_VALUE'      => $row['raid_value'],
				'ATTENDEE_COUNT'  => (int) $row['attendee_count'],
				'U_EDIT'          => $this->u_action . '&amp;action=edit&amp;raid_id=' . (int) $row['raid_id'],
				'U_DELETE'        => $this->u_action . '&amp;action=delete&amp;raid_id=' . (int) $row['raid_id'],
			]);
		}
	}

	private function handle_form($raids, $pools, $events, int $raid_id, $request, $template, $user, $db): void
	{
		$is_edit  = $raid_id > 0;
		$existing = $is_edit ? $raids->get_raid($raid_id) : null;

		if ($request->is_set_post('submit'))
		{
			$guild_id   = $request->variable('guild_id', 0);
			$pool_id    = $request->variable('pool_id', 0);
			$event_id   = $request->variable('event_id', 0);
			$raid_start = $request->variable('raid_start', 0);
			$raid_end   = $request->variable('raid_end', 0);
			$raid_value = $request->variable('raid_value', '0.00');
			$raid_note  = $request->variable('raid_note', '', true);

			$attendee_ids   = $request->variable('attendee_ids', [0]);
			$attendee_ovrs  = $request->variable('attendee_overrides', ['']);
			$attendees = [];
			foreach ($attendee_ids as $i => $pid)
			{
				$pid = (int) $pid;
				if ($pid === 0)
				{
					continue;
				}
				$override = trim((string) ($attendee_ovrs[$i] ?? ''));
				$attendees[] = [
					'player_id'      => $pid,
					'value_override' => $override === '' ? null : $override,
				];
			}

			if ($is_edit)
			{
				$raids->update_raid($raid_id, [
					'event_id'   => $event_id,
					'raid_end'   => $raid_end,
					'raid_note'  => $raid_note,
					'raid_value' => $raid_value,
				]);
				trigger_error(sprintf($user->lang['RAID_UPDATED'], $raid_id)
					. adm_back_link($this->u_action));
			}
			else
			{
				$new_id = $raids->create_raid($guild_id, $pool_id, $event_id, $raid_start, $raid_end,
					$raid_value, $raid_note, $attendees);
				trigger_error(sprintf($user->lang['RAID_CREATED'], $new_id)
					. adm_back_link($this->u_action));
			}
		}

		// Form options
		foreach ($pools->list_pools() as $p)
		{
			$template->assign_block_vars('pool_options', [
				'POOL_ID'   => (int) $p['pool_id'],
				'POOL_NAME' => $p['pool_name'],
				'GUILD_ID'  => (int) $p['guild_id'],
				'SELECTED'  => $is_edit && (int) $p['pool_id'] === (int) $existing['pool_id'],
			]);
		}
		foreach ($events->list_events() as $e)
		{
			$template->assign_block_vars('event_options', [
				'EVENT_ID'   => (int) $e['event_id'],
				'EVENT_NAME' => $e['event_name'],
				'POOL_ID'    => (int) $e['pool_id'],
				'SELECTED'   => $is_edit && (int) $e['event_id'] === (int) $existing['event_id'],
			]);
		}

		// Player picker: list all players in the guild of the edited raid (or guild 0 = all on add)
		$this->render_player_picker($db, $template, $is_edit ? (int) $existing['guild_id'] : 0,
			$is_edit ? array_column($existing['attendees'], 'player_id') : []);

		$template->assign_vars([
			'S_FORM'        => true,
			'S_IS_EDIT'     => $is_edit,
			'RAID_GUILD'    => $existing['guild_id'] ?? 0,
			'RAID_START'    => $existing['raid_start'] ?? time(),
			'RAID_END'      => $existing['raid_end']   ?? 0,
			'RAID_VALUE'    => $existing['raid_value'] ?? '0.00',
			'RAID_NOTE'     => $existing['raid_note']  ?? '',
			'U_BACK'        => $this->u_action,
		]);

		// Existing attendees (for edit-mode rendering of override inputs)
		if ($is_edit)
		{
			foreach ($existing['attendees'] as $att)
			{
				$template->assign_block_vars('existing_attendees', [
					'ATTENDEE_ID'    => (int) $att['attendee_id'],
					'PLAYER_ID'      => (int) $att['player_id'],
					'VALUE_OVERRIDE' => $att['value_override'] ?? '',
				]);
			}
		}
	}

	private function render_player_picker($db, $template, int $guild_id, array $selected_ids): void
	{
		$selected_set = array_flip(array_map('intval', $selected_ids));

		// Resolve bb_players via the container's tables.bbdkp_pools parameter,
		// stripping the bbDKP-specific suffix to get phpBB's table prefix.
		global $phpbb_container;
		$prefix = '';
		try
		{
			$pool_table = $phpbb_container->getParameter('tables.bbdkp_pools');
			$pos = strrpos($pool_table, 'bb_dkp_pools');
			$prefix = $pos === false ? '' : substr($pool_table, 0, $pos);
		}
		catch (\Exception $e)
		{
			$prefix = '';
		}
		$players_table = $prefix . 'bb_players';

		$sql = 'SELECT player_id, player_name, player_realm
			FROM ' . $players_table
			. ($guild_id > 0 ? ' WHERE player_guild_id = ' . $guild_id : '')
			. ' ORDER BY player_name ASC';
		$result = $db->sql_query_limit($sql, 200);
		while ($row = $db->sql_fetchrow($result))
		{
			$template->assign_block_vars('player_picker', [
				'PLAYER_ID'   => (int) $row['player_id'],
				'PLAYER_NAME' => $row['player_name'],
				'REALM'       => $row['player_realm'],
				'SELECTED'    => isset($selected_set[(int) $row['player_id']]),
			]);
		}
		$db->sql_freeresult($result);
	}
}
```

- [ ] **Step 2: Verify lints**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/acp/acp_raid_module.php
```
Expected: `No syntax errors detected`.

### Task 16: Create `acp_bbdkp_raid.html` template

**Files:**
- Create: `ext/avathar/bbdkp/adm/style/acp_bbdkp_raid.html`

- [ ] **Step 1: Write the template**

Write `ext/avathar/bbdkp/adm/style/acp_bbdkp_raid.html`:
```html
<!-- INCLUDE overall_header.html -->

<a id="maincontent"></a>

<h1>{L_ACP_DKP_RAIDS}</h1>
<p>{L_ACP_DKP_RAIDS_EXPLAIN}</p>

<!-- IF S_FORM -->
	<form id="raid_form" method="post" action="{U_ACTION}">
		<fieldset>
			<legend><!-- IF S_IS_EDIT -->{L_RAID_EDIT}<!-- ELSE -->{L_RAID_ADD}<!-- ENDIF --></legend>

			<dl>
				<dt><label for="guild_id">{L_RAID_POOL} (guild):</label></dt>
				<dd><input type="number" id="guild_id" name="guild_id" value="{RAID_GUILD}" min="1" required<!-- IF S_IS_EDIT --> disabled<!-- ENDIF -->></dd>
			</dl>

			<dl>
				<dt><label for="pool_id">{L_RAID_POOL}:</label></dt>
				<dd>
					<select id="pool_id" name="pool_id"<!-- IF S_IS_EDIT --> disabled<!-- ENDIF --> required>
					<!-- BEGIN pool_options -->
						<option value="{pool_options.POOL_ID}"<!-- IF pool_options.SELECTED --> selected<!-- ENDIF -->>{pool_options.POOL_NAME}</option>
					<!-- BEGINELSE -->
						<option value="0">— no pools —</option>
					<!-- END pool_options -->
					</select>
				</dd>
			</dl>

			<dl>
				<dt><label for="event_id">{L_RAID_EVENT}:</label></dt>
				<dd>
					<select id="event_id" name="event_id" required>
					<!-- BEGIN event_options -->
						<option value="{event_options.EVENT_ID}" data-pool="{event_options.POOL_ID}"<!-- IF event_options.SELECTED --> selected<!-- ENDIF -->>{event_options.EVENT_NAME}</option>
					<!-- BEGINELSE -->
						<option value="0">— no events —</option>
					<!-- END event_options -->
					</select>
				</dd>
			</dl>

			<dl>
				<dt><label for="raid_start">{L_RAID_START}:</label></dt>
				<dd><input type="number" id="raid_start" name="raid_start" value="{RAID_START}" min="0" required<!-- IF S_IS_EDIT --> disabled<!-- ENDIF -->></dd>
			</dl>

			<dl>
				<dt><label for="raid_end">{L_RAID_END}:</label></dt>
				<dd><input type="number" id="raid_end" name="raid_end" value="{RAID_END}" min="0"></dd>
			</dl>

			<dl>
				<dt><label for="raid_value">{L_RAID_VALUE}:</label></dt>
				<dd><input type="text" id="raid_value" name="raid_value" value="{RAID_VALUE}" pattern="^-?[0-9]+(\.[0-9]+)?$" required></dd>
			</dl>

			<dl>
				<dt><label for="raid_note">{L_RAID_NOTE}:</label></dt>
				<dd><textarea id="raid_note" name="raid_note" rows="3" cols="60">{RAID_NOTE}</textarea></dd>
			</dl>

			<fieldset>
				<legend>{L_RAID_ATTENDEES}</legend>

				<!-- IF S_IS_EDIT -->
					<table class="table1">
						<thead><tr><th>Attendee</th><th>{L_RAID_ATTENDEE_VALUE}</th></tr></thead>
						<tbody>
						<!-- BEGIN existing_attendees -->
							<tr>
								<td>player #{existing_attendees.PLAYER_ID}</td>
								<td>{existing_attendees.VALUE_OVERRIDE}</td>
							</tr>
						<!-- BEGINELSE -->
							<tr><td colspan="2"><em>(none yet)</em></td></tr>
						<!-- END existing_attendees -->
						</tbody>
					</table>
					<p><em>Attendee changes during edit are not exposed in alpha2 ACP; use delete + re-add for now.</em></p>
				<!-- ELSE -->
					<table class="table1">
						<thead><tr><th>+</th><th>Player</th><th>{L_RAID_ATTENDEE_VALUE}</th></tr></thead>
						<tbody>
						<!-- BEGIN player_picker -->
							<tr>
								<td><input type="checkbox" name="attendee_ids[]" value="{player_picker.PLAYER_ID}"<!-- IF player_picker.SELECTED --> checked<!-- ENDIF -->></td>
								<td>{player_picker.PLAYER_NAME}<!-- IF player_picker.REALM --> ({player_picker.REALM})<!-- ENDIF --></td>
								<td><input type="text" name="attendee_overrides[]" placeholder="(default)" size="8" pattern="^-?[0-9]+(\.[0-9]+)?$"></td>
							</tr>
						<!-- BEGINELSE -->
							<tr><td colspan="3"><em>(no players in this guild yet)</em></td></tr>
						<!-- END player_picker -->
						</tbody>
					</table>
				<!-- ENDIF -->
			</fieldset>

			<p class="submit-buttons">
				<input type="submit" name="submit" value="{L_SUBMIT}" class="button1">
				&nbsp; <a href="{U_BACK}">{L_CANCEL}</a>
			</p>
			{S_FORM_TOKEN}
		</fieldset>
	</form>
<!-- ELSE -->
	<p><a href="{U_ACTION}&amp;action=add" class="button1">{L_RAID_ADD}</a></p>

	<table class="table1">
		<thead>
			<tr>
				<th>{L_RAID_EVENT}</th>
				<th>{L_RAID_START}</th>
				<th>{L_RAID_VALUE}</th>
				<th>{L_RAID_ATTENDEES}</th>
				<th>{L_RAID_ACTIONS}</th>
			</tr>
		</thead>
		<tbody>
		<!-- BEGIN raids -->
			<tr>
				<td>{raids.EVENT_NAME}</td>
				<td>{raids.RAID_START}</td>
				<td>{raids.RAID_VALUE}</td>
				<td>{raids.ATTENDEE_COUNT}</td>
				<td>
					<a href="{raids.U_EDIT}">{L_RAID_EDIT}</a>
					| <a href="{raids.U_DELETE}">{L_RAID_DELETE}</a>
				</td>
			</tr>
		<!-- BEGINELSE -->
			<tr><td colspan="5"><em>{L_RAID_NONE}</em></td></tr>
		<!-- END raids -->
		</tbody>
	</table>
<!-- ENDIF -->

<!-- INCLUDE overall_footer.html -->
```

### Task 17: Sync + commit phase 3

- [ ] **Step 1: Sync**

Run:
```bash
rsync -a --exclude='.git' --exclude='.DS_Store' \
  /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/ \
  /Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP/
```

- [ ] **Step 2: Commit**

Run:
```bash
cd /Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP \
  && git add -A \
  && git commit -m "feat: raid ACP module — full CRUD

acp_raid_info + acp_raid_module + adm/style template + info_acp_raid
language file. Add mode renders a multi-select player picker scoped to
the chosen pool's guild; each attendee row has an inline override-value
field. Edit mode allows raid_value / raid_end / raid_note / event_id
changes; attendee membership edits via delete + re-add (full attendee
editing is alpha3+). Delete uses phpBB's confirm_box.

Module registration happens in the alpha2 migration (Task 26)."
```

---

## Phase 4 — `adjustment_manager` service

### Task 18: Create `adjustment_state_exception`

**Files:**
- Create: `ext/avathar/bbdkp/exception/adjustment_state_exception.php`

- [ ] **Step 1: Write the file**

Write `ext/avathar/bbdkp/exception/adjustment_state_exception.php`:
```php
<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\exception;

/**
 * Thrown by adjustment_manager for user-actionable validation failures.
 *
 * Reserved keys:
 *   ADJ_NO_RECIPIENTS, ADJ_ZERO_AMOUNT, ADJ_DUPLICATE_RECIPIENT,
 *   ADJ_UNKNOWN_PLAYER, ADJ_POOL_DISABLED, ADJ_NOT_FOUND.
 */
class adjustment_state_exception extends \RuntimeException
{
}
```

- [ ] **Step 2: Verify lints**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/exception/adjustment_state_exception.php
```
Expected: `No syntax errors detected`.

### Task 19: Create `adjustment_manager_interface`

**Files:**
- Create: `ext/avathar/bbdkp/service/adjustment_manager_interface.php`

- [ ] **Step 1: Write the interface**

Write `ext/avathar/bbdkp/service/adjustment_manager_interface.php`:
```php
<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\service;

/**
 * Domain service for adjustment + recipient CRUD.
 *
 * One adjustment header carries one reason and one group_key; N recipient
 * rows attach with individual signed amounts. Bulk-add posts each recipient
 * via dkp_ledger->post_adjustment().
 */
interface adjustment_manager_interface
{
	/**
	 * @param int    $pool_id
	 * @param string $reason
	 * @param array  $recipients each: ['player_id' => int, 'amount' => string (signed)]
	 *
	 * @return int adjustment_id
	 *
	 * @throws \avathar\bbdkp\exception\adjustment_state_exception
	 *         ADJ_NO_RECIPIENTS, ADJ_ZERO_AMOUNT, ADJ_DUPLICATE_RECIPIENT,
	 *         ADJ_UNKNOWN_PLAYER, ADJ_POOL_DISABLED
	 */
	public function create_adjustment(int $pool_id, string $reason, array $recipients): int;

	/**
	 * Editable: reason only. pool_id and adjustment_date are immutable.
	 * Amount changes go via remove_recipients + add_recipients.
	 */
	public function update_adjustment(int $adjustment_id, array $fields): void;

	public function add_recipients(int $adjustment_id, array $recipients): void;

	/** @param int[] $recipient_ids */
	public function remove_recipients(int $adjustment_id, array $recipient_ids): void;

	/**
	 * Reverses every recipient's ledger entry, deletes the recipient rows,
	 * deletes the adjustment header. ledger_link rows persist for audit.
	 */
	public function delete_adjustment(int $adjustment_id): void;

	public function list_adjustments(int $pool_id = 0, int $limit = 25, int $offset = 0): array;

	public function get_adjustment(int $adjustment_id): ?array;
}
```

- [ ] **Step 2: Verify lints**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/service/adjustment_manager_interface.php
```
Expected: `No syntax errors detected`.

### Task 20: Implement `adjustment_manager`

**Files:**
- Create: `ext/avathar/bbdkp/service/adjustment_manager.php`

- [ ] **Step 1: Write the full implementation**

Write `ext/avathar/bbdkp/service/adjustment_manager.php`:
```php
<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\service;

use avathar\bbdkp\exception\adjustment_state_exception;

class adjustment_manager implements adjustment_manager_interface
{
	/** @var \phpbb\db\driver\driver_interface */
	private $db;

	/** @var dkp_ledger_interface */
	private $ledger;

	/** @var \phpbb\user */
	private $user;

	/** @var \phpbb\log\log_interface */
	private $log;

	/** @var string */
	private $table_adjustments;

	/** @var string */
	private $table_recipients;

	/** @var string */
	private $table_pools;

	/** @var string */
	private $table_ledger_link;

	public function __construct(
		\phpbb\db\driver\driver_interface $db,
		dkp_ledger_interface $ledger,
		\phpbb\user $user,
		\phpbb\log\log_interface $log,
		string $table_adjustments,
		string $table_recipients,
		string $table_pools,
		string $table_ledger_link
	)
	{
		$this->db = $db;
		$this->ledger = $ledger;
		$this->user = $user;
		$this->log = $log;
		$this->table_adjustments = $table_adjustments;
		$this->table_recipients = $table_recipients;
		$this->table_pools = $table_pools;
		$this->table_ledger_link = $table_ledger_link;
	}

	public function create_adjustment(int $pool_id, string $reason, array $recipients): int
	{
		$this->validate_recipients($recipients);
		$this->assert_pool_enabled($pool_id);

		$now = time();
		$uid = (int) $this->user->data['user_id'];

		$this->db->sql_transaction('begin');
		try
		{
			$group_key = unique_id();

			$adj_ary = [
				'pool_id'            => $pool_id,
				'adjustment_date'    => $now,
				'adjustment_reason'  => $reason,
				'group_key'          => $group_key,
				'added_by'           => $uid,
				'added_at'           => $now,
				'updated_by'         => $uid,
				'updated_at'         => $now,
			];
			$this->db->sql_query('INSERT INTO ' . $this->table_adjustments . ' '
				. $this->db->sql_build_array('INSERT', $adj_ary));
			$adjustment_id = (int) $this->db->sql_nextid();

			foreach ($recipients as $r)
			{
				$rec_ary = [
					'adjustment_id' => $adjustment_id,
					'player_id'     => (int) $r['player_id'],
					'amount'        => (string) $r['amount'],
				];
				$this->db->sql_query('INSERT INTO ' . $this->table_recipients . ' '
					. $this->db->sql_build_array('INSERT', $rec_ary));
				$recipient_id = (int) $this->db->sql_nextid();

				$this->ledger->post_adjustment($recipient_id, $pool_id, (int) $r['player_id'], (string) $r['amount']);
			}

			$this->log->add('admin', $uid, $this->user->ip, 'LOG_INDIVADJ_ADDED', $now,
				[$reason, count($recipients)]);

			$this->db->sql_transaction('commit');
			return $adjustment_id;
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			throw $e;
		}
	}

	public function update_adjustment(int $adjustment_id, array $fields): void
	{
		$existing = $this->get_adjustment_row($adjustment_id);
		if (!$existing)
		{
			throw new adjustment_state_exception('ADJ_NOT_FOUND');
		}

		$allowed = ['adjustment_reason'];
		$update = array_intersect_key($fields, array_flip($allowed));
		if (empty($update))
		{
			return;
		}

		$now = time();
		$uid = (int) $this->user->data['user_id'];
		$update['updated_at'] = $now;
		$update['updated_by'] = $uid;

		$this->db->sql_query('UPDATE ' . $this->table_adjustments . ' SET '
			. $this->db->sql_build_array('UPDATE', $update)
			. ' WHERE adjustment_id = ' . $adjustment_id);

		$this->log->add('admin', $uid, $this->user->ip, 'LOG_INDIVADJ_UPDATED', $now, [$adjustment_id]);
	}

	public function add_recipients(int $adjustment_id, array $recipients): void
	{
		$existing = $this->get_adjustment_row($adjustment_id);
		if (!$existing)
		{
			throw new adjustment_state_exception('ADJ_NOT_FOUND');
		}

		$this->validate_recipients($recipients);
		$existing_players = $this->existing_recipient_player_ids($adjustment_id);
		foreach ($recipients as $r)
		{
			if (isset($existing_players[(int) $r['player_id']]))
			{
				throw new adjustment_state_exception('ADJ_DUPLICATE_RECIPIENT');
			}
		}

		$pool_id = (int) $existing['pool_id'];
		$now = time();
		$uid = (int) $this->user->data['user_id'];

		$this->db->sql_transaction('begin');
		try
		{
			foreach ($recipients as $r)
			{
				$rec_ary = [
					'adjustment_id' => $adjustment_id,
					'player_id'     => (int) $r['player_id'],
					'amount'        => (string) $r['amount'],
				];
				$this->db->sql_query('INSERT INTO ' . $this->table_recipients . ' '
					. $this->db->sql_build_array('INSERT', $rec_ary));
				$recipient_id = (int) $this->db->sql_nextid();

				$this->ledger->post_adjustment($recipient_id, $pool_id, (int) $r['player_id'], (string) $r['amount']);
			}
			$this->log->add('admin', $uid, $this->user->ip, 'LOG_INDIVADJ_UPDATED', $now, [$adjustment_id]);
			$this->db->sql_transaction('commit');
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			throw $e;
		}
	}

	public function remove_recipients(int $adjustment_id, array $recipient_ids): void
	{
		if (empty($recipient_ids))
		{
			return;
		}

		$existing = $this->get_adjustment_row($adjustment_id);
		if (!$existing)
		{
			throw new adjustment_state_exception('ADJ_NOT_FOUND');
		}

		$now = time();
		$uid = (int) $this->user->data['user_id'];

		$this->db->sql_transaction('begin');
		try
		{
			foreach ($recipient_ids as $rid)
			{
				$rid = (int) $rid;
				$link_id = $this->find_live_link('adjustment_recipient', $rid);
				if ($link_id > 0)
				{
					$this->ledger->reverse($link_id);
				}
				$this->db->sql_query('DELETE FROM ' . $this->table_recipients
					. ' WHERE recipient_id = ' . $rid);
			}
			$this->log->add('admin', $uid, $this->user->ip, 'LOG_INDIVADJ_UPDATED', $now, [$adjustment_id]);
			$this->db->sql_transaction('commit');
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			throw $e;
		}
	}

	public function delete_adjustment(int $adjustment_id): void
	{
		$existing = $this->get_adjustment_row($adjustment_id);
		if (!$existing)
		{
			throw new adjustment_state_exception('ADJ_NOT_FOUND');
		}

		$now = time();
		$uid = (int) $this->user->data['user_id'];

		$this->db->sql_transaction('begin');
		try
		{
			$result = $this->db->sql_query('SELECT recipient_id FROM ' . $this->table_recipients
				. ' WHERE adjustment_id = ' . $adjustment_id);
			while ($r = $this->db->sql_fetchrow($result))
			{
				$rid = (int) $r['recipient_id'];
				$link_id = $this->find_live_link('adjustment_recipient', $rid);
				if ($link_id > 0)
				{
					$this->ledger->reverse($link_id);
				}
			}
			$this->db->sql_freeresult($result);

			$this->db->sql_query('DELETE FROM ' . $this->table_recipients
				. ' WHERE adjustment_id = ' . $adjustment_id);
			$this->db->sql_query('DELETE FROM ' . $this->table_adjustments
				. ' WHERE adjustment_id = ' . $adjustment_id);

			$this->log->add('admin', $uid, $this->user->ip, 'LOG_INDIVADJ_DELETED', $now, [$adjustment_id]);
			$this->db->sql_transaction('commit');
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			throw $e;
		}
	}

	public function list_adjustments(int $pool_id = 0, int $limit = 25, int $offset = 0): array
	{
		$where = $pool_id > 0 ? ' WHERE pool_id = ' . $pool_id : '';
		$sql = 'SELECT a.*,
			(SELECT COUNT(*) FROM ' . $this->table_recipients . ' r WHERE r.adjustment_id = a.adjustment_id) AS recipient_count,
			(SELECT SUM(r.amount) FROM ' . $this->table_recipients . ' r WHERE r.adjustment_id = a.adjustment_id) AS total_amount
			FROM ' . $this->table_adjustments . ' a'
			. $where
			. ' ORDER BY a.adjustment_date DESC';

		$result = $this->db->sql_query_limit($sql, $limit, $offset);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $rows;
	}

	public function get_adjustment(int $adjustment_id): ?array
	{
		$row = $this->get_adjustment_row($adjustment_id);
		if (!$row)
		{
			return null;
		}
		$result = $this->db->sql_query('SELECT * FROM ' . $this->table_recipients
			. ' WHERE adjustment_id = ' . $adjustment_id
			. ' ORDER BY recipient_id ASC');
		$row['recipients'] = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);
		return $row;
	}

	// ----- private helpers --------------------------------------------------

	private function validate_recipients(array $recipients): void
	{
		if (empty($recipients))
		{
			throw new adjustment_state_exception('ADJ_NO_RECIPIENTS');
		}

		$seen = [];
		foreach ($recipients as $r)
		{
			$pid = (int) ($r['player_id'] ?? 0);
			$amt = (string) ($r['amount'] ?? '0');
			if ($pid === 0)
			{
				throw new adjustment_state_exception('ADJ_UNKNOWN_PLAYER');
			}
			if (isset($seen[$pid]))
			{
				throw new adjustment_state_exception('ADJ_DUPLICATE_RECIPIENT');
			}
			if ((float) $amt === 0.0)
			{
				throw new adjustment_state_exception('ADJ_ZERO_AMOUNT');
			}
			$seen[$pid] = true;
		}
	}

	private function assert_pool_enabled(int $pool_id): void
	{
		$result = $this->db->sql_query('SELECT pool_status FROM ' . $this->table_pools
			. ' WHERE pool_id = ' . $pool_id);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row || (int) $row['pool_status'] !== 1)
		{
			throw new adjustment_state_exception('ADJ_POOL_DISABLED');
		}
	}

	private function get_adjustment_row(int $adjustment_id): ?array
	{
		$result = $this->db->sql_query('SELECT * FROM ' . $this->table_adjustments
			. ' WHERE adjustment_id = ' . $adjustment_id);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row ?: null;
	}

	private function existing_recipient_player_ids(int $adjustment_id): array
	{
		$out = [];
		$result = $this->db->sql_query('SELECT player_id FROM ' . $this->table_recipients
			. ' WHERE adjustment_id = ' . $adjustment_id);
		while ($r = $this->db->sql_fetchrow($result))
		{
			$out[(int) $r['player_id']] = true;
		}
		$this->db->sql_freeresult($result);
		return $out;
	}

	private function find_live_link(string $entity_type, int $entity_id): int
	{
		$type = $this->db->sql_escape($entity_type);
		$sql = 'SELECT a.link_id
			FROM ' . $this->table_ledger_link . " a
			WHERE a.entity_type = '{$type}'
			AND a.entity_id = " . $entity_id . '
			AND a.reversal_of IS NULL
			AND NOT EXISTS (
				SELECT 1 FROM ' . $this->table_ledger_link . ' r
				WHERE r.reversal_of = a.link_id
			)
			ORDER BY a.link_id DESC';
		$result = $this->db->sql_query_limit($sql, 1);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		return $row ? (int) $row['link_id'] : 0;
	}
}
```

- [ ] **Step 2: Verify lints**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/service/adjustment_manager.php
```
Expected: `No syntax errors detected`.

### Task 21: Sync + commit phase 4

- [ ] **Step 1: Sync + commit**

Run:
```bash
rsync -a --exclude='.git' --exclude='.DS_Store' \
  /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/ \
  /Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP/

cd /Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP \
  && git add -A \
  && git commit -m "feat: adjustment_manager service

Domain service for adjustment + recipient CRUD. create_adjustment writes
one header row (carrying the group_key generated via unique_id()) and N
recipient rows, posting each to dkp_ledger->post_adjustment inside a
single transaction. delete_adjustment + remove_recipients reverse the
live links before tearing down metadata. Validation surfaces typed
exceptions for empty recipient list, zero amount, duplicate player,
unknown player, and disabled pool.

Read paths: list_adjustments returns recipient_count + total_amount
aggregates per adjustment; get_adjustment hydrates the recipients
subarray."
```

---

## Phase 5 — Adjustment ACP module

### Task 22: Create `info_acp_adjustment.php` + common.php additions

**Files:**
- Create: `ext/avathar/bbdkp/language/en/info_acp_adjustment.php`

- [ ] **Step 1: Write the file**

Write `ext/avathar/bbdkp/language/en/info_acp_adjustment.php`:
```php
<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

$lang = array_merge($lang, [
	'ACP_DKP_ADJUSTMENTS'         => 'Adjustments',
	'ACP_DKP_ADJUSTMENTS_EXPLAIN' => 'Manual DKP grants and debits. One reason + signed amount applies to all selected recipients; use a negative amount to subtract DKP. Each row posts an entry through bbAccounts.',

	'ADJ_ADD'                     => 'Add adjustment',
	'ADJ_EDIT'                    => 'Edit adjustment',
	'ADJ_DELETE'                  => 'Delete adjustment',
	'ADJ_POOL'                    => 'Pool',
	'ADJ_REASON'                  => 'Reason',
	'ADJ_AMOUNT'                  => 'Amount (signed)',
	'ADJ_RECIPIENTS'              => 'Recipients',
	'ADJ_DATE'                    => 'Date',
	'ADJ_TOTAL'                   => 'Total',
	'ADJ_NONE'                    => 'No adjustments recorded yet.',
	'ADJ_ACTIONS'                 => 'Actions',
	'ADJ_CREATED'                 => 'Adjustment #%d created.',
	'ADJ_UPDATED'                 => 'Adjustment #%d updated.',
	'ADJ_DELETED'                 => 'Adjustment #%d deleted.',
	'ADJ_CONFIRM_DELETE'          => 'Delete this adjustment and reverse all of its DKP postings?',

	// Exception → friendly text
	'ADJ_NO_RECIPIENTS'           => 'Select at least one recipient.',
	'ADJ_ZERO_AMOUNT'             => 'Adjustment amount must be non-zero.',
	'ADJ_DUPLICATE_RECIPIENT'     => 'A player can only appear once per adjustment.',
	'ADJ_UNKNOWN_PLAYER'          => 'One of the selected recipients is not a valid player in this guild.',
	'ADJ_POOL_DISABLED'           => 'This pool is disabled. Re-enable it before adding adjustments.',
	'ADJ_NOT_FOUND'               => 'That adjustment no longer exists.',
]);
```

- [ ] **Step 2: Verify lints**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/language/en/info_acp_adjustment.php
```
Expected: `No syntax errors detected`.

### Task 23: Create `acp_adjustment_info.php`

**Files:**
- Create: `ext/avathar/bbdkp/acp/acp_adjustment_info.php`

- [ ] **Step 1: Write the file**

Write `ext/avathar/bbdkp/acp/acp_adjustment_info.php`:
```php
<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\acp;

class acp_adjustment_info
{
	public function module()
	{
		return [
			'filename' => '\\avathar\\bbdkp\\acp\\acp_adjustment_module',
			'title'    => 'ACP_DKP_ADJUSTMENTS',
			'modes'    => [
				'list' => [
					'title' => 'ACP_DKP_ADJUSTMENTS',
					'auth'  => 'ext_avathar/bbdkp && acl_a_bbdkp',
					'cat'   => ['ACP_DKP'],
				],
			],
		];
	}
}
```

- [ ] **Step 2: Verify lints**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/acp/acp_adjustment_info.php
```
Expected: `No syntax errors detected`.

### Task 24: Create `acp_adjustment_module.php`

**Files:**
- Create: `ext/avathar/bbdkp/acp/acp_adjustment_module.php`

- [ ] **Step 1: Write the module**

Write `ext/avathar/bbdkp/acp/acp_adjustment_module.php`:
```php
<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\acp;

use avathar\bbdkp\exception\adjustment_state_exception;

class acp_adjustment_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	public function main($id, $mode)
	{
		global $phpbb_container, $request, $template, $user, $db;

		$user->add_lang_ext('avathar/bbdkp', ['common', 'info_acp_adjustment', 'logs']);

		$adjustments = $phpbb_container->get('avathar.bbdkp.adjustment_manager');
		$pools       = $phpbb_container->get('avathar.bbdkp.pool_manager');

		$action = $request->variable('action', '');
		$adj_id = $request->variable('adjustment_id', 0);

		$this->tpl_name   = 'acp_bbdkp_adjustment';
		$this->page_title = 'ACP_DKP_ADJUSTMENTS';

		try
		{
			switch ($action)
			{
				case 'add':
				case 'edit':
					$this->handle_form($adjustments, $pools, $adj_id, $request, $template, $user, $db);
					break;

				case 'delete':
					$adj = $adjustments->get_adjustment($adj_id);
					if ($adj && confirm_box(true))
					{
						$adjustments->delete_adjustment($adj_id);
						trigger_error(sprintf($user->lang['ADJ_DELETED'], $adj_id)
							. adm_back_link($this->u_action));
					}
					elseif ($adj)
					{
						confirm_box(false, $user->lang['ADJ_CONFIRM_DELETE'],
							build_hidden_fields(['action' => 'delete', 'adjustment_id' => $adj_id]));
					}
					break;
			}
		}
		catch (adjustment_state_exception $e)
		{
			trigger_error($user->lang[$e->getMessage()]
				. adm_back_link($this->u_action), E_USER_WARNING);
		}
		catch (\RuntimeException $e)
		{
			$key = $e->getMessage();
			$msg = isset($user->lang[$key]) ? $user->lang[$key] : $key;
			trigger_error($msg . adm_back_link($this->u_action), E_USER_WARNING);
		}

		$this->render_list($adjustments, $template);
		$template->assign_var('U_ACTION', $this->u_action);
	}

	private function render_list($adjustments, $template): void
	{
		foreach ($adjustments->list_adjustments() as $row)
		{
			$template->assign_block_vars('adjustments', [
				'ADJ_ID'           => (int) $row['adjustment_id'],
				'POOL_ID'          => (int) $row['pool_id'],
				'REASON'           => $row['adjustment_reason'],
				'DATE'             => (int) $row['adjustment_date'],
				'RECIPIENT_COUNT'  => (int) $row['recipient_count'],
				'TOTAL'            => $row['total_amount'],
				'U_EDIT'           => $this->u_action . '&amp;action=edit&amp;adjustment_id=' . (int) $row['adjustment_id'],
				'U_DELETE'         => $this->u_action . '&amp;action=delete&amp;adjustment_id=' . (int) $row['adjustment_id'],
			]);
		}
	}

	private function handle_form($adjustments, $pools, int $adj_id, $request, $template, $user, $db): void
	{
		$is_edit  = $adj_id > 0;
		$existing = $is_edit ? $adjustments->get_adjustment($adj_id) : null;

		if ($request->is_set_post('submit'))
		{
			$pool_id = $request->variable('pool_id', 0);
			$reason  = $request->variable('reason', '', true);
			$amount  = $request->variable('amount', '0');
			$recipient_ids = $request->variable('recipient_ids', [0]);

			if ($is_edit)
			{
				$adjustments->update_adjustment($adj_id, ['adjustment_reason' => $reason]);
				trigger_error(sprintf($user->lang['ADJ_UPDATED'], $adj_id)
					. adm_back_link($this->u_action));
			}
			else
			{
				$recipients = [];
				foreach ($recipient_ids as $pid)
				{
					$pid = (int) $pid;
					if ($pid === 0)
					{
						continue;
					}
					$recipients[] = ['player_id' => $pid, 'amount' => $amount];
				}

				$new_id = $adjustments->create_adjustment($pool_id, $reason, $recipients);
				trigger_error(sprintf($user->lang['ADJ_CREATED'], $new_id)
					. adm_back_link($this->u_action));
			}
		}

		// Pool options
		foreach ($pools->list_pools() as $p)
		{
			$template->assign_block_vars('pool_options', [
				'POOL_ID'   => (int) $p['pool_id'],
				'POOL_NAME' => $p['pool_name'],
				'GUILD_ID'  => (int) $p['guild_id'],
				'SELECTED'  => $is_edit && (int) $p['pool_id'] === (int) $existing['pool_id'],
			]);
		}

		// Player picker: in add mode only (edit mode disallows recipient changes in alpha2)
		if (!$is_edit)
		{
			$this->render_player_picker($db, $template);
		}
		else
		{
			foreach ($existing['recipients'] as $r)
			{
				$template->assign_block_vars('existing_recipients', [
					'RECIPIENT_ID' => (int) $r['recipient_id'],
					'PLAYER_ID'    => (int) $r['player_id'],
					'AMOUNT'       => $r['amount'],
				]);
			}
		}

		$template->assign_vars([
			'S_FORM'    => true,
			'S_IS_EDIT' => $is_edit,
			'ADJ_REASON' => $existing['adjustment_reason'] ?? '',
			'U_BACK'    => $this->u_action,
		]);
	}

	private function render_player_picker($db, $template): void
	{
		global $phpbb_container;
		try
		{
			$pool_table = $phpbb_container->getParameter('tables.bbdkp_pools');
			$pos = strrpos($pool_table, 'bb_dkp_pools');
			$prefix = $pos === false ? '' : substr($pool_table, 0, $pos);
		}
		catch (\Exception $e)
		{
			$prefix = '';
		}
		$players_table = $prefix . 'bb_players';

		$sql = 'SELECT player_id, player_name, player_realm, player_guild_id
			FROM ' . $players_table
			. ' ORDER BY player_guild_id, player_name ASC';
		$result = $db->sql_query_limit($sql, 200);
		while ($row = $db->sql_fetchrow($result))
		{
			$template->assign_block_vars('player_picker', [
				'PLAYER_ID'   => (int) $row['player_id'],
				'PLAYER_NAME' => $row['player_name'],
				'REALM'       => $row['player_realm'],
				'GUILD_ID'    => (int) $row['player_guild_id'],
			]);
		}
		$db->sql_freeresult($result);
	}
}
```

- [ ] **Step 2: Verify lints**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/acp/acp_adjustment_module.php
```
Expected: `No syntax errors detected`.

### Task 25: Create `acp_bbdkp_adjustment.html` template + commit phase 5

**Files:**
- Create: `ext/avathar/bbdkp/adm/style/acp_bbdkp_adjustment.html`

- [ ] **Step 1: Write the template**

Write `ext/avathar/bbdkp/adm/style/acp_bbdkp_adjustment.html`:
```html
<!-- INCLUDE overall_header.html -->

<a id="maincontent"></a>

<h1>{L_ACP_DKP_ADJUSTMENTS}</h1>
<p>{L_ACP_DKP_ADJUSTMENTS_EXPLAIN}</p>

<!-- IF S_FORM -->
	<form id="adj_form" method="post" action="{U_ACTION}">
		<fieldset>
			<legend><!-- IF S_IS_EDIT -->{L_ADJ_EDIT}<!-- ELSE -->{L_ADJ_ADD}<!-- ENDIF --></legend>

			<dl>
				<dt><label for="pool_id">{L_ADJ_POOL}:</label></dt>
				<dd>
					<select id="pool_id" name="pool_id"<!-- IF S_IS_EDIT --> disabled<!-- ENDIF --> required>
					<!-- BEGIN pool_options -->
						<option value="{pool_options.POOL_ID}"<!-- IF pool_options.SELECTED --> selected<!-- ENDIF -->>{pool_options.POOL_NAME}</option>
					<!-- BEGINELSE -->
						<option value="0">— no pools —</option>
					<!-- END pool_options -->
					</select>
				</dd>
			</dl>

			<dl>
				<dt><label for="reason">{L_ADJ_REASON}:</label></dt>
				<dd><input type="text" id="reason" name="reason" value="{ADJ_REASON}" maxlength="255" required></dd>
			</dl>

			<!-- IF not S_IS_EDIT -->
				<dl>
					<dt><label for="amount">{L_ADJ_AMOUNT}:</label></dt>
					<dd><input type="text" id="amount" name="amount" value="0" pattern="^-?[0-9]+(\.[0-9]+)?$" required></dd>
				</dl>

				<fieldset>
					<legend>{L_ADJ_RECIPIENTS}</legend>
					<table class="table1">
						<thead><tr><th>+</th><th>Player</th><th>Guild</th></tr></thead>
						<tbody>
						<!-- BEGIN player_picker -->
							<tr>
								<td><input type="checkbox" name="recipient_ids[]" value="{player_picker.PLAYER_ID}"></td>
								<td>{player_picker.PLAYER_NAME}<!-- IF player_picker.REALM --> ({player_picker.REALM})<!-- ENDIF --></td>
								<td>{player_picker.GUILD_ID}</td>
							</tr>
						<!-- BEGINELSE -->
							<tr><td colspan="3"><em>(no players)</em></td></tr>
						<!-- END player_picker -->
						</tbody>
					</table>
				</fieldset>
			<!-- ELSE -->
				<fieldset>
					<legend>{L_ADJ_RECIPIENTS}</legend>
					<table class="table1">
						<thead><tr><th>Player</th><th>{L_ADJ_AMOUNT}</th></tr></thead>
						<tbody>
						<!-- BEGIN existing_recipients -->
							<tr><td>player #{existing_recipients.PLAYER_ID}</td><td>{existing_recipients.AMOUNT}</td></tr>
						<!-- BEGINELSE -->
							<tr><td colspan="2"><em>(none)</em></td></tr>
						<!-- END existing_recipients -->
						</tbody>
					</table>
					<p><em>Recipient lists are immutable after creation in alpha2; delete + re-add to change them.</em></p>
				</fieldset>
			<!-- ENDIF -->

			<p class="submit-buttons">
				<input type="submit" name="submit" value="{L_SUBMIT}" class="button1">
				&nbsp; <a href="{U_BACK}">{L_CANCEL}</a>
			</p>
			{S_FORM_TOKEN}
		</fieldset>
	</form>
<!-- ELSE -->
	<p><a href="{U_ACTION}&amp;action=add" class="button1">{L_ADJ_ADD}</a></p>

	<table class="table1">
		<thead>
			<tr>
				<th>{L_ADJ_REASON}</th>
				<th>{L_ADJ_POOL}</th>
				<th>{L_ADJ_DATE}</th>
				<th>{L_ADJ_RECIPIENTS}</th>
				<th>{L_ADJ_TOTAL}</th>
				<th>{L_ADJ_ACTIONS}</th>
			</tr>
		</thead>
		<tbody>
		<!-- BEGIN adjustments -->
			<tr>
				<td>{adjustments.REASON}</td>
				<td>#{adjustments.POOL_ID}</td>
				<td>{adjustments.DATE}</td>
				<td>{adjustments.RECIPIENT_COUNT}</td>
				<td>{adjustments.TOTAL}</td>
				<td>
					<a href="{adjustments.U_EDIT}">{L_ADJ_EDIT}</a>
					| <a href="{adjustments.U_DELETE}">{L_ADJ_DELETE}</a>
				</td>
			</tr>
		<!-- BEGINELSE -->
			<tr><td colspan="6"><em>{L_ADJ_NONE}</em></td></tr>
		<!-- END adjustments -->
		</tbody>
	</table>
<!-- ENDIF -->

<!-- INCLUDE overall_footer.html -->
```

- [ ] **Step 2: Sync + commit phase 5**

Run:
```bash
rsync -a --exclude='.git' --exclude='.DS_Store' \
  /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/ \
  /Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP/

cd /Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP \
  && git add -A \
  && git commit -m "feat: adjustment ACP module — full CRUD

acp_adjustment_info + acp_adjustment_module + adm/style template +
info_acp_adjustment language file. Add mode renders the bulk picker
(multi-select players, single signed amount, single reason). Edit
mode allows reason text only; recipient list changes via delete +
re-add (full recipient editing is alpha3+). Delete uses confirm_box.

Module registration happens in the alpha2 migration (Task 26)."
```

---

## Phase 6 — Migration

### Task 26: Create the alpha2 migration

**Files:**
- Create: `ext/avathar/bbdkp/migrations/v200a2/bbdkp_v2_alpha2.php`

- [ ] **Step 1: Create the directory**

Run:
```bash
mkdir -p /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/migrations/v200a2
```

- [ ] **Step 2: Write the migration**

Write `ext/avathar/bbdkp/migrations/v200a2/bbdkp_v2_alpha2.php`:
```php
<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\migrations\v200a2;

/**
 * v2.0.0-alpha2 data migration.
 *
 * Registers the two new ACP modules (ACP_DKP_RAIDS and ACP_DKP_ADJUSTMENTS)
 * under the alpha1 ACP_DKP category. Bumps bbdkp_version.
 *
 * No schema changes — alpha1 created all required tables.
 */
class bbdkp_v2_alpha2 extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return ['\\avathar\\bbdkp\\migrations\\bbdkp_install'];
	}

	public function effectively_installed()
	{
		return isset($this->config['bbdkp_version'])
			&& version_compare($this->config['bbdkp_version'], '2.0.0-alpha2', '>=');
	}

	public function update_data()
	{
		return [
			['module.add', ['acp', 'ACP_DKP', [
				'module_basename' => '\\avathar\\bbdkp\\acp\\acp_raid_module',
				'module_langname' => 'ACP_DKP_RAIDS',
				'module_mode'     => 'list',
				'module_auth'     => 'ext_avathar/bbdkp && acl_a_bbdkp',
			]]],

			['module.add', ['acp', 'ACP_DKP', [
				'module_basename' => '\\avathar\\bbdkp\\acp\\acp_adjustment_module',
				'module_langname' => 'ACP_DKP_ADJUSTMENTS',
				'module_mode'     => 'list',
				'module_auth'     => 'ext_avathar/bbdkp && acl_a_bbdkp',
			]]],

			['config.update', ['bbdkp_version', '2.0.0-alpha2']],
		];
	}

	public function revert_data()
	{
		return [
			['module.remove', ['acp', false, 'ACP_DKP_ADJUSTMENTS']],
			['module.remove', ['acp', false, 'ACP_DKP_RAIDS']],
			['config.update', ['bbdkp_version', '2.0.0-alpha1']],
		];
	}
}
```

- [ ] **Step 3: Verify lints**

Run:
```bash
php -l /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/migrations/v200a2/bbdkp_v2_alpha2.php
```
Expected: `No syntax errors detected`.

### Task 27: Sync + commit phase 6

- [ ] **Step 1: Sync + commit**

Run:
```bash
rsync -a --exclude='.git' --exclude='.DS_Store' \
  /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/ \
  /Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP/

cd /Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP \
  && git add -A \
  && git commit -m "feat: alpha2 install migration

Registers ACP_DKP_RAIDS and ACP_DKP_ADJUSTMENTS under the alpha1
ACP_DKP category and bumps bbdkp_version to 2.0.0-alpha2. No schema
changes — alpha1 created every table the new services use."
```

---

## Phase 7 — Release prep

### Task 28: Bump composer.json version

**Files:**
- Modify: `ext/avathar/bbdkp/composer.json`

- [ ] **Step 1: Bump version and time**

Read current contents:
```bash
cat /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/composer.json
```

Edit `ext/avathar/bbdkp/composer.json`: change the two top-level fields to:
```json
    "version": "2.0.0-alpha2",
    "time": "2026-05-26",
```
Leave the rest untouched.

- [ ] **Step 2: Verify JSON parses**

Run:
```bash
php -r "json_decode(file_get_contents('/Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/composer.json'), false, 512, JSON_THROW_ON_ERROR); echo 'ok';"
```
Expected: prints `ok`.

### Task 29: Update CHANGELOG.md with alpha2 entry

**Files:**
- Modify: `ext/avathar/bbdkp/CHANGELOG.md`

- [ ] **Step 1: Add the alpha2 section above the existing alpha1 section**

Find the line `## [2.0.0-alpha1] — 2026-05-23` and insert directly above it:
```markdown
## [2.0.0-alpha2] — 2026-05-26

First posting surface ships: admins can record raids with attendance and
issue manual DKP adjustments. Every action now writes to phpBB's mod log
through bbDKP-specific log types.

### Added

- **`raid_manager` service + raid ACP module** — full CRUD with
  multi-select character picker for attendance, per-attendee
  `value_override` field, and edit-raid-value cascade that reverses
  prior ledger entries and reposts at the new amount (improves on the
  legacy bbDKPMOD which rewrote balances in place).
- **`adjustment_manager` service + adjustment ACP module** — bulk-add
  with multi-select recipients, single signed amount, single reason,
  one shared `group_key` per adjustment.
- **Log type registration** — new `event/log_listener.php` subscriber
  registers 13 bbDKP log type translations (DKPSYS_*, EVENT_*, RAID_*,
  INDIVADJ_*, PLAYERDKP_*) via `core.user_setup`. All four CRUD services
  — including the alpha1 pool and event paths — now write entries to
  `phpbb_log` so admin actions appear in ACP → System → Admin log.
- **Migration `v200a2`** registers the two new ACP modes and bumps
  `bbdkp_version`. No schema changes.

### Notes

- Attendee membership edits (add/remove individual players on an
  existing raid) and recipient list edits on an existing adjustment
  go through delete + re-add in alpha2; full in-place editing of
  these collections is alpha3+.
- Automated test suite still deferred (alpha3 / first CI-ready alpha).
- `dkp_ledger` itself is unchanged from alpha1.
```

### Task 30: Manual smoke test checklist

**Files:** none (verification only — does not block tagging on failure since this is alpha)

Per spec §11, run through these in a freshly enabled bbDKP install with bbGuild + bbAccounts present:

- [ ] **Step 1: Verify ACP modules appear**

In phpBB ACP, navigate to ACP_BBGUILD_MAINPAGE → ACP_DKP. Expected: four sub-items visible — Pools, Events, Raids, Adjustments.

- [ ] **Step 2: Create one pool + one event**

Create a pool ("Test Pool"), create an event under it ("Test Event", value 50). Expected: both appear in their lists; mod log shows `LOG_DKPSYS_ADDED` and `LOG_EVENT_ADDED` entries.

- [ ] **Step 3: Add a raid with 3 attendees**

Add a raid in Test Pool / Test Event, pick 3 players from the picker, leave override fields blank. Submit. Expected: redirect to list, raid row visible with attendee_count=3, three `bb_dkp_raid_attendees` rows present, three `bb_dkp_ledger_link` rows with `entity_type='raid_attendee'`, balances on each player wallet account in bbAccounts = +50.

- [ ] **Step 4: Edit raid value to 75**

Edit the raid, change raid_value 50 → 75, submit. Expected: each of the three player wallet balances becomes +75; six new `bb_dkp_ledger_link` rows total (three reversals + three forwards).

- [ ] **Step 5: Delete raid**

Confirm-box → delete. Expected: each player wallet balance returns to 0; nine total link rows (three original forwards + three reversals from step 4 + three reversals from step 5); raid metadata gone; mod log shows `LOG_RAID_DELETED`.

- [ ] **Step 6: Bulk +50 adjustment**

Add adjustment, reason="bonus", amount=+50, pick 5 players, submit. Expected: balances each +50; one adjustment header with `group_key` shared across 5 recipient rows; 5 ledger entries posted.

- [ ] **Step 7: Bulk -50 adjustment**

Add adjustment, reason="penalty", amount=-50, same 5 players, submit. Expected: balances back to 0 net; new header + 5 new recipient rows + 5 new ledger entries.

- [ ] **Step 8: Delete adjustment**

Delete the bonus adjustment. Expected: 5 reversals posted; final balances = -50 each (only penalty remains).

- [ ] **Step 9: Negative-path tests**

Try each of these and confirm a clean error message (not a blank page or stack trace):

| Action | Expected message key |
|---|---|
| Add raid with 0 attendees | RAID_NO_ATTENDEES |
| Add raid with two of the same player | RAID_DUPLICATE_ATTENDEE |
| Disable a pool, then try to add a raid against it | RAID_POOL_DISABLED |
| Add adjustment with 0 recipients | ADJ_NO_RECIPIENTS |
| Add adjustment with amount=0 | ADJ_ZERO_AMOUNT |

If any step above fails, **stop and fix before proceeding to Task 31**. Add a follow-up commit summarising the fix.

### Task 31: Tag + push v2.0.0-alpha2

- [ ] **Step 1: Confirm clean tree in dev repo**

Run:
```bash
cd /Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP && git status
```
Expected: `nothing to commit, working tree clean`. If composer.json / CHANGELOG changes from Tasks 28-29 aren't committed yet, sync + commit:
```bash
rsync -a --exclude='.git' --exclude='.DS_Store' \
  /Users/Andreas/Sites/avathar/forum/ext/avathar/bbdkp/ \
  /Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP/
cd /Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP \
  && git add -A \
  && git commit -m "chore: release 2.0.0-alpha2

Bumps composer.json version + time. CHANGELOG carries the alpha2
section above alpha1."
```

- [ ] **Step 2: Tag and push**

Run:
```bash
cd /Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP \
  && git tag -a v2.0.0-alpha2 -m "bbDKP 2.0.0-alpha2 — raid + adjustment CRUD + log type registration" \
  && git push origin main \
  && git push origin v2.0.0-alpha2
```
Expected: `[new tag] v2.0.0-alpha2 -> v2.0.0-alpha2`.

- [ ] **Step 3: Create GitHub Release**

Run:
```bash
cd /Users/Andreas/development/PHP/phpbb33_extensions/avathar/bbDKP \
  && gh release create v2.0.0-alpha2 -R avatharbe/bbDKP \
     --prerelease \
     --title "bbDKP 2.0.0-alpha2" \
     --notes-file CHANGELOG.md
```
Expected: prints the release URL `https://github.com/avatharbe/bbDKP/releases/tag/v2.0.0-alpha2`.

### Task 32: Update memory

- [ ] **Step 1: Update `released_versions.md`**

Edit `/Users/Andreas/.claude/projects/-Users-Andreas/memory/released_versions.md`: in the "Latest GitHub releases" table, change the bbDKP row to:
```
| `avatharbe/bbDKP` | `v2.0.0-alpha2` | 2026-05-26 |
```

- [ ] **Step 2: Update `bbdkp_v2_plan.md`**

In `/Users/Andreas/.claude/projects/-Users-Andreas/memory/bbdkp_v2_plan.md`:

Replace the heading `## Phase 1 — Ship as modern extension with bbAccounts integration (DONE — v2.0.0-alpha1, 2026-05-24)` with `## Released alphas` and add a sub-section:
```markdown
### v2.0.0-alpha2 (2026-05-26)

- raid_manager service + raid ACP module (multi-select picker, cascade
  reverse+repost on raid_value edits)
- adjustment_manager service + adjustment ACP module (bulk multi-select,
  single signed amount)
- Log type registration via core.user_setup; backfilled $phpbb_log->add()
  calls in pool_manager + event_manager
- Migration v200a2 registers the two new ACP modes
- Tag: v2.0.0-alpha2, SHA (insert from Task 31 output)
```

In the "Phase 1.5 — Remaining alpha cycle (NEXT)" section, strike out the alpha2 line and shift focus to alpha3 (item catalog + loot ACP CRUD).

---

## Spec coverage check (self-review after writing the plan)

This plan covers every section of `contrib/specs/2026-05-24-bbdkp-v2-alpha2-design.md`:

- §1 Summary — services + ACP modules + log_listener all in plan (Tasks 1-26)
- §2 Goals — raid CRUD (Tasks 9-12, 15), adjustment CRUD (Tasks 19-20, 24), edit-raid cascade (Task 10), delete reverses (Tasks 11, 20), log calls everywhere (Tasks 1-5, 9-12, 20), plain-array APIs (Tasks 8, 19)
- §2 Non-goals — honored (no tests, no bulk-import, no schema migration, no new perms)
- §3 Decisions log A1-A9 — A1 in Task 15, A2 in Task 24, A3 in Task 10, A4 in Tasks 11+20, A5 in Tasks 1-5, A6 in Tasks 8+19, A7 in Task 26 (note in migration), A8 in Task 20 (`unique_id()`), A9 in plan §forward-compat
- §4 Architecture — Tasks 3 (services.yml wiring), 9-12, 20, 15, 24
- §5 Data model — no new tables; audit columns populated everywhere (Tasks 9, 20)
- §6 Services — raid_manager (8-12), adjustment_manager (19-20), log_listener (2)
- §6.4 inline log call sites — table covered: pool (Task 4), event (Task 5), raid (Tasks 9-12), adjustment (Task 20)
- §7 Data flow — 7.1 in Task 9, 7.2 in Task 10, 7.3 in Task 10, 7.4 in Task 11, 7.5 in Task 20, 7.6 in Task 20
- §8 Module + route surface — Tasks 13-16, 22-25, 26
- §9 Files & folders — every file listed appears in a task
- §10 Error handling — exception classes (Tasks 7, 18); typed message-key contract (Tasks 13, 22); try/catch in ACP modules (Tasks 15, 24)
- §11 Testing — manual checklist in Task 30
- §12 Forward-compat — managers take plain arrays; documented in interface docblocks (Tasks 8, 19)
- §13 Improvements — encoded in CHANGELOG (Task 29) and raid_manager docblock (Task 8)
- §14 Open assumptions — assumption #1 `\phpbb\log\log_interface` resolved via `@log` service id (Task 3); #2 `unique_id()` used (Task 20); #3 custom picker (Tasks 15, 24); #4 player_guild_id used directly in queries
- §15 Release plan — Tasks grouped into 7 phases matching §15 exactly
- §16 Next steps — Task 32 closes the loop

No requirement is unaddressed.

---

## Notes for the implementer

- This plan deliberately **does not write tests** (per spec §2 and §11). The
  manual smoke checklist in Task 30 is the substitute. Restoring the
  phpBB test framework is a higher-priority task than backfilling these
  tests — when that happens, alpha3 or beta1 should include the backfill.
- The `find_live_link` helper appears in both `raid_manager` and
  `adjustment_manager`. This duplication is intentional for alpha2 to
  keep both services self-contained. If a third caller appears in
  alpha3 (loot), extract it to a shared utility class then.
- The `players_table()` / `render_player_picker()` prefix resolution
  trick is a workaround for the fact that bbGuild's `PLAYERS_TABLE`
  global may not be defined when bbDKP code runs first. If a cleaner
  service for resolving bbGuild's table prefix appears in bbGuild, swap
  to it.
- All commits go in the dev repo (`/Users/Andreas/development/...`).
  Working-copy edits inside `/Users/Andreas/Sites/avathar/forum/...` are
  rsync'd in before each commit per the user's standing workflow
  (memory `feedback_rt_working_copy.md`).
