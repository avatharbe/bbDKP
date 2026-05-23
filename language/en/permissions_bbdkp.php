<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

if (!defined('IN_PHPBB')) { exit; }

if (empty($lang) || !is_array($lang)) { $lang = []; }

$lang = array_merge($lang, [
	'ACL_A_BBDKP'             => 'bbDKP: Can manage all DKP settings',
	'ACL_M_BBDKP'             => 'bbDKP: Can add/edit raids, loot, adjustments',
	'ACL_U_BBDKP_VIEW'        => 'bbDKP: Can view DKP standings and history',
	'ACL_U_BBDKP_VIEW_OTHERS' => 'bbDKP: Can view DKP pages of other characters',
]);
