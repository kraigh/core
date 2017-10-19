<?php
/**
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\Http\Client;

use OCP\Http\Client\IWebdavClientService;
use OCP\IConfig;
use OCP\ICertificateManager;
use Sabre\DAV\Client;

/**
 * Class WebdavClientService
 *
 * @package OC\Http
 */
class WebdavClientService implements IWebdavClientService {
	/** @var IConfig */
	private $config;
	/** @var ICertificateManager */
	private $certificateManager;

	/**
	 * @param IConfig $config
	 * @param ICertificateManager $certificateManager
	 */
	public function __construct(IConfig $config,
								ICertificateManager $certificateManager) {
		$this->config = $config;
		$this->certificateManager = $certificateManager;
	}

	/**
	 * Instantiate new Sabre client
	 *
	 * @param array $settings
	 * @return Client
	 */
	public function newClient($settings) {
		if (!isset($settings['proxy'])) {
			$proxy = \OC::$server->getConfig()->getSystemValue('proxy', '');
			if($proxy !== '') {
				$settings['proxy'] = $proxy;
			}
		}

		$certPath = null;
		if (strpos($settings['baseUri'], 'https') === 0) {
			$certPath = $this->certificateManager->getAbsoluteBundlePath();
			if (!file_exists($certPath)) {
				$certPath = null;
			}
		}

		$client = new Client($settings);
		$client->setThrowExceptions(true);

		if ($certPath !== null) {
			$client->addCurlSetting(CURLOPT_CAINFO, $certPath);
		}
		return $client;
	}
}
