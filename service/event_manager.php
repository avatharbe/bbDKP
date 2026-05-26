<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\service;

class event_manager implements event_manager_interface
{
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

	public function create_event(
		int $pool_id,
		string $name,
		string $default_value = '0.00',
		string $color = '',
		string $icon = ''
	): int
	{
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
		$event_id = (int) $this->db->sql_nextid();

		$this->log->add('admin', $uid, $this->user->ip, 'LOG_EVENT_ADDED', false, [$name]);

		return $event_id;
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

		$event = $this->get_event($event_id);
		if ($event)
		{
			$this->log->add('admin', (int) $this->user->data['user_id'], $this->user->ip,
				'LOG_EVENT_UPDATED', false, [$event['event_name']]);
		}
	}

	public function disable_event(int $event_id): void
	{
		$this->update_event($event_id, ['event_status' => 0]);
	}

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
