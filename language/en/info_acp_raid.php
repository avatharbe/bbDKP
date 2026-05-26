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
	'ACP_DKP_RAIDS'              => 'Raids',
	'ACP_DKP_RAIDS_EXPLAIN'      => 'Create, edit and delete raids. Each raid awards DKP to its attendees through bbAccounts. Editing the raid value automatically reverses and reposts the affected entries.',

	'RAID_ADD'                   => 'Add raid',
	'RAID_EDIT'                  => 'Edit raid',
	'RAID_DELETE'                => 'Delete raid',
	'RAID_POOL'                  => 'Pool',
	'RAID_EVENT'                 => 'Event',
	'RAID_START'                 => 'Start (unix seconds)',
	'RAID_END'                   => 'End (unix seconds, 0 = none)',
	'RAID_VALUE'                 => 'Default DKP value',
	'RAID_NOTE'                  => 'Note (BBCode)',
	'RAID_ATTENDEES'             => 'Attendees',
	'RAID_ATTENDEE_VALUE'        => 'Override value',
	'RAID_NONE'                  => 'No raids recorded yet.',
	'RAID_ACTIONS'               => 'Actions',
	'RAID_CREATED'               => 'Raid created (#%d).',
	'RAID_UPDATED'               => 'Raid #%d updated.',
	'RAID_DELETED'               => 'Raid #%d deleted.',
	'RAID_CONFIRM_DELETE'        => 'Delete this raid and reverse all of its DKP postings?',

	// Exception → friendly text
	'RAID_NO_ATTENDEES'          => 'A raid needs at least one attendee.',
	'RAID_DUPLICATE_ATTENDEE'    => 'A player cannot attend the same raid twice.',
	'RAID_POOL_DISABLED'         => 'This pool is disabled. Re-enable it before adding raids.',
	'RAID_EVENT_MISMATCH'        => 'The selected event does not belong to the selected pool.',
	'RAID_UNKNOWN_PLAYER'        => 'One of the attendees is not a valid player in this guild.',
	'RAID_NOT_FOUND'             => 'That raid no longer exists.',
	'LEDGER_LINK_MISSING'        => 'Internal error: no live ledger link found for an attendee. The raid metadata and the ledger are out of sync — contact a developer.',
]);
