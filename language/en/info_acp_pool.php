<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

if (!defined('IN_PHPBB')) { exit; }

if (empty($lang) || !is_array($lang)) { $lang = []; }

$lang = array_merge($lang, [
	'ACP_DKP'                => 'bbDKP',
	'ACP_DKP_POOLS'          => 'DKP Pools',
	'ACP_DKP_POOLS_EXPLAIN'  => 'Define and manage DKP pools (separate point economies). Creating a pool auto-creates five chart-of-accounts entries in bbAccounts.',
]);
