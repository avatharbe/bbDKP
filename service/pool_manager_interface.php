<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\service;

interface pool_manager_interface
{
	/**
	 * Create a new pool and auto-mint its 5 bbAccounts entries.
	 *
	 * @return int pool_id
	 * @throws \RuntimeException on duplicate name within the guild
	 */
	public function create_pool(int $guild_id, string $name, string $desc = ''): int;

	/**
	 * Rename / re-describe a pool. Account names in bbAccounts stay frozen.
	 *
	 * @param array<string, mixed> $fields allowed keys: pool_name, pool_desc, pool_status
	 */
	public function update_pool(int $pool_id, array $fields): void;

	/**
	 * Soft-disable: pool_status = 0. The pool stays queryable for
	 * historical reads; bbAccounts accounts persist.
	 */
	public function disable_pool(int $pool_id): void;

	/**
	 * Hard-delete. v2.0.0-alpha1 always fails because bbAccounts has no
	 * account-delete API. Caller should surface as "use disable instead".
	 */
	public function delete_pool(int $pool_id): void;

	/**
	 * Set this pool as the default for its guild (exactly one default
	 * per guild — service-enforced).
	 */
	public function set_default(int $pool_id): void;

	/** @return array<int, array<string, mixed>> */
	public function list_pools(int $guild_id = 0): array;

	public function get_pool(int $pool_id): ?array;
}
