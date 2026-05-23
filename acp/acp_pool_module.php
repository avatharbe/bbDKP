<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\acp;

/**
 * Placeholder pool ACP module. Replaced with full CRUD in Phase 6.
 */
class acp_pool_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	public function main($id, $mode)
	{
		global $template, $user;

		$user->add_lang_ext('avathar/bbdkp', ['common', 'info_acp_pool']);

		$this->tpl_name   = 'acp_bbdkp_pool';
		$this->page_title = 'ACP_DKP_POOLS';

		$template->assign_var('U_ACTION', $this->u_action);
	}
}
