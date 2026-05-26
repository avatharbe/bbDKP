<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

$lang = array_merge($lang, [
	'ACP_DKP_ADJUSTMENTS'         => 'Adjustments',
	'ACP_DKP_ADJUSTMENTS_EXPLAIN' => 'Manual DKP grants and debits. One reason + signed amount applies to all selected recipients; use a negative amount to subtract DKP. Each row posts an entry through bbAccounts.',

	'ADJ_ADD'                     => 'Add adjustment',
	'ADJ_EDIT'                    => 'Edit adjustment',
	'ADJ_DELETE'                  => 'Delete adjustment',
	'ADJ_POOL'                    => 'Pool',
	'ADJ_REASON'                  => 'Reason',
	'ADJ_AMOUNT'                  => 'Amount (signed)',
	'ADJ_RECIPIENTS'              => 'Recipients',
	'ADJ_DATE'                    => 'Date',
	'ADJ_TOTAL'                   => 'Total',
	'ADJ_NONE'                    => 'No adjustments recorded yet.',
	'ADJ_ACTIONS'                 => 'Actions',
	'ADJ_CREATED'                 => 'Adjustment #%d created.',
	'ADJ_UPDATED'                 => 'Adjustment #%d updated.',
	'ADJ_DELETED'                 => 'Adjustment #%d deleted.',
	'ADJ_CONFIRM_DELETE'          => 'Delete this adjustment and reverse all of its DKP postings?',

	// Exception → friendly text
	'ADJ_NO_RECIPIENTS'           => 'Select at least one recipient.',
	'ADJ_ZERO_AMOUNT'             => 'Adjustment amount must be non-zero.',
	'ADJ_DUPLICATE_RECIPIENT'     => 'A player can only appear once per adjustment.',
	'ADJ_UNKNOWN_PLAYER'          => 'One of the selected recipients is not a valid player in this guild.',
	'ADJ_POOL_DISABLED'           => 'This pool is disabled. Re-enable it before adding adjustments.',
	'ADJ_NOT_FOUND'               => 'That adjustment no longer exists.',
]);
