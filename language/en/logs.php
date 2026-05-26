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
	'LOG_DKPSYS_ADDED'      => '<strong>bbDKP</strong>: added DKP pool « %s »',
	'LOG_DKPSYS_UPDATED'    => '<strong>bbDKP</strong>: updated DKP pool « %s »',
	'LOG_DKPSYS_DELETED'    => '<strong>bbDKP</strong>: deleted DKP pool « %s »',
	'LOG_EVENT_ADDED'       => '<strong>bbDKP</strong>: added event « %s »',
	'LOG_EVENT_UPDATED'     => '<strong>bbDKP</strong>: updated event « %s »',
	'LOG_EVENT_DELETED'     => '<strong>bbDKP</strong>: deleted event « %s »',
	'LOG_RAID_ADDED'        => '<strong>bbDKP</strong>: added raid « %s » with %d attendees',
	'LOG_RAID_UPDATED'      => '<strong>bbDKP</strong>: updated raid #%d',
	'LOG_RAID_DELETED'      => '<strong>bbDKP</strong>: deleted raid #%d',
	'LOG_INDIVADJ_ADDED'    => '<strong>bbDKP</strong>: added adjustment « %s » for %d recipient(s)',
	'LOG_INDIVADJ_UPDATED'  => '<strong>bbDKP</strong>: updated adjustment #%d',
	'LOG_INDIVADJ_DELETED'  => '<strong>bbDKP</strong>: deleted adjustment #%d',
	'LOG_PLAYERDKP_UPDATED' => '<strong>bbDKP</strong>: updated DKP for player #%d (raid #%d, %s)',
]);
