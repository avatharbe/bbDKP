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
