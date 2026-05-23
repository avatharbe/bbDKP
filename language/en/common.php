<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

if (!defined('IN_PHPBB')) { exit; }

if (empty($lang) || !is_array($lang)) { $lang = []; }

$lang = array_merge($lang, [
	'BBDKP'             => 'bbDKP',
	'BBDKP_VERSION'     => 'bbDKP Version',
	'BBDKP_DEP_MISSING' => 'bbDKP requires bbGuild ≥ 2.0.0-b1 and bbAccounts ≥ 1.1.0-alpha to be installed and enabled.',
]);
