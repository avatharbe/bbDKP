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

	'EVENT_ADD'              => 'Add new event',
	'EVENT_NAME'             => 'Name',
	'EVENT_VALUE'            => 'Default DKP value',
	'EVENT_COLOR'            => 'Color',
	'EVENT_ICON'             => 'Icon',
	'EVENT_POOL'             => 'Pool',
	'EVENT_STATUS'           => 'Status',
	'EVENT_ACTIONS'          => 'Actions',
	'EVENT_EDIT'             => 'Edit',
	'EVENT_DISABLE'          => 'Disable',
	'EVENT_DELETE'           => 'Delete',
	'EVENT_ACTIVE'           => 'Active',
	'EVENT_INACTIVE'         => 'Disabled',
	'EVENT_NONE'             => 'No events yet.',
	'EVENT_NAME_REQUIRED'    => 'Event name is required.',

	'EVENT_CREATED'          => 'Event "%s" created.',
	'EVENT_UPDATED'          => 'Event "%s" updated.',
	'EVENT_DISABLED'         => 'Event "%s" disabled.',
	'EVENT_DELETED'          => 'Event "%s" deleted.',
	'EVENT_DELETE_BLOCKED'   => 'Cannot delete event — raids still reference it. Disable instead.',

	'EVENT_CONFIRM_DELETE'   => 'Are you sure you want to delete this event?',
	'EVENT_CONFIRM_DISABLE'  => 'Are you sure you want to disable this event?',

	'FILTER_BY_POOL'         => 'Filter by pool',
	'ALL_POOLS'              => 'All pools',
	'POOL_REQUIRED'          => 'You must create a DKP pool before adding events.',
]);
