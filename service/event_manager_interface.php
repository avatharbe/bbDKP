<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\service;

interface event_manager_interface
{
	/**
	 * Create a new event under a pool.
	 *
	 * @param string $default_value decimal string, e.g. '50.00'
	 * @param string $color hex string with leading #, e.g. '#FFCC00'
	 * @param string $icon optional image path
	 * @return int event_id
	 */
	public function create_event(
		int $pool_id,
		string $name,
		string $default_value = '0.00',
		string $color = '',
		string $icon = ''
	): int;

	/**
	 * @param array<string, mixed> $fields allowed keys: event_name, event_value,
	 *   event_color, event_icon, event_status
	 */
	public function update_event(int $event_id, array $fields): void;

	/** Soft-disable: status = 0. Events stay queryable for historical raids. */
	public function disable_event(int $event_id): void;

	/**
	 * Hard-delete. Fails if any raids reference this event.
	 *
	 * @throws \RuntimeException when raids reference the event
	 */
	public function delete_event(int $event_id): void;

	/** @return array<int, array<string, mixed>> events filtered by pool_id (0 = all) */
	public function list_events(int $pool_id = 0): array;

	public function get_event(int $event_id): ?array;
}
