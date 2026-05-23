<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\acp;

class acp_event_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	public function main($id, $mode)
	{
		global $phpbb_container, $request, $template, $user;

		$user->add_lang_ext('avathar/bbdkp', ['common', 'info_acp_event']);

		$events = $phpbb_container->get('avathar.bbdkp.event_manager');
		$pools  = $phpbb_container->get('avathar.bbdkp.pool_manager');

		$action      = $request->variable('action', '');
		$event_id    = $request->variable('event_id', 0);
		$filter_pool = $request->variable('filter_pool', 0);

		$this->tpl_name   = 'acp_bbdkp_event';
		$this->page_title = 'ACP_DKP_EVENTS';

		try
		{
			switch ($action)
			{
				case 'add':
				case 'edit':
					$this->handle_form($events, $pools, $event_id, $request, $template, $user);
					break;

				case 'disable':
					$ev = $events->get_event($event_id);
					if ($ev && confirm_box(true))
					{
						$events->disable_event($event_id);
						trigger_error(sprintf($user->lang['EVENT_DISABLED'], $ev['event_name'])
							. adm_back_link($this->u_action));
					}
					elseif ($ev)
					{
						confirm_box(false, $user->lang['EVENT_CONFIRM_DISABLE'],
							build_hidden_fields(['action' => 'disable', 'event_id' => $event_id]));
					}
					break;

				case 'delete':
					$ev = $events->get_event($event_id);
					if ($ev && confirm_box(true))
					{
						try
						{
							$events->delete_event($event_id);
							trigger_error(sprintf($user->lang['EVENT_DELETED'], $ev['event_name'])
								. adm_back_link($this->u_action));
						}
						catch (\RuntimeException $e)
						{
							trigger_error($user->lang['EVENT_DELETE_BLOCKED']
								. adm_back_link($this->u_action), E_USER_WARNING);
						}
					}
					elseif ($ev)
					{
						confirm_box(false, $user->lang['EVENT_CONFIRM_DELETE'],
							build_hidden_fields(['action' => 'delete', 'event_id' => $event_id]));
					}
					break;
			}
		}
		catch (\RuntimeException $e)
		{
			trigger_error($e->getMessage() . adm_back_link($this->u_action), E_USER_WARNING);
		}

		$this->render_list($events, $pools, $template, $filter_pool);
		$template->assign_vars([
			'U_ACTION'    => $this->u_action,
			'FILTER_POOL' => $filter_pool,
		]);
	}

	private function render_list($events, $pools, $template, int $filter_pool): void
	{
		$pool_map = [];
		foreach ($pools->list_pools() as $p)
		{
			$pool_map[(int) $p['pool_id']] = $p['pool_name'];
			$template->assign_block_vars('pool_options', [
				'ID'       => (int) $p['pool_id'],
				'NAME'     => $p['pool_name'],
				'SELECTED' => $filter_pool === (int) $p['pool_id'],
			]);
		}

		foreach ($events->list_events($filter_pool) as $row)
		{
			$pid = (int) $row['pool_id'];
			$template->assign_block_vars('events', [
				'EVENT_ID'   => (int) $row['event_id'],
				'NAME'       => $row['event_name'],
				'VALUE'      => $row['event_value'],
				'COLOR'      => $row['event_color'],
				'ICON'       => $row['event_icon'],
				'POOL_NAME'  => $pool_map[$pid] ?? '?',
				'STATUS'     => (int) $row['event_status'],
				'U_EDIT'     => $this->u_action . '&amp;action=edit&amp;event_id=' . (int) $row['event_id'],
				'U_DISABLE'  => $this->u_action . '&amp;action=disable&amp;event_id=' . (int) $row['event_id'],
				'U_DELETE'   => $this->u_action . '&amp;action=delete&amp;event_id=' . (int) $row['event_id'],
			]);
		}
	}

	private function handle_form($events, $pools, int $event_id, $request, $template, $user): void
	{
		$is_edit  = $event_id > 0;
		$existing = $is_edit ? $events->get_event($event_id) : null;

		if ($request->is_set_post('submit'))
		{
			$pool_id = $request->variable('pool_id', 0);
			$name    = $request->variable('event_name', '', true);
			$value   = $request->variable('event_value', '0.00');
			$color   = $request->variable('event_color', '');
			$icon    = $request->variable('event_icon', '');

			if ($name === '')
			{
				trigger_error($user->lang['EVENT_NAME_REQUIRED'] . adm_back_link($this->u_action), E_USER_WARNING);
			}

			if ($is_edit)
			{
				$events->update_event($event_id, [
					'event_name'  => $name,
					'event_value' => $value,
					'event_color' => $color,
					'event_icon'  => $icon,
				]);
				trigger_error(sprintf($user->lang['EVENT_UPDATED'], $name)
					. adm_back_link($this->u_action));
			}
			else
			{
				$events->create_event($pool_id, $name, $value, $color, $icon);
				trigger_error(sprintf($user->lang['EVENT_CREATED'], $name)
					. adm_back_link($this->u_action));
			}
		}

		$pool_list = $pools->list_pools();
		if (empty($pool_list))
		{
			trigger_error($user->lang['POOL_REQUIRED'] . adm_back_link($this->u_action), E_USER_WARNING);
		}

		foreach ($pool_list as $p)
		{
			$template->assign_block_vars('form_pool_options', [
				'ID'       => (int) $p['pool_id'],
				'NAME'     => $p['pool_name'],
				'SELECTED' => isset($existing['pool_id']) && (int) $existing['pool_id'] === (int) $p['pool_id'],
			]);
		}

		$template->assign_vars([
			'S_FORM'      => true,
			'S_IS_EDIT'   => $is_edit,
			'EVENT_NAME'  => $existing['event_name'] ?? '',
			'EVENT_VALUE' => $existing['event_value'] ?? '0.00',
			'EVENT_COLOR' => $existing['event_color'] ?? '',
			'EVENT_ICON'  => $existing['event_icon'] ?? '',
			'U_BACK'      => $this->u_action,
		]);
	}
}
