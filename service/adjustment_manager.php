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
		// "Live" = a forward post (reversal_of = 0; alpha1's dkp_ledger uses 0
		// as the sentinel since the column is non-null UINT) that has not
		// itself been reversed (no other link row points at this link_id).
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
}
