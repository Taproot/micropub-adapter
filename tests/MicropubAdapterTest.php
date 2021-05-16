<?php declare(strict_types=1);

namespace Taproot\Micropub\Tests;

use Taproot\Micropub;
use Nyholm\Psr7\ServerRequest;

use PHPUnit\Framework\TestCase;

final class MicropubAdapterTest extends TestCase {
	public function testReturnsUnauthorizedWhenRequestHasNoAccessToken() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => false
		]);
		$request = new ServerRequest('POST', '/mp');
		$response = $mp->handleRequest($request);

		$this->assertEquals(401, $response->getStatusCode(), 'The response should have a 401 status code.');
	}
}
