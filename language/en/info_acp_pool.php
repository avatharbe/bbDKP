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

	'POOL_ADD'               => 'Add new pool',
	'POOL_NAME'              => 'Pool name',
	'POOL_DESC'              => 'Description',
	'POOL_STATUS'            => 'Status',
	'POOL_DEFAULT'           => 'Default',
	'POOL_GUILD'             => 'Guild ID',
	'POOL_ACTIONS'           => 'Actions',
	'POOL_EDIT'              => 'Edit',
	'POOL_DISABLE'           => 'Disable',
	'POOL_DELETE'            => 'Delete',
	'POOL_SET_DEFAULT'       => 'Make default',
	'POOL_ACTIVE'            => 'Active',
	'POOL_INACTIVE'          => 'Disabled',
	'POOL_NONE'              => 'No pools yet.',

	'POOL_CREATED'           => 'Pool "%s" created successfully.',
	'POOL_UPDATED'           => 'Pool "%s" updated.',
	'POOL_DISABLED'          => 'Pool "%s" disabled.',
	'POOL_DELETED'           => 'Pool "%s" deleted.',
	'POOL_DEFAULT_SET'       => 'Pool "%s" set as default.',
	'POOL_DELETE_BLOCKED'    => 'Pool hard-delete is not supported in this version of bbDKP — please disable the pool instead.',
	'POOL_NAME_REQUIRED'     => 'Pool name is required.',

	'POOL_CONFIRM_DELETE'    => 'Are you sure you want to delete this pool? This cannot be undone.',
	'POOL_CONFIRM_DISABLE'   => 'Are you sure you want to disable this pool?',
]);
