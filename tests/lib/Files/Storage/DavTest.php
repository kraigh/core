<?php
/**
 * ownCloud
 *
 * @author Robin Appelman
 * @copyright 2012 Robin Appelman icewind@owncloud.com
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace Test\Files\Storage;

use Test\TestCase;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IClient;
use Sabre\DAV\Client;
use OCP\Http\Client\IWebdavClientService;
use Sabre\HTTP\ClientHttpException;
use OCP\Lock\LockedException;
use OCP\AppFramework\Http;
use OCP\Files\StorageInvalidException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\ClientException;
use Sabre\DAV\Exception\InsufficientStorage;
use Sabre\DAV\Exception\Forbidden;
use OCP\Files\StorageNotAvailableException;

/**
 * Class DavTest
 *
 * @group DB
 *
 * @package Test\Files\Storage
 */
class DavTest extends TestCase {

	private $instance;

	private $httpClientService;
	private $webdavClientService;

	protected function setUp() {
		parent::setUp();

		$this->httpClientService = $this->createMock(IClientService::class);
		$this->overwriteService('HttpClientService', $this->httpClientService);

		$this->webdavClientService = $this->createMock(IWebdavClientService::class);
		$this->overwriteService('WebdavClientService', $this->webdavClientService);

		$this->instance = new \OC\Files\Storage\DAV([
			'user' => 'davuser',
			'password' => 'davpassword',
			'host' => 'davhost',
			'root' => 'davroot',
		]);
	}

	protected function tearDown() {
		$this->restoreService('HttpClientService');
		$this->restoreService('WebdavClientService');
		parent::tearDown();
	}

	public function testId() {
		$this->assertEquals('webdav::davuser@davhost//davroot/', $this->instance->getId());
	}

	private function createClientHttpException($statusCode) {
		$response = $this->createMock(\Sabre\HTTP\ResponseInterface::class);
		$response->method('getStatusText')->willReturn('');
		$response->method('getStatus')->willReturn($statusCode);
		return new ClientHttpException($response);
	}

	private function createGuzzleClientException($statusCode) {
		$response = $this->createMock(\GuzzleHttp\MessageResponseInterface::class);
		$response->method('getStatusCode')->willReturn($statusCode);
		return new ClientException($response);
	}

	private function createGuzzleServerException($statusCode) {
		$response = $this->createMock(\GuzzleHttp\MessageResponseInterface::class);
		$response->method('getStatusCode')->willReturn($statusCode);
		return new ServerException($response);
	}

	public function convertExceptionDataProvider() {
		return [
			[$this->createClientHttpException(Http::STATUS_UNAUTHORIZED), StorageInvalidException::class],
			[$this->createClientHttpException(Http::STATUS_LOCKED), LockedException::class],
			[$this->createClientHttpException(Http::STATUS_INSUFFICIENT_STORAGE), InsufficientStorage::class],
			[$this->createClientHttpException(Http::STATUS_FORBIDDEN), Forbidden::class],
			[$this->createClientHttpException(Http::STATUS_INTERNAL_SERVER_ERROR), StorageNotAvailableException::class],
			[new \InvalidArgumentException(), StorageNotAvailableException::class],
			[new StorageNotAvailableException(), StorageNotAvailableException::class],
			[new StorageInvalidException(), StorageInvalidException::class],
		];
	}

	/**
	 * @dataProvider convertExceptionDataProvider
	 */
	public function testConvertException($inputException, $expectedExceptionClass) {
		$client = $this->createMock(Client::class);
		$this->webdavClientService->method('newClient')->willReturn($client);
		$client->method('propfind')->will($this->throwException($inputException));

		$thrownException = null;
		try {
			$this->instance->opendir('/test');
		} catch (\Exception $e) {
			$thrownException = $e;
		}

		$this->assertNotNull($thrownException);
		$this->assertInstanceOf($expectedExceptionClass, $thrownException);
	}
}

