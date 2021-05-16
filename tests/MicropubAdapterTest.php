<?php declare(strict_types=1);

namespace Taproot\Micropub\Tests;

use DateTime;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class MicropubAdapterTest extends TestCase {
	public function makeAccessToken($me='https://example.com', $scope=['create'], $clientId='https://client.example', $dateIssued=null) {
		if ($dateIssued === null) {
			$dateIssued = new DateTime();
		}

		return [
			'me' => $me,
			'scope' => $scope,
			'client_id' => $clientId,
			'date_issued' => $dateIssued
		];
	}

	public function makeRequest($method='POST') {
		return new ServerRequest($method, '/mp', ['authorization' => 'Bearer 12345678']);
	}

	public function testReturnsUnauthorizedWhenRequestHasNoAccessToken() {
		$mp = new MicropubAdapterMock(['verifyAccessTokenCallback' => false]);
		$response = $mp->handleRequest(new ServerRequest('POST', '/mp'));

		$this->assertEquals(401, $response->getStatusCode(), 'The response should have a 401 status code.');
	}

	public function testShortCircuitsResponseFromAccessTokenCallback() {
		$resp = new Response(400);
		$mp = new MicropubAdapterMock(['verifyAccessTokenCallback' => $resp]);
		$r = $mp->handleRequest($this->makeRequest());
		$this->assertSame($resp, $r);
	}

	public function testInvalidAccessTokenError() {
		$mp = new MicropubAdapterMock(['verifyAccessTokenCallback' => false]);
		$r = $mp->handleRequest($this->makeRequest());
		$this->assertEquals(403, $r->getStatusCode());
	}

	public function testExtensionCallback() {
		$at = $this->makeAccessToken();
		$resp = new Response();
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $at,
			'extensionCallback' => $resp
		]);
		$r = $mp->handleRequest($this->makeRequest());
		$this->assertSame($resp, $r);
	}

	public function testConfigQuery() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'configurationQueryCallback' => [
				'media-endpoint' => 'https://example.com/media-endpoint'
			]
		]);
		$r = $mp->handleRequest($this->makeRequest('GET')->withQueryParams(['q' => 'config']));
		$responseBody = json_decode($r->getBody()->getContents(), true);
		
		$this->assertEquals('application/json', $r->getHeaderLine('content-type'), 'q=config response must have Content-type: application/json');
		$this->assertEquals('https://example.com/media-endpoint', $responseBody['media-endpoint']);
	}

	public function testSourceQueryReturnsErrorWithoutUrlParameter() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'sourceQueryCallback' => function ($url, $parameters) { $this->fail(); } // If the source query callback ends up getting called, the test failed.
		]);
		$r = $mp->handleRequest($this->makeRequest('GET')->withQueryParams(['q' => 'source']));
		
		$this->assertEquals(400, $r->getStatusCode());
	}

	public function testSourceQueryWithoutProperties() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'sourceQueryCallback' => function ($url, $properties) {
				$this->assertNull($properties);
				return [
					'type' => 'h-entry',
					'properties' => [
						'name' => ['A Note'],
						'url' => ['https://example.com/post']
					]
				];
			}
		]);
		$r = $mp->handleRequest($this->makeRequest('GET')->withQueryParams([
			'q' => 'source',
			'url' => 'https://example.com/post'
		]));

		$this->assertEquals(200, $r->getStatusCode());
		$this->assertEquals('application/json', $r->getHeaderLine('content-type'));
	}

	public function testSourceQueryWithSingleProperty() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'sourceQueryCallback' => function ($url, $properties) {
				$this->assertCount(1, $properties);
				return [
					'type' => 'h-entry',
					'properties' => [
						'name' => ['A Note']
					]
				];
			}
		]);
		$r = $mp->handleRequest($this->makeRequest('GET')->withQueryParams([
			'q' => 'source',
			'url' => 'https://example.com/post',
			'properties' => 'name'
		]));

		$this->assertEquals(200, $r->getStatusCode());
		$this->assertEquals('application/json', $r->getHeaderLine('content-type'));
	}

	public function testSourceQueryWithMultipleProperties() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'sourceQueryCallback' => function ($url, $properties) {
				$this->assertCount(2, $properties);
				return [
					'type' => 'h-entry',
					'properties' => [
						'name' => ['A Note'],
						'url' => $url
					]
				];
			}
		]);
		$r = $mp->handleRequest($this->makeRequest('GET')->withQueryParams([
			'q' => 'source',
			'url' => 'https://example.com/post',
			'properties[]' => ['name', 'url']
		]));

		$this->assertEquals(200, $r->getStatusCode());
		$this->assertEquals('application/json', $r->getHeaderLine('content-type'));
	}

	public function testSourceQueryCallbackFalseInvalidRequest() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'sourceQueryCallback' => false
		]);
		$r = $mp->handleRequest($this->makeRequest('GET')->withQueryParams([
			'url' => 'https://example.com/post'
		]));

		$this->assertEquals(400, $r->getStatusCode());
	}

	public function testSyndicateToQueryPassesResponseInterfaceThrough() {
		$response = new Response(400);
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'configurationQueryCallback' => $response
		]);
		$r = $mp->handleRequest($this->makeRequest('GET')->withQueryParams(['q' => 'syndicate-to']));

		$this->assertSame($response, $r);
	}

	public function testSyndicateToQueryExtractsSyndicateToDataFromConfigQuery() {
		$st = [[
			'uid' => 'https://target.example',
			'name' => 'Example Target'
		]];
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'configurationQueryCallback' => [
				'media-endpoint' => 'https://example.com/media-endpoint',
				'syndicate-to' => $st
			]
		]);
		$r = $mp->handleRequest($this->makeRequest('GET')->withQueryParams(['q' => 'syndicate-to']));
		
		$parsedResponse = json_decode($r->getBody()->getContents(), true);
		$this->assertEquals(200, $r->getStatusCode());
		$this->assertEquals('application/json', $r->getHeaderLine('content-type'));
		$this->assertArrayHasKey('syndicate-to', $parsedResponse);
		$this->assertArrayNotHasKey('media-endpoint', $parsedResponse);
	}

	public function testSyndicateToQueryReturnsEmptyJsonObject() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'configurationQueryCallback' => false
		]);
		$r = $mp->handleRequest($this->makeRequest('GET')->withQueryParams(['q' => 'syndicate-to']));
		
		$parsedResponse = json_decode($r->getBody()->getContents(), true);
		$this->assertEquals(200, $r->getStatusCode());
		$this->assertEquals('application/json', $r->getHeaderLine('content-type'));
		$this->isEmpty($parsedResponse);
	}

	public function testReturnsInvalidRequestOnUnhandleableRequest() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken()
		]);
		$r = $mp->handleRequest($this->makeRequest('GET')->withQueryParams(['what' => 'banana']));

		$this->assertEquals(400, $r->getStatusCode());
	}

	public function testReturnsInvalidRequestOnDeleteRequestWithoutUrl() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken()
		]);
		$r = $mp->handleRequest($this->makeRequest()->withParsedBody(['action' => 'delete']));

		$this->assertEquals(400, $r->getStatusCode());
	}

	public function testReturnsSuccessOnSuccessfulDelete() {
		$url = 'https://example.com/post';
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'deleteCallback' => function ($u) use ($url) {
				$this->assertEquals($url, $u);
				return true;
			}
		]);
		$r = $mp->handleRequest($this->makeRequest()->withParsedBody([
			'action' => 'delete',
			'url' => $url
		]));
		
		$this->assertEquals(204, $r->getStatusCode());
	}

	public function testReturnsAppropriateErrorOnUnsuccessfulDelete() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'deleteCallback' => 'insufficient_scope'
		]);
		$r = $mp->handleRequest($this->makeRequest()->withParsedBody([
			'action' => 'delete',
			'url' => 'https://example.com/post'
		]));

		$parsedBody = json_decode($r->getBody()->getContents(), true);
		$this->assertEquals(403, $r->getStatusCode());
		$this->assertEquals('application/json', $r->getHeaderLine('content-type'));
		$this->assertEquals('insufficient_scope', $parsedBody['error']);
	}

	public function testReturnsSuccessOnSuccessfulUndelete() {
		$url = 'https://example.com/post';
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'undeleteCallback' => function ($u) use ($url) {
				$this->assertEquals($url, $u);
				return true;
			}
		]);
		$r = $mp->handleRequest($this->makeRequest()->withParsedBody([
			'action' => 'undelete',
			'url' => $url
		]));

		$this->assertEquals(204, $r->getStatusCode());
	}

	public function testReturnsSuccessWithLocationOnSuccessfulUndeleteWithUrl() {
		$newUrl = 'https://example.com/post2';
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'undeleteCallback' => $newUrl
		]);
		$r = $mp->handleRequest($this->makeRequest()->withParsedBody([
			'action' => 'undelete',
			'url' => 'https://example.com/post'
		]));

		$this->assertEquals(201, $r->getStatusCode());
		$this->assertEquals($newUrl, $r->getHeaderLine('location'));
	}

	public function testReturnsErrorWhenUndeleteCallbackReturnsMicropubErrorCode() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'undeleteCallback' => 'invalid_request'
		]);
		$r = $mp->handleRequest($this->makeRequest()->withParsedBody([
			'action' => 'undelete',
			'url' => 'https://example.com/post'
		]));

		$this->assertEquals(400, $r->getStatusCode());
	}

	public function testReturnsErrorOnUpdateRequestWithoutUrlParameter() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'updateCallback' => function ($url, $actions) { $this->fail('Update requests without a URL parameter should not call updateCallback().'); }
		]);
		$r = $mp->handleRequest($this->makeRequest()->withParsedBody([
			'action' => 'update'
		]));

		$this->assertEquals(400, $r->getStatusCode());
	}
}
