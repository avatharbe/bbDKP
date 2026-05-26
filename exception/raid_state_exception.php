<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\exception;

/**
 * Thrown by raid_manager when a raid mutation is rejected for a
 * state/validation reason that the user can act on. The exception
 * message is the language key the ACP module will render.
 *
 * Reserved keys:
 *   RAID_NO_ATTENDEES, RAID_DUPLICATE_ATTENDEE, RAID_POOL_DISABLED,
 *   RAID_EVENT_MISMATCH, RAID_UNKNOWN_PLAYER, RAID_NOT_FOUND.
 */
class raid_state_exception extends \RuntimeException
{
}
