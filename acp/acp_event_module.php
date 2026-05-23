<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\acp;

/**
 * Placeholder event ACP module. Replaced with full CRUD in Phase 7.
 */
class acp_event_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	public function main($id, $mode)
	{
		global $template, $user;

		$user->add_lang_ext('avathar/bbdkp', ['common', 'info_acp_event']);

		$this->tpl_name   = 'acp_bbdkp_event';
		$this->page_title = 'ACP_DKP_EVENTS';

		$template->assign_var('U_ACTION', $this->u_action);
	}
}
