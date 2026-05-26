<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\service;

/**
 * Domain service for raid + attendee CRUD.
 *
 * Composes dkp_ledger primitives. Validates input, opens a transaction,
 * writes metadata rows, posts to bbAccounts via dkp_ledger, writes
 * \phpbb_log entries, commits or rolls back. ACP modules never write to
 * bb_dkp_raids or bb_dkp_raid_attendees directly.
 */
interface raid_manager_interface
{
	/**
	 * Create a new raid with N attendees.
	 *
	 * @param int    $guild_id
	 * @param int    $pool_id
	 * @param int    $event_id
	 * @param int    $raid_start   unix seconds
	 * @param int    $raid_end     unix seconds; 0 = point-in-time
	 * @param string $raid_value   decimal string (e.g. '50.00')
	 * @param string $raid_note    BBCode
	 * @param array  $attendees    each: ['player_id' => int, 'value_override' => ?string]
	 *
	 * @return int raid_id
	 *
	 * @throws \avathar\bbdkp\exception\raid_state_exception
	 *         RAID_NO_ATTENDEES, RAID_DUPLICATE_ATTENDEE, RAID_POOL_DISABLED,
	 *         RAID_EVENT_MISMATCH, RAID_UNKNOWN_PLAYER
	 */
	public function create_raid(
		int $guild_id,
		int $pool_id,
		int $event_id,
		int $raid_start,
		int $raid_end,
		string $raid_value,
		string $raid_note,
		array $attendees
	): int;

	/**
	 * Update a raid's metadata.
	 *
	 * Editable: raid_end, raid_note, raid_value, event_id.
	 * raid_start, pool_id, guild_id are intentionally immutable.
	 *
	 * raid_value changes cascade: every attendee with value_override IS NULL
	 * has its prior ledger entry reversed and a new entry posted at the new
	 * amount.
	 *
	 * @throws \avathar\bbdkp\exception\raid_state_exception RAID_NOT_FOUND
	 */
	public function update_raid(int $raid_id, array $fields): void;

	/**
	 * Update one attendee row. Editable: value_override.
	 *
	 * value_override change cascades reverse + post for that one attendee.
	 *
	 * @param array $fields  ['value_override' => ?string]   null clears override
	 */
	public function update_attendee(int $attendee_id, array $fields): void;

	/**
	 * Append attendees to an existing raid. Each is posted on insert.
	 *
	 * @param array $attendees same shape as create_raid
	 * @throws \avathar\bbdkp\exception\raid_state_exception RAID_DUPLICATE_ATTENDEE, RAID_UNKNOWN_PLAYER
	 */
	public function add_attendees(int $raid_id, array $attendees): void;

	/**
	 * Remove attendees from a raid. Each removal reverses the prior posting
	 * and deletes the bb_dkp_raid_attendees row (ledger_link rows persist).
	 *
	 * @param int[] $player_ids
	 */
	public function remove_attendees(int $raid_id, array $player_ids): void;

	/**
	 * Hard-delete a raid: reverses every attendee's ledger entry, deletes
	 * the bb_dkp_raid_attendees rows, deletes the bb_dkp_raids row.
	 * ledger_link rows persist for audit.
	 */
	public function delete_raid(int $raid_id): void;

	/**
	 * @return array list of raid rows (no attendees subarray) ordered by raid_start DESC
	 */
	public function list_raids(int $pool_id = 0, int $event_id = 0, int $limit = 25, int $offset = 0): array;

	/**
	 * @return array|null raid row with `attendees` subarray, or null
	 */
	public function get_raid(int $raid_id): ?array;
}
