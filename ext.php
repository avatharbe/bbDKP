<?php
/**
 * bbDKP — phpBB Extension
 * @copyright (c) 2026 Andreas Vandenberghe
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 */

namespace avathar\bbdkp;

/**
 * Extension entrypoint. Blocks enable when bbGuild or bbAccounts is missing
 * or below the required version, per design spec §D7.
 */
class ext extends \phpbb\extension\base
{
	public function is_enableable()
	{
		$required = [
			'avathar/bbguild'    => '2.0.0-b1',
			'avathar/bbaccounts' => '1.1.0-alpha',
		];

		$manager = $this->container->get('ext.manager');

		foreach ($required as $ext_name => $min_version)
		{
			if (!$manager->is_enabled($ext_name))
			{
				return false;
			}

			$md = $manager->create_extension_metadata_manager($ext_name);
			$meta = $md->get_metadata();
			$version = $meta['version'] ?? '0';

			if (version_compare($version, $min_version, '<'))
			{
				return false;
			}
		}

		return true;
	}
}
