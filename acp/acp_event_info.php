<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

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
