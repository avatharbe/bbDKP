<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\acp;

use avathar\bbdkp\exception\raid_state_exception;

class acp_raid_module
{
	public $u_action;
	public $tpl_name;
	public $page_title;

	public function main($id, $mode)
	{
		global $phpbb_container, $request, $template, $user, $db;

		$user->add_lang_ext('avathar/bbdkp', ['common', 'info_acp_raid', 'logs']);

		$raids   = $phpbb_container->get('avathar.bbdkp.raid_manager');
		$pools   = $phpbb_container->get('avathar.bbdkp.pool_manager');
		$events  = $phpbb_container->get('avathar.bbdkp.event_manager');

		$action  = $request->variable('action', '');
		$raid_id = $request->variable('raid_id', 0);

		$this->tpl_name   = 'acp_bbdkp_raid';
		$this->page_title = 'ACP_DKP_RAIDS';

		try
		{
			switch ($action)
			{
				case 'add':
				case 'edit':
					$this->handle_form($raids, $pools, $events, $raid_id, $request, $template, $user, $db);
					break;

				case 'delete':
					$raid = $raids->get_raid($raid_id);
					if ($raid && confirm_box(true))
					{
						$raids->delete_raid($raid_id);
						trigger_error(sprintf($user->lang['RAID_DELETED'], $raid_id)
							. adm_back_link($this->u_action));
					}
					elseif ($raid)
					{
						confirm_box(false, $user->lang['RAID_CONFIRM_DELETE'],
							build_hidden_fields(['action' => 'delete', 'raid_id' => $raid_id]));
					}
					break;
			}
		}
		catch (raid_state_exception $e)
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

		$this->render_list($raids, $template);
		$template->assign_var('U_ACTION', $this->u_action);
	}

	private function render_list($raids, $template): void
	{
		foreach ($raids->list_raids() as $row)
		{
			$template->assign_block_vars('raids', [
				'RAID_ID'         => (int) $row['raid_id'],
				'EVENT_NAME'      => $row['event_name'] ?? ('#' . $row['event_id']),
				'POOL_ID'         => (int) $row['pool_id'],
				'RAID_START'      => (int) $row['raid_start'],
				'RAID_END'        => (int) $row['raid_end'],
				'RAID_VALUE'      => $row['raid_value'],
				'ATTENDEE_COUNT'  => (int) $row['attendee_count'],
				'U_EDIT'          => $this->u_action . '&amp;action=edit&amp;raid_id=' . (int) $row['raid_id'],
				'U_DELETE'        => $this->u_action . '&amp;action=delete&amp;raid_id=' . (int) $row['raid_id'],
			]);
		}
	}

	private function handle_form($raids, $pools, $events, int $raid_id, $request, $template, $user, $db): void
	{
		$is_edit  = $raid_id > 0;
		$existing = $is_edit ? $raids->get_raid($raid_id) : null;

		if ($request->is_set_post('submit'))
		{
			$guild_id   = $request->variable('guild_id', 0);
			$pool_id    = $request->variable('pool_id', 0);
			$event_id   = $request->variable('event_id', 0);
			$raid_start = $request->variable('raid_start', 0);
			$raid_end   = $request->variable('raid_end', 0);
			$raid_value = $request->variable('raid_value', '0.00');
			$raid_note  = $request->variable('raid_note', '', true);

			$attendee_ids   = $request->variable('attendee_ids', [0]);
			$attendee_ovrs  = $request->variable('attendee_overrides', ['']);
			$attendees = [];
			foreach ($attendee_ids as $i => $pid)
			{
				$pid = (int) $pid;
				if ($pid === 0)
				{
					continue;
				}
				$override = trim((string) ($attendee_ovrs[$i] ?? ''));
				// Empty / '0' / 0 → null (will be persisted as the 0-sentinel by raid_manager).
				$attendees[] = [
					'player_id'      => $pid,
					'value_override' => ($override === '' || (float) $override === 0.0) ? null : $override,
				];
			}

			if ($is_edit)
			{
				$raids->update_raid($raid_id, [
					'event_id'   => $event_id,
					'raid_end'   => $raid_end,
					'raid_note'  => $raid_note,
					'raid_value' => $raid_value,
				]);
				trigger_error(sprintf($user->lang['RAID_UPDATED'], $raid_id)
					. adm_back_link($this->u_action));
			}
			else
			{
				$new_id = $raids->create_raid($guild_id, $pool_id, $event_id, $raid_start, $raid_end,
					$raid_value, $raid_note, $attendees);
				trigger_error(sprintf($user->lang['RAID_CREATED'], $new_id)
					. adm_back_link($this->u_action));
			}
		}

		// Form options
		foreach ($pools->list_pools() as $p)
		{
			$template->assign_block_vars('pool_options', [
				'POOL_ID'   => (int) $p['pool_id'],
				'POOL_NAME' => $p['pool_name'],
				'GUILD_ID'  => (int) $p['guild_id'],
				'SELECTED'  => $is_edit && (int) $p['pool_id'] === (int) $existing['pool_id'],
			]);
		}
		foreach ($events->list_events() as $e)
		{
			$template->assign_block_vars('event_options', [
				'EVENT_ID'   => (int) $e['event_id'],
				'EVENT_NAME' => $e['event_name'],
				'POOL_ID'    => (int) $e['pool_id'],
				'SELECTED'   => $is_edit && (int) $e['event_id'] === (int) $existing['event_id'],
			]);
		}

		// Player picker (limited to the raid's guild on edit; all guilds on add).
		$this->render_player_picker($db, $template, $is_edit ? (int) $existing['guild_id'] : 0,
			$is_edit ? array_column($existing['attendees'], 'player_id') : []);

		$template->assign_vars([
			'S_FORM'        => true,
			'S_IS_EDIT'     => $is_edit,
			'RAID_GUILD'    => $existing['guild_id'] ?? 0,
			'RAID_START'    => $existing['raid_start'] ?? time(),
			'RAID_END'      => $existing['raid_end']   ?? 0,
			'RAID_VALUE'    => $existing['raid_value'] ?? '0.00',
			'RAID_NOTE'     => $existing['raid_note']  ?? '',
			'U_BACK'        => $this->u_action,
		]);

		// Existing attendees (edit-mode rendering of overrides).
		if ($is_edit)
		{
			foreach ($existing['attendees'] as $att)
			{
				$override = $att['value_override'];
				// 0-sentinel → display as empty.
				$display = ($override === null || (float) $override === 0.0) ? '' : $override;
				$template->assign_block_vars('existing_attendees', [
					'ATTENDEE_ID'    => (int) $att['attendee_id'],
					'PLAYER_ID'      => (int) $att['player_id'],
					'VALUE_OVERRIDE' => $display,
				]);
			}
		}
	}

	private function render_player_picker($db, $template, int $guild_id, array $selected_ids): void
	{
		$selected_set = array_flip(array_map('intval', $selected_ids));

		// Resolve bb_players via the container's tables.bbdkp_pools parameter,
		// stripping the bbDKP-specific suffix to get phpBB's table prefix.
		global $phpbb_container;
		$prefix = '';
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

		$sql = 'SELECT player_id, player_name, player_realm
			FROM ' . $players_table
			. ($guild_id > 0 ? ' WHERE player_guild_id = ' . $guild_id : '')
			. ' ORDER BY player_name ASC';
		$result = $db->sql_query_limit($sql, 200);
		while ($row = $db->sql_fetchrow($result))
		{
			$template->assign_block_vars('player_picker', [
				'PLAYER_ID'   => (int) $row['player_id'],
				'PLAYER_NAME' => $row['player_name'],
				'REALM'       => $row['player_realm'],
				'SELECTED'    => isset($selected_set[(int) $row['player_id']]),
			]);
		}
		$db->sql_freeresult($result);
	}
}
