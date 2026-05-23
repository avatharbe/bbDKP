<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

if (!defined('IN_PHPBB')) { exit; }

if (empty($lang) || !is_array($lang)) { $lang = []; }

$lang = array_merge($lang, [
	'ACP_DKP_EVENTS'         => 'DKP Events',
	'ACP_DKP_EVENTS_EXPLAIN' => 'Raid encounter types and their default DKP values. Each event belongs to one DKP pool.',
]);
