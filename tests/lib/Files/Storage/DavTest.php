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
use OC\Files\Storage\DAV;

/**
 * Class DavTest
 *
 * @group DB
 *
 * @package Test\Files\Storage
 */
class DavTest extends TestCase {

	/**
	 * @var DAV
	 */
	private $instance;

	/**
	 * @var IClientService
	 */
	private $httpClientService;

	/**
	 * @var IWebdavClientService
	 */
	private $webdavClientService;

	/**
	 * @var Client
	 */
	private $client;

	protected function setUp() {
		parent::setUp();

		$this->httpClientService = $this->createMock(IClientService::class);
		$this->overwriteService('HttpClientService', $this->httpClientService);

		$this->webdavClientService = $this->createMock(IWebdavClientService::class);
		$this->overwriteService('WebdavClientService', $this->webdavClientService);

		$this->client = $this->createMock(Client::class);
		$this->webdavClientService->method('newClient')->willReturn($this->client);

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
			[new \Sabre\DAV\Exception\Forbidden('Forbidden'), \Sabre\DAV\Exception\Forbidden::class],
			[new \InvalidArgumentException(), StorageNotAvailableException::class],
			[new StorageNotAvailableException(), StorageNotAvailableException::class],
			[new StorageInvalidException(), StorageInvalidException::class],
		];
	}

	/**
	 * @dataProvider convertExceptionDataProvider
	 */
	public function testConvertException($inputException, $expectedExceptionClass) {
		$this->client->method('propfind')->will($this->throwException($inputException));

		$thrownException = null;
		try {
			$this->instance->opendir('/test');
		} catch (\Exception $e) {
			$thrownException = $e;
		}

		$this->assertNotNull($thrownException);
		$this->assertInstanceOf($expectedExceptionClass, $thrownException);
	}

	public function testMkdir() {
		$this->client->expects($this->once())
			->method('request')
			->with('MKCOL', 'new%25dir', null)
			->willReturn(['statusCode' => Http::STATUS_CREATED]);

		$this->assertTrue($this->instance->mkdir('/new%dir'));
	}

	public function testMkdirAlreadyExists() {
		$this->client->expects($this->once())
			->method('request')
			->with('MKCOL', 'new%25dir', null)
			->willReturn(['statusCode' => Http::STATUS_METHOD_NOT_ALLOWED]);

		$this->assertFalse($this->instance->mkdir('/new%dir'));
	}

	/**
	 * @expectedException \OCA\DAV\Connector\Sabre\Exception\Forbidden
	 */
	public function testMkdirException() {
		$this->client->expects($this->once())
			->method('request')
			->with('MKCOL', 'new%25dir', null)
			->willThrowException($this->createClientHttpException(Http::STATUS_FORBIDDEN));

		$this->instance->mkdir('/new%dir');
	}

	public function testRmdir() {
		$this->client->expects($this->once())
			->method('request')
			->with('DELETE', 'old%25dir', null)
			->willReturn(['statusCode' => Http::STATUS_NO_CONTENT]);

		$this->assertTrue($this->instance->rmdir('/old%dir'));
	}

	public function testRmdirUnexist() {
		$this->client->expects($this->once())
			->method('request')
			->with('DELETE', 'old%25dir', null)
			->willReturn(['statusCode' => Http::STATUS_NOT_FOUND]);

		$this->assertFalse($this->instance->rmdir('/old%dir'));
	}

	/**
	 * @expectedException \OCA\DAV\Connector\Sabre\Exception\Forbidden
	 */
	public function testRmdirException() {
		$this->client->expects($this->once())
			->method('request')
			->with('DELETE', 'old%25dir', null)
			->willThrowException($this->createClientHttpException(Http::STATUS_FORBIDDEN));

		$this->instance->rmdir('/old%dir');
	}

	public function testOpenDir() {
		$responseBody = [
			// root entry
			'some%25dir' => [],
			'some%25dir/first%25folder' => [],
			'some%25dir/second' => [],
		];

		$this->client->expects($this->once())
			->method('propfind')
			->with('some%25dir', [], 1)
			->willReturn($responseBody);

		$dir = $this->instance->opendir('/some%dir');
		$entries = [];
		while ($entry = readdir($dir)) {
			$entries[] = $entry;
		}

		$this->assertCount(2, $entries);
		$this->assertEquals('first%folder', $entries[0]);
		$this->assertEquals('second', $entries[1]);
	}

	public function testOpenDirNotFound() {
		$this->client->expects($this->once())
			->method('propfind')
			->with('some%25dir', [], 1)
			->willThrowException($this->createClientHttpException(Http::STATUS_NOT_FOUND));

		$this->assertFalse($this->instance->opendir('/some%dir'));
	}

	/**
	 * @expectedException \OCA\DAV\Connector\Sabre\Exception\Forbidden
	 */
	public function testOpenDirException() {
		$this->client->expects($this->once())
			->method('propfind')
			->with('some%25dir', [], 1)
			->willThrowException($this->createClientHttpException(Http::STATUS_FORBIDDEN));

		$this->instance->opendir('/some%dir');
	}

	public function testFileTypeDir() {
		$resourceTypeObj = $this->getMockBuilder('\stdclass')
			->setMethods(['getValue'])
			->getMock();
		$resourceTypeObj->method('getValue')
			->willReturn(['{DAV:}collection']);

		$this->client->expects($this->once())
			->method('propfind')
			->with('some%25dir/file%25type', $this->contains('{DAV:}resourcetype'))
			->willReturn([
				'{DAV:}resourcetype' => $resourceTypeObj
			]);

		$this->assertEquals('dir', $this->instance->filetype('/some%dir/file%type'));
	}

	public function testFileTypeFile() {
		$this->client->expects($this->once())
			->method('propfind')
			->with('some%25dir/file%25type', $this->contains('{DAV:}resourcetype'))
			->willReturn([]);

		$this->assertEquals('file', $this->instance->filetype('/some%dir/file%type'));
	}

	/**
	 * @expectedException \OCA\DAV\Connector\Sabre\Exception\Forbidden
	 */
	public function testFileTypeException() {
		$this->client->expects($this->once())
			->method('propfind')
			->with('some%25dir/file%25type', $this->contains('{DAV:}resourcetype'))
			->willThrowException($this->createClientHttpException(Http::STATUS_FORBIDDEN));

		$this->instance->filetype('/some%dir/file%type');
	}
}

