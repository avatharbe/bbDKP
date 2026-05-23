<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\acp;

class acp_pool_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	public function main($id, $mode)
	{
		global $phpbb_container, $request, $template, $user;

		$user->add_lang_ext('avathar/bbdkp', ['common', 'info_acp_pool']);

		$pools = $phpbb_container->get('avathar.bbdkp.pool_manager');

		$action  = $request->variable('action', '');
		$pool_id = $request->variable('pool_id', 0);

		$this->tpl_name   = 'acp_bbdkp_pool';
		$this->page_title = 'ACP_DKP_POOLS';

		try
		{
			switch ($action)
			{
				case 'add':
				case 'edit':
					$this->handle_form($pools, $pool_id, $request, $template, $user);
					break;

				case 'disable':
					$pool = $pools->get_pool($pool_id);
					if ($pool && confirm_box(true))
					{
						$pools->disable_pool($pool_id);
						trigger_error(sprintf($user->lang['POOL_DISABLED'], $pool['pool_name'])
							. adm_back_link($this->u_action));
					}
					elseif ($pool)
					{
						confirm_box(false, $user->lang['POOL_CONFIRM_DISABLE'],
							build_hidden_fields(['action' => 'disable', 'pool_id' => $pool_id]));
					}
					break;

				case 'delete':
					$pool = $pools->get_pool($pool_id);
					if ($pool && confirm_box(true))
					{
						try
						{
							$pools->delete_pool($pool_id);
							trigger_error(sprintf($user->lang['POOL_DELETED'], $pool['pool_name'])
								. adm_back_link($this->u_action));
						}
						catch (\RuntimeException $e)
						{
							trigger_error($user->lang['POOL_DELETE_BLOCKED']
								. adm_back_link($this->u_action), E_USER_WARNING);
						}
					}
					elseif ($pool)
					{
						confirm_box(false, $user->lang['POOL_CONFIRM_DELETE'],
							build_hidden_fields(['action' => 'delete', 'pool_id' => $pool_id]));
					}
					break;

				case 'set_default':
					$pool = $pools->get_pool($pool_id);
					if ($pool)
					{
						$pools->set_default($pool_id);
						trigger_error(sprintf($user->lang['POOL_DEFAULT_SET'], $pool['pool_name'])
							. adm_back_link($this->u_action));
					}
					break;
			}
		}
		catch (\RuntimeException $e)
		{
			trigger_error($e->getMessage() . adm_back_link($this->u_action), E_USER_WARNING);
		}

		$this->render_list($pools, $template);
		$template->assign_var('U_ACTION', $this->u_action);
	}

	private function render_list($pools, $template): void
	{
		foreach ($pools->list_pools() as $row)
		{
			$template->assign_block_vars('pools', [
				'POOL_ID'    => (int) $row['pool_id'],
				'GUILD_ID'   => (int) $row['guild_id'],
				'NAME'       => $row['pool_name'],
				'DESC'       => $row['pool_desc'],
				'STATUS'     => (int) $row['pool_status'],
				'IS_DEFAULT' => (bool) $row['pool_default'],
				'U_EDIT'     => $this->u_action . '&amp;action=edit&amp;pool_id=' . (int) $row['pool_id'],
				'U_DISABLE'  => $this->u_action . '&amp;action=disable&amp;pool_id=' . (int) $row['pool_id'],
				'U_DELETE'   => $this->u_action . '&amp;action=delete&amp;pool_id=' . (int) $row['pool_id'],
				'U_DEFAULT'  => $this->u_action . '&amp;action=set_default&amp;pool_id=' . (int) $row['pool_id'],
			]);
		}
	}

	private function handle_form($pools, int $pool_id, $request, $template, $user): void
	{
		$is_edit  = $pool_id > 0;
		$existing = $is_edit ? $pools->get_pool($pool_id) : null;

		if ($request->is_set_post('submit'))
		{
			$guild_id = $request->variable('guild_id', 0);
			$name     = $request->variable('pool_name', '', true);
			$desc     = $request->variable('pool_desc', '', true);

			if ($name === '')
			{
				trigger_error($user->lang['POOL_NAME_REQUIRED'] . adm_back_link($this->u_action), E_USER_WARNING);
			}

			if ($is_edit)
			{
				$pools->update_pool($pool_id, ['pool_name' => $name, 'pool_desc' => $desc]);
				trigger_error(sprintf($user->lang['POOL_UPDATED'], $name)
					. adm_back_link($this->u_action));
			}
			else
			{
				$pools->create_pool($guild_id, $name, $desc);
				trigger_error(sprintf($user->lang['POOL_CREATED'], $name)
					. adm_back_link($this->u_action));
			}
		}

		$template->assign_vars([
			'S_FORM'        => true,
			'S_IS_EDIT'     => $is_edit,
			'POOL_NAME'     => $existing['pool_name'] ?? '',
			'POOL_DESC'     => $existing['pool_desc'] ?? '',
			'POOL_GUILD_ID' => $existing['guild_id'] ?? 0,
			'U_BACK'        => $this->u_action,
		]);
	}
}
