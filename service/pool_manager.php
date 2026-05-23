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

	/** @var string */
	private $table_pools;

	public function __construct(
		\phpbb\db\driver\driver_interface $db,
		dkp_ledger_interface $ledger,
		\phpbb\user $user,
		string $table_pools
	)
	{
		$this->db = $db;
		$this->ledger = $ledger;
		$this->user = $user;
		$this->table_pools = $table_pools;
	}

	public function create_pool(int $guild_id, string $name, string $desc = ''): int
	{
		// Pre-check duplicate so consumers get a clean exception rather
		// than a DBAL UNIQUE-constraint failure.
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
		// In alpha1, delete_pool_accounts always throws; the ACP module
		// catches the RuntimeException and surfaces POOL_DELETE_BLOCKED.
		$this->ledger->delete_pool_accounts($pool_id);

		$this->db->sql_query('DELETE FROM ' . $this->table_pools
			. ' WHERE pool_id = ' . (int) $pool_id);
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
