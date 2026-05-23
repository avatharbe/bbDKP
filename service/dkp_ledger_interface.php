<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\service;

/**
 * The single posting surface from bbDKP to bbAccounts.
 *
 * Every DKP-side mutation (raid award, loot purchase, adjustment, reversal)
 * goes through this interface. No bbDKP code writes to bbAccounts tables
 * directly. The interface is intentionally narrow — domain logic decides
 * what to post, this service decides how to post it.
 */
interface dkp_ledger_interface
{
	/**
	 * Auto-mint the 5 chart-of-accounts entries for a new pool.
	 *
	 * @return array<string, int> map of role => account_id, keys:
	 *   'player_wallets', 'raid_attendance', 'loot_proceeds',
	 *   'adjust_credit', 'adjust_debit'
	 */
	public function mint_pool_accounts(int $pool_id): array;

	/**
	 * Soft-disable accounts for a pool. In v2.0.0-alpha1 this is a no-op
	 * because bbAccounts has no public account-deactivate API; bbDKP's
	 * `bb_dkp_pools.pool_status` flag is what hides the pool from UI.
	 */
	public function archive_pool_accounts(int $pool_id): void;

	/**
	 * Hard-delete accounts for a pool. In v2.0.0-alpha1 this always throws
	 * because bbAccounts has no public account-delete API. Callers should
	 * present this as "use disable instead" to the operator.
	 *
	 * @throws \RuntimeException always
	 */
	public function delete_pool_accounts(int $pool_id): void;

	/**
	 * Post a raid award. DR raid_attendance / CR player_wallets.
	 * Records a forward link in bb_dkp_ledger_link.
	 *
	 * @param int    $attendee_id PK of bb_dkp_raid_attendees row
	 * @param int    $pool_id
	 * @param int    $player_id
	 * @param string $amount      decimal string (e.g. '75.00')
	 * @return int link_id
	 */
	public function post_raid_award(int $attendee_id, int $pool_id, int $player_id, string $amount): int;

	/**
	 * Post a loot purchase. DR player_wallets / CR loot_proceeds.
	 *
	 * @return int link_id
	 */
	public function post_loot_purchase(int $loot_id, int $pool_id, int $player_id, string $value): int;

	/**
	 * Post a single adjustment line. Direction depends on amount sign.
	 *
	 * @param int    $recipient_id PK of bb_dkp_adjustment_recipients row
	 * @param string $amount        signed decimal string
	 * @return int link_id
	 */
	public function post_adjustment(int $recipient_id, int $pool_id, int $player_id, string $amount): int;

	/**
	 * Reverse a previously posted entry by link_id. Posts the inverse
	 * journal entry in bbAccounts and writes a new bb_dkp_ledger_link
	 * row with reversal_of=<original>.
	 *
	 * @return int the new link_id of the reversal
	 * @throws \RuntimeException if the link doesn't exist or is itself a reversal
	 */
	public function reverse(int $link_id): int;
}
