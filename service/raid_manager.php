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
		// Sentinel: value_override = 0 means "use raid default" (alpha1 schema
		// made the column non-null DECIMAL with default 0; same pattern as
		// reversal_of in ledger_link). To override to literal 0 DKP, use a
		// post-raid adjustment.
		$old_effective = ((float) $row['value_override'] === 0.0)
			? (string) $raid['raid_value']
			: (string) $row['value_override'];
		$new_override = $fields['value_override'];
		$new_effective = ($new_override === null || (float) $new_override === 0.0)
			? (string) $raid['raid_value']
			: (string) $new_override;

		if ($old_effective === $new_effective)
		{
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

	public function add_attendees(int $raid_id, array $attendees): void
	{
		$raid = $this->get_raid_row($raid_id);
		if (!$raid)
		{
			throw new raid_state_exception('RAID_NOT_FOUND');
		}

		$this->validate_attendees($attendees);

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

	// ----- private helpers --------------------------------------------------

	private function insert_attendee(int $raid_id, array $att, int $now, int $uid): int
	{
		// value_override = 0 → "use raid_value default"; non-zero → explicit per-attendee amount.
		$override = isset($att['value_override']) && $att['value_override'] !== null
			? (string) $att['value_override']
			: '0';
		$att_ary = [
			'raid_id'        => $raid_id,
			'player_id'      => (int) $att['player_id'],
			'value_override' => $override,
			'join_time'      => 0,
			'leave_time'     => 0,
			'added_by'       => $uid,
			'added_at'       => $now,
			'updated_by'     => $uid,
			'updated_at'     => $now,
		];
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

	private function cascade_value_change(int $raid_id, int $pool_id, string $new_value): void
	{
		// value_override = 0 means "use raid default" — cascade reposts those
		// at the new raid_value. Attendees with a non-zero explicit override
		// are left alone.
		$sql = 'SELECT attendee_id, player_id FROM ' . $this->table_attendees
			. ' WHERE raid_id = ' . $raid_id
			. ' AND value_override = 0';
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
		// "Live" = a forward post (reversal_of = 0; alpha1's dkp_ledger
		// uses 0 as the sentinel since the column is non-null UINT) that
		// has not itself been reversed (no other link row points at this
		// link_id via reversal_of).
		$type = $this->db->sql_escape($entity_type);
		$sql = 'SELECT a.link_id
			FROM ' . $this->table_ledger_link . " a
			WHERE a.entity_type = '{$type}'
			AND a.entity_id = " . $entity_id . '
			AND a.reversal_of = 0
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
		// null or '0' → "clear override" = store the 0-sentinel.
		$override = ($value_override === null) ? '0' : (string) $value_override;
		$sql_ary = [
			'value_override' => $override,
			'updated_at'     => time(),
			'updated_by'     => (int) $this->user->data['user_id'],
		];
		$this->db->sql_query('UPDATE ' . $this->table_attendees . ' SET '
			. $this->db->sql_build_array('UPDATE', $sql_ary)
			. ' WHERE attendee_id = ' . $attendee_id);
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
}
