<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\exception;

/**
 * Thrown by adjustment_manager for user-actionable validation failures.
 *
 * Reserved keys:
 *   ADJ_NO_RECIPIENTS, ADJ_ZERO_AMOUNT, ADJ_DUPLICATE_RECIPIENT,
 *   ADJ_UNKNOWN_PLAYER, ADJ_POOL_DISABLED, ADJ_NOT_FOUND.
 */
class adjustment_state_exception extends \RuntimeException
{
}
