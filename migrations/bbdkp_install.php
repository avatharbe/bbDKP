<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\migrations;

/**
 * Single squashed install for bbDKP v2.0.0-alpha1.
 *
 * Creates all metadata tables (pools, events, raids, attendees, items, loot,
 * adjustments, adjustment_recipients, ledger_link), registers permissions
 * and the top-level ACP_DKP category. Pool / event ACP module registration
 * happens via the matching `acp_*_info` files. This migration uses
 * effectively_installed to short-circuit re-runs.
 *
 * bbAccounts (≥1.1.0-alpha) is a hard runtime dependency declared in
 * ext.php::is_enableable(); this migration does NOT depend on bbAccounts'
 * migrations explicitly because phpBB resolves extension order at enable
 * time.
 */
class bbdkp_install extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return ['\\phpbb\\db\\migration\\data\\v330\\v330'];
	}

	public function effectively_installed()
	{
		return isset($this->config['bbdkp_version'])
			&& version_compare($this->config['bbdkp_version'], '2.0.0-alpha1', '>=');
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'bb_dkp_pools' => [
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
						'idx_guild'       => ['INDEX', 'guild_id'],
					],
				],
				$this->table_prefix . 'bb_dkp_events' => [
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
				],
				$this->table_prefix . 'bb_dkp_raids' => [
					'COLUMNS' => [
						'raid_id'        => ['UINT', null, 'auto_increment'],
						'guild_id'       => ['UINT', 0],
						'pool_id'        => ['UINT', 0],
						'event_id'       => ['UINT', 0],
						'raid_start'     => ['TIMESTAMP', 0],
						'raid_end'       => ['TIMESTAMP', 0],
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
						'idx_event'      => ['INDEX', 'event_id'],
						'idx_start'      => ['INDEX', 'raid_start'],
					],
				],
				$this->table_prefix . 'bb_dkp_raid_attendees' => [
					'COLUMNS' => [
						'attendee_id'    => ['UINT', null, 'auto_increment'],
						'raid_id'        => ['UINT', 0],
						'player_id'      => ['UINT', 0],
						'join_time'      => ['TIMESTAMP', 0],
						'leave_time'     => ['TIMESTAMP', 0],
						'value_override' => ['DECIMAL:11', 0],
					],
					'PRIMARY_KEY' => 'attendee_id',
					'KEYS' => [
						'raid_player' => ['UNIQUE', ['raid_id', 'player_id']],
						'idx_player'  => ['INDEX', 'player_id'],
					],
				],
				$this->table_prefix . 'bb_dkp_items' => [
					'COLUMNS' => [
						'item_id'        => ['UINT', null, 'auto_increment'],
						'game_id'        => ['VCHAR:10', ''],
						'item_name'      => ['VCHAR_UNI:255', ''],
						'item_gameid'    => ['VCHAR:50', ''],
						'itempool_id'    => ['UINT', 0],
						'added_by'       => ['UINT', 0],
						'added_at'       => ['TIMESTAMP', 0],
						'updated_by'     => ['UINT', 0],
						'updated_at'     => ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'item_id',
					'KEYS' => [
						'game_name'   => ['UNIQUE', ['game_id', 'item_name']],
						'idx_gameid'  => ['INDEX', 'item_gameid'],
					],
				],
				$this->table_prefix . 'bb_dkp_loot' => [
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
						'idx_raid'        => ['INDEX', 'raid_id'],
						'idx_item'        => ['INDEX', 'item_id'],
						'idx_player_date' => ['INDEX', ['player_id', 'drop_date']],
					],
				],
				$this->table_prefix . 'bb_dkp_adjustments' => [
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
				],
				$this->table_prefix . 'bb_dkp_adjustment_recipients' => [
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
				],
				$this->table_prefix . 'bb_dkp_ledger_link' => [
					'COLUMNS' => [
						'link_id'            => ['UINT', null, 'auto_increment'],
						'entity_type'        => ['VCHAR:32', ''],
						'entity_id'          => ['UINT', 0],
						'journal_entry_id'   => ['UINT', 0],
						'reversal_of'        => ['UINT', 0],
						'posted_at'          => ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'link_id',
					'KEYS' => [
						'idx_entity'   => ['INDEX', ['entity_type', 'entity_id']],
						'idx_je'       => ['INDEX', 'journal_entry_id'],
						'idx_reversal' => ['INDEX', 'reversal_of'],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'bb_dkp_ledger_link',
				$this->table_prefix . 'bb_dkp_adjustment_recipients',
				$this->table_prefix . 'bb_dkp_adjustments',
				$this->table_prefix . 'bb_dkp_loot',
				$this->table_prefix . 'bb_dkp_items',
				$this->table_prefix . 'bb_dkp_raid_attendees',
				$this->table_prefix . 'bb_dkp_raids',
				$this->table_prefix . 'bb_dkp_events',
				$this->table_prefix . 'bb_dkp_pools',
			],
		];
	}

	public function update_data()
	{
		return [
			['config.add', ['bbdkp_version', '2.0.0-alpha1']],

			// Permissions
			['permission.add', ['a_bbdkp', true]],
			['permission.add', ['m_bbdkp', true]],
			['permission.add', ['u_bbdkp_view', true]],
			['permission.add', ['u_bbdkp_view_others', true]],

			['permission.permission_set', ['ROLE_ADMIN_FULL',    'a_bbdkp']],
			['permission.permission_set', ['ROLE_MOD_FULL',      'm_bbdkp']],
			['permission.permission_set', ['ROLE_USER_STANDARD', 'u_bbdkp_view']],
			['permission.permission_set', ['ROLE_USER_FULL',     'u_bbdkp_view']],
			['permission.permission_set', ['ROLE_USER_FULL',     'u_bbdkp_view_others']],
			['permission.permission_set', ['GUESTS',             'u_bbdkp_view', 'group']],
			['permission.permission_set', ['REGISTERED',         'u_bbdkp_view', 'group']],

			// ACP module registration: top-level ACP_DKP category under
			// bbGuild's ACP_BBGUILD_MAINPAGE, plus pool + event modes.
			['module.add', ['acp', 'ACP_BBGUILD_MAINPAGE', [
				'module_basename' => '',
				'module_langname' => 'ACP_DKP',
				'module_mode'     => '',
				'module_auth'     => 'ext_avathar/bbdkp && acl_a_bbdkp',
			]]],

			['module.add', ['acp', 'ACP_DKP', [
				'module_basename' => '\\avathar\\bbdkp\\acp\\acp_pool_module',
				'module_langname' => 'ACP_DKP_POOLS',
				'module_mode'     => 'list',
				'module_auth'     => 'ext_avathar/bbdkp && acl_a_bbdkp',
			]]],

			['module.add', ['acp', 'ACP_DKP', [
				'module_basename' => '\\avathar\\bbdkp\\acp\\acp_event_module',
				'module_langname' => 'ACP_DKP_EVENTS',
				'module_mode'     => 'list',
				'module_auth'     => 'ext_avathar/bbdkp && acl_a_bbdkp',
			]]],
		];
	}

	public function revert_data()
	{
		return [
			['module.remove', ['acp', false, 'ACP_DKP_EVENTS']],
			['module.remove', ['acp', false, 'ACP_DKP_POOLS']],
			['module.remove', ['acp', false, 'ACP_DKP']],

			['permission.remove', ['a_bbdkp']],
			['permission.remove', ['m_bbdkp']],
			['permission.remove', ['u_bbdkp_view']],
			['permission.remove', ['u_bbdkp_view_others']],

			['config.remove', ['bbdkp_version']],
		];
	}
}
