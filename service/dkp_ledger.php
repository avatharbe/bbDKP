<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
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
	 * pool ids (worst case `dkp_p999999_w` = 13 chars). Names are
	 * human-readable longer strings.
	 *
	 * Only `player_wallets` is subledger='character'; the four counter
	 * accounts are non-subledger expense/revenue.
	 */
	private const ROLES = [
		'player_wallets'  => ['suffix' => 'w',  'type' => 'liability', 'subledger' => 'character', 'name_suffix' => 'Player Wallets'],
		'raid_attendance' => ['suffix' => 'ra', 'type' => 'expense',   'subledger' => '',          'name_suffix' => 'Raid Attendance'],
		'loot_proceeds'   => ['suffix' => 'lp', 'type' => 'revenue',   'subledger' => '',          'name_suffix' => 'Loot Proceeds'],
		'adjust_credit'   => ['suffix' => 'ac', 'type' => 'expense',   'subledger' => '',          'name_suffix' => 'Adjustment Credits'],
		'adjust_debit'    => ['suffix' => 'ad', 'type' => 'revenue',   'subledger' => '',          'name_suffix' => 'Adjustment Debits'],
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

	public function archive_pool_accounts(int $pool_id): void
	{
		// no-op in v2.0.0-alpha1 (see interface docblock)
	}

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
				['account_id' => $expense_id, 'debit' => $amount, 'credit' => '0.00',  'subledger_user_id' => 0, 'subledger_player_id' => 0,          'memo' => ''],
				['account_id' => $wallet_id,  'debit' => '0.00',  'credit' => $amount, 'subledger_user_id' => 0, 'subledger_player_id' => $player_id, 'memo' => ''],
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
