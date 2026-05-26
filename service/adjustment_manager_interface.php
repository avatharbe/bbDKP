<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\service;

/**
 * Domain service for adjustment + recipient CRUD.
 *
 * One adjustment header carries one reason and one group_key; N recipient
 * rows attach with individual signed amounts. Bulk-add posts each recipient
 * via dkp_ledger->post_adjustment().
 */
interface adjustment_manager_interface
{
	/**
	 * @param int    $pool_id
	 * @param string $reason
	 * @param array  $recipients each: ['player_id' => int, 'amount' => string (signed)]
	 *
	 * @return int adjustment_id
	 *
	 * @throws \avathar\bbdkp\exception\adjustment_state_exception
	 *         ADJ_NO_RECIPIENTS, ADJ_ZERO_AMOUNT, ADJ_DUPLICATE_RECIPIENT,
	 *         ADJ_UNKNOWN_PLAYER, ADJ_POOL_DISABLED
	 */
	public function create_adjustment(int $pool_id, string $reason, array $recipients): int;

	/**
	 * Editable: reason only. pool_id and adjustment_date are immutable.
	 * Amount changes go via remove_recipients + add_recipients.
	 */
	public function update_adjustment(int $adjustment_id, array $fields): void;

	public function add_recipients(int $adjustment_id, array $recipients): void;

	/** @param int[] $recipient_ids */
	public function remove_recipients(int $adjustment_id, array $recipient_ids): void;

	/**
	 * Reverses every recipient's ledger entry, deletes the recipient rows,
	 * deletes the adjustment header. ledger_link rows persist for audit.
	 */
	public function delete_adjustment(int $adjustment_id): void;

	public function list_adjustments(int $pool_id = 0, int $limit = 25, int $offset = 0): array;

	public function get_adjustment(int $adjustment_id): ?array;
}
