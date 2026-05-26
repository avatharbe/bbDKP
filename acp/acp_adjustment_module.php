<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\acp;

use avathar\bbdkp\exception\adjustment_state_exception;

class acp_adjustment_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	public function main($id, $mode)
	{
		global $phpbb_container, $request, $template, $user, $db;

		$user->add_lang_ext('avathar/bbdkp', ['common', 'info_acp_adjustment', 'logs']);

		$adjustments = $phpbb_container->get('avathar.bbdkp.adjustment_manager');
		$pools       = $phpbb_container->get('avathar.bbdkp.pool_manager');

		$action = $request->variable('action', '');
		$adj_id = $request->variable('adjustment_id', 0);

		$this->tpl_name   = 'acp_bbdkp_adjustment';
		$this->page_title = 'ACP_DKP_ADJUSTMENTS';

		try
		{
			switch ($action)
			{
				case 'add':
				case 'edit':
					$this->handle_form($adjustments, $pools, $adj_id, $request, $template, $user, $db);
					break;

				case 'delete':
					$adj = $adjustments->get_adjustment($adj_id);
					if ($adj && confirm_box(true))
					{
						$adjustments->delete_adjustment($adj_id);
						trigger_error(sprintf($user->lang['ADJ_DELETED'], $adj_id)
							. adm_back_link($this->u_action));
					}
					elseif ($adj)
					{
						confirm_box(false, $user->lang['ADJ_CONFIRM_DELETE'],
							build_hidden_fields(['action' => 'delete', 'adjustment_id' => $adj_id]));
					}
					break;
			}
		}
		catch (adjustment_state_exception $e)
		{
			trigger_error($user->lang[$e->getMessage()]
				. adm_back_link($this->u_action), E_USER_WARNING);
		}
		catch (\RuntimeException $e)
		{
			$key = $e->getMessage();
			$msg = isset($user->lang[$key]) ? $user->lang[$key] : $key;
			trigger_error($msg . adm_back_link($this->u_action), E_USER_WARNING);
		}

		$this->render_list($adjustments, $template);
		$template->assign_var('U_ACTION', $this->u_action);
	}

	private function render_list($adjustments, $template): void
	{
		foreach ($adjustments->list_adjustments() as $row)
		{
			$template->assign_block_vars('adjustments', [
				'ADJ_ID'           => (int) $row['adjustment_id'],
				'POOL_ID'          => (int) $row['pool_id'],
				'REASON'           => $row['adjustment_reason'],
				'DATE'             => (int) $row['adjustment_date'],
				'RECIPIENT_COUNT'  => (int) $row['recipient_count'],
				'TOTAL'            => $row['total_amount'],
				'U_EDIT'           => $this->u_action . '&amp;action=edit&amp;adjustment_id=' . (int) $row['adjustment_id'],
				'U_DELETE'         => $this->u_action . '&amp;action=delete&amp;adjustment_id=' . (int) $row['adjustment_id'],
			]);
		}
	}

	private function handle_form($adjustments, $pools, int $adj_id, $request, $template, $user, $db): void
	{
		$is_edit  = $adj_id > 0;
		$existing = $is_edit ? $adjustments->get_adjustment($adj_id) : null;

		if ($request->is_set_post('submit'))
		{
			$pool_id = $request->variable('pool_id', 0);
			$reason  = $request->variable('reason', '', true);
			$amount  = $request->variable('amount', '0');
			$recipient_ids = $request->variable('recipient_ids', [0]);

			if ($is_edit)
			{
				$adjustments->update_adjustment($adj_id, ['adjustment_reason' => $reason]);
				trigger_error(sprintf($user->lang['ADJ_UPDATED'], $adj_id)
					. adm_back_link($this->u_action));
			}
			else
			{
				$recipients = [];
				foreach ($recipient_ids as $pid)
				{
					$pid = (int) $pid;
					if ($pid === 0)
					{
						continue;
					}
					$recipients[] = ['player_id' => $pid, 'amount' => $amount];
				}

				$new_id = $adjustments->create_adjustment($pool_id, $reason, $recipients);
				trigger_error(sprintf($user->lang['ADJ_CREATED'], $new_id)
					. adm_back_link($this->u_action));
			}
		}

		foreach ($pools->list_pools() as $p)
		{
			$template->assign_block_vars('pool_options', [
				'POOL_ID'   => (int) $p['pool_id'],
				'POOL_NAME' => $p['pool_name'],
				'GUILD_ID'  => (int) $p['guild_id'],
				'SELECTED'  => $is_edit && (int) $p['pool_id'] === (int) $existing['pool_id'],
			]);
		}

		if (!$is_edit)
		{
			$this->render_player_picker($db, $template);
		}
		else
		{
			foreach ($existing['recipients'] as $r)
			{
				$template->assign_block_vars('existing_recipients', [
					'RECIPIENT_ID' => (int) $r['recipient_id'],
					'PLAYER_ID'    => (int) $r['player_id'],
					'AMOUNT'       => $r['amount'],
				]);
			}
		}

		$template->assign_vars([
			'S_FORM'    => true,
			'S_IS_EDIT' => $is_edit,
			'ADJ_REASON' => $existing['adjustment_reason'] ?? '',
			'U_BACK'    => $this->u_action,
		]);
	}

	private function render_player_picker($db, $template): void
	{
		global $phpbb_container;
		try
		{
			$pool_table = $phpbb_container->getParameter('tables.bbdkp_pools');
			$pos = strrpos($pool_table, 'bb_dkp_pools');
			$prefix = $pos === false ? '' : substr($pool_table, 0, $pos);
		}
		catch (\Exception $e)
		{
			$prefix = '';
		}
		$players_table = $prefix . 'bb_players';

		$sql = 'SELECT player_id, player_name, player_realm, player_guild_id
			FROM ' . $players_table
			. ' ORDER BY player_guild_id, player_name ASC';
		$result = $db->sql_query_limit($sql, 200);
		while ($row = $db->sql_fetchrow($result))
		{
			$template->assign_block_vars('player_picker', [
				'PLAYER_ID'   => (int) $row['player_id'],
				'PLAYER_NAME' => $row['player_name'],
				'REALM'       => $row['player_realm'],
				'GUILD_ID'    => (int) $row['player_guild_id'],
			]);
		}
		$db->sql_freeresult($result);
	}
}
