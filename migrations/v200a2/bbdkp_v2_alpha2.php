<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\migrations\v200a2;

/**
 * v2.0.0-alpha2 migration.
 *
 * Schema: adds audit columns (added_by/added_at/updated_by/updated_at) to
 * bb_dkp_raid_attendees. These were omitted from alpha1's install migration
 * but are required by raid_manager's insert_attendee + persist_attendee_override.
 *
 * Data: registers ACP_DKP_RAIDS and ACP_DKP_ADJUSTMENTS under the alpha1
 * ACP_DKP category. Bumps bbdkp_version.
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

	public function update_schema()
	{
		return [
			'add_columns' => [
				$this->table_prefix . 'bb_dkp_raid_attendees' => [
					'added_by'   => ['UINT', 0],
					'added_at'   => ['TIMESTAMP', 0],
					'updated_by' => ['UINT', 0],
					'updated_at' => ['TIMESTAMP', 0],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_columns' => [
				$this->table_prefix . 'bb_dkp_raid_attendees' => [
					'added_by', 'added_at', 'updated_by', 'updated_at',
				],
			],
		];
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
