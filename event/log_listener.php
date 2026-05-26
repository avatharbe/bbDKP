<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Loads bbDKP log type translations so phpBB's mod log renders them
 * by name. Wired as a phpBB event listener via services.yml with the
 * `event.listener` tag; phpBB's container dispatches via the
 * EventSubscriberInterface::getSubscribedEvents() map.
 */
class log_listener implements EventSubscriberInterface
{
	/** @var \phpbb\user */
	private $user;

	public function __construct(\phpbb\user $user)
	{
		$this->user = $user;
	}

	public static function getSubscribedEvents()
	{
		return [
			'core.user_setup' => 'load_language',
		];
	}

	public function load_language($event)
	{
		$lang_set_ext = $event['lang_set_ext'];
		$lang_set_ext[] = [
			'ext_name' => 'avathar/bbdkp',
			'lang_set' => 'logs',
		];
		$event['lang_set_ext'] = $lang_set_ext;
	}
}
