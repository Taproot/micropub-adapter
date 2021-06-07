<?php declare(strict_types=1);

namespace Taproot\Micropub\Tests;

use DateTime;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
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

	public function makeRequest($method='POST', $body=null) {
		return new ServerRequest($method, '/mp', [
			'authorization' => 'Bearer 12345678',
			'content-type' => 'x-www-form-urlencoded'
		], $body);
	}

	public function makeJsonRequest($body) {
		return new ServerRequest('POST', '/mp', [
			'authorization' => 'Bearer 12345678',
			'content-type' => 'application/json'
		], json_encode($body));
	}

	public function testAccessTokenIsExtractedFromHeaderCorrectly() {
		$accessToken = '12345678';
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => function ($t) use ($accessToken) {
				$this->assertEquals($accessToken, $t);
				return false;
			}
		]);
		$r = $mp->handleRequest(new ServerRequest('POST', '/mp', [
			'authorization' => 'Bearer 12345678',
			'content-type' => 'x-www-form-urlencoded'
		]));
		$this->assertEquals(403, $r->getStatusCode());
	}

	public function testAccessTokenIsExtractedFromRequestBody() {
		$accessToken = '12345678';
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => function ($t) use ($accessToken) {
				$this->assertEquals($accessToken, $t);
				return false;
			}
		]);
		$req = new ServerRequest('POST', '/mp', ['content-type' => 'x-www-form-urlencoded']);
		$r = $mp->handleRequest($req->withParsedBody(['access_token' => $accessToken]));

		$this->assertEquals(403, $r->getStatusCode());
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

	public function testExtensionCallbackAndTokenDataAvailable() {
		$at = $this->makeAccessToken();
		$req = $this->makeRequest();
		$resp = new Response();
		$mp = new MicropubAdapterMock(['verifyAccessTokenCallback' => $at]);
		$mp->callbackResponses['extensionCallback'] = function ($r) use ($at, $req, $resp, $mp) {
			$rProp = 'request';
			$uProp = 'user';
			$this->assertEquals($at, $mp->$uProp);
			$this->assertEquals($req, $mp->$rProp);
			return $resp;
		};

		$r = $mp->handleRequest($req);
		$this->assertSame($resp, $r);
	}

	public function testDefaultConfigQueryResponse() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken()
		]);
		$r = $mp->handleRequest($this->makeRequest('GET')->withQueryParams(['q' => 'config']));

		$this->assertEquals(200, $r->getStatusCode());
		$this->assertEquals('application/json', $r->getHeaderLine('content-type'));
		$this->assertJsonStringEqualsJsonString('{}', (string) $r->getBody());
	}

	public function testConfigQuery() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'configurationQueryCallback' => [
				'media-endpoint' => 'https://example.com/media-endpoint'
			]
		]);
		$r = $mp->handleRequest($this->makeRequest('GET')->withQueryParams(['q' => 'config']));
		$responseBody = json_decode((string) $r->getBody(), true);
		
		$this->assertEquals('application/json', $r->getHeaderLine('content-type'), 'q=config response must have Content-type: application/json');
		$this->assertEquals('https://example.com/media-endpoint', $responseBody['media-endpoint']);
	}

	public function testDefaultSourceQueryReturnsNotImplementedError() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken()
		]);
		$r = $mp->handleRequest($this->makeRequest('GET')->withQueryParams([
			'q' => 'source',
			'url' => 'https://example.com/post'
		]));

		$this->assertEquals(400, $r->getStatusCode());
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
		$sourceData = [
			'type' => 'h-entry',
			'properties' => [
				'name' => ['A Note'],
				'url' => ['https://example.com/post']
			]
		];

		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'sourceQueryCallback' => function ($url, $properties) use ($sourceData) {
				$this->assertNull($properties);
				$this->assertEquals($sourceData['properties']['url'][0], $url);
				return $sourceData;
			}
		]);
		$r = $mp->handleRequest($this->makeRequest('GET')->withQueryParams([
			'q' => 'source',
			'url' => 'https://example.com/post'
		]));

		$this->assertEquals(200, $r->getStatusCode());
		$this->assertEquals('application/json', $r->getHeaderLine('content-type'));
		$this->assertJsonStringEqualsJsonString(json_encode($sourceData), (string) $r->getBody());
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
			'q' => 'source',
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
		
		$parsedResponse = json_decode((string) $r->getBody(), true);
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
		
		$parsedResponse = json_decode((string) $r->getBody(), true);
		$this->assertEquals(200, $r->getStatusCode());
		$this->assertEquals('application/json', $r->getHeaderLine('content-type'));
		$this->isEmpty($parsedResponse);
	}

	public function testReturnsInvalidRequestOnUnhandleableGetRequest() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken()
		]);
		$r = $mp->handleRequest($this->makeRequest('GET')->withQueryParams(['what' => 'banana']));

		$this->assertEquals(400, $r->getStatusCode());
	}

	public function testReturnsInvalidResponseOnPostRequestWithoutParseableBody() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken()
		]);
		$r = $mp->handleRequest($this->makeRequest());

		$this->assertEquals(400, $r->getStatusCode());
	}

	public function testDefaultDeleteQueryReturnsError() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken()
		]);
		$r = $mp->handleRequest($this->makeRequest()->withParsedBody([
			'action' => 'delete',
			'url' => 'https://example.com/post'
		]));

		$this->assertEquals(400, $r->getStatusCode());
	}

	public function testReturnsInvalidRequestOnDeleteRequestWithoutUrl() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'deleteCallback' => function ($url) { $this->fail(); }
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

		$parsedBody = json_decode((string) $r->getBody(), true);
		$this->assertEquals(403, $r->getStatusCode());
		$this->assertEquals('application/json', $r->getHeaderLine('content-type'));
		$this->assertEquals('insufficient_scope', $parsedBody['error']);
	}

	public function testDefaultUndeleteCallbackReturnsError() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken()
		]);
		$r = $mp->handleRequest($this->makeRequest()->withParsedBody([
			'action' => 'undelete',
			'url' => 'https://example.com/post'
		]));

		$this->assertEquals(400, $r->getStatusCode());
	}

	public function testReturnsErrorOnUndeleteRequestWithoutUrl() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'undeleteCallback' => function ($url) { $this->fail(); }
		]);
		$r = $mp->handleRequest($this->makeRequest()->withParsedBody([
			'action' => 'undelete'
		]));

		$this->assertEquals(400, $r->getStatusCode());
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

	public function testDefaultUpdateCallbackReturnsError() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken()
		]);
		$r = $mp->handleRequest($this->makeJsonRequest([
			'action' => 'update',
			'url' => 'https://example.com/post'
		]));
		
		$this->assertEquals(400, $r->getStatusCode());
	}

	public function testReturnsErrorOnUpdateRequestWithoutUrlParameter() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'updateCallback' => function ($url, $actions) { $this->fail('Update requests without a URL parameter should not call updateCallback().'); }
		]);
		$r = $mp->handleRequest($this->makeJsonRequest([
			'action' => 'update'
		]));

		$this->assertEquals(400, $r->getStatusCode());
	}

	public function testSuccessfulUpdate() {
		$url = 'https://example.com/post';
		$updates = ['replace' => ['content' => 'test']];
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'updateCallback' => function ($u, $as) use ($url, $updates) {
				$this->assertEquals($url, $u);
				$this->assertEquals($updates['replace'], $as['replace']);
				return true;
			}
		]);
		$r = $mp->handleRequest($this->makeJsonRequest(array_merge([
			'action' => 'update',
			'url' => $url
		], $updates)));

		$this->assertEquals(204, $r->getStatusCode());
	}

	public function testSuccessfulUpdateWithChangedUrlReturnsLocationHeader() {
		$newUrl = 'https://example.com/post2';
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'updateCallback' => $newUrl
		]);
		$r = $mp->handleRequest($this->makeJsonRequest([
			'action' => 'update',
			'url' => 'https://example.com/post'
		]));

		$this->assertEquals(201, $r->getStatusCode());
		$this->assertEquals($newUrl, $r->getHeaderLine('location'));
	}

	public function testUpdateInvalidScopeReturnsError() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'updateCallback' => 'insufficient_scope'
		]);
		$r = $mp->handleRequest($this->makeJsonRequest([
			'action' => 'update',
			'url' => 'https://example.com/post'
		]));

		$this->assertEquals(403, $r->getStatusCode());
	}

	public function testPostRequestWithUnknownActionReturnsError() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken()
		]);
		$r = $mp->handleRequest($this->makeJsonRequest([
			'action' => 'banana'
		]));

		$this->assertEquals(400, $r->getStatusCode());
	}

	public function testDefaultCreateCallbackReturnsError() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken()
		]);
		$r = $mp->handleRequest($this->makeRequest()->withParsedBody([
			'h' => 'entry',
			'content' => 'everybody is good'
		]));

		$this->assertEquals(400, $r->getStatusCode());
	}

	public function testUrlEncodedCreateRequestIsNormalizedToMf2Json() {
		$url = 'https://example.com/post';
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'createCallback' => function ($d, $f) use ($url) {
				$expectedData = [
					'type' => ['h-entry'],
					'properties' => [
						'content' => ['Hello world!'],
						'category' => ['category1', 'category2']
					]
				];
				$this->assertEquals($expectedData, $d);
				$this->assertEquals(['photo' => 'DUMMY_UPLOADED_FILE'], $f);
				return $url;
			}
		]);

		$r = $mp->handleRequest($this->makeRequest()->withParsedBody([
			'content' => 'Hello world!',
			'category[]' => ['category1', 'category2']
		])->withUploadedFiles(['photo' => 'DUMMY_UPLOADED_FILE']));
		
		$this->assertEquals(201, $r->getStatusCode());
		$this->assertEquals($url, $r->getHeaderLine('location'));
	}

	public function testHParameterIsCorrectlyUsedAsTypeProperty() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'createCallback' => function ($d, $f) {
				$expectedData = [
					'type' => ['h-measure'],
					'properties' => [
						'num' => ['70.4'],
						'unit' => ['kg']
					]
				];
				$this->assertEquals($expectedData, $d);
				return 'https://example.com/post';
			}
		]);
		$r = $mp->handleRequest($this->makeRequest()->withParsedBody([
			'h' => 'measure',
			'num' => '70.4',
			'unit' => 'kg'
		]));

		$this->assertEquals(201, $r->getStatusCode());
	}

	public function testCreateCallbackHandlesJsonRequest() {
		$requestBody = [
			'type' => ['h-entry'],
			'properties' => [
				'content' => ['Hello world!']
			]
		];
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'createCallback' => function ($d, $f) use ($requestBody) {
				$this->assertEquals($requestBody, $d);
				return 'https://example.com/post';
			}
		]);
		$r = $mp->handleRequest($this->makeJsonRequest($requestBody));
		
		$this->assertEquals(201, $r->getStatusCode());
	}

	public function testCreateCallbackInsufficientScopeError() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'createCallback' => 'insufficient_scope'
		]);
		$r = $mp->handleRequest($this->makeRequest()->withParsedBody(['h' => 'entry', 'content' => 'blah']));

		$this->assertEquals(403, $r->getStatusCode());
	}

	public function testMethodOtherThanGetOrPostReturnErrors() {
		$mp = new MicropubAdapterMock(['verifyAccessTokenCallback' => $this->makeAccessToken()]);
		$r = $mp->handleRequest($this->makeRequest('PUT'));
		
		$this->assertEquals(400, $r->getStatusCode());
	}

	// Media Endpoint Tests
	public function testMediaEndpointReturnsUnauthorizedWhenRequestHasNoAccessToken() {
		$mp = new MicropubAdapterMock(['verifyAccessTokenCallback' => false]);
		$response = $mp->handleMediaEndpointRequest(new ServerRequest('POST', '/mp'));

		$this->assertEquals(401, $response->getStatusCode(), 'The response should have a 401 status code.');
	}

	public function testMediaEndpointShortCircuitsResponseFromAccessTokenCallback() {
		$resp = new Response(400);
		$mp = new MicropubAdapterMock(['verifyAccessTokenCallback' => $resp]);
		$r = $mp->handleMediaEndpointRequest($this->makeRequest());
		$this->assertSame($resp, $r);
	}

	public function testMediaEndpointInvalidAccessTokenError() {
		$mp = new MicropubAdapterMock(['verifyAccessTokenCallback' => false]);
		$r = $mp->handleMediaEndpointRequest($this->makeRequest());
		$this->assertEquals(403, $r->getStatusCode());
	}

	public function testMediaEndpointExtensionCallback() {
		$resp = new Response();
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'mediaEndpointExtensionCallback' => $resp
		]);
		$r = $mp->handleMediaEndpointRequest($this->makeRequest());

		$this->assertSame($resp, $r);
	}

	public function testMediaEndpointReturnsErrorForNonPostRequest() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'mediaEndpointCallback' => function ($f) {
				$this->fail();
			}
		]);
		$r = $mp->handleMediaEndpointRequest($this->makeRequest('GET'));
		
		$this->assertEquals(400, $r->getStatusCode());
	}

	public function testDefaultMediaEndpointCallbackReturnsError() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken()
		]);
		$r = $mp->handleMediaEndpointRequest($this->makeRequest()->withUploadedFiles(['file' => new UploadedFile('php://input', 1000, 0)]));

		$this->assertEquals(400, $r->getStatusCode());
	}

	public function testMediaEndpointHandlesSuccessfulRequest() {
		$url = 'https://example.com/img.png';
		$uploadedFile = new UploadedFile('php://input', 1000, 0);
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'mediaEndpointCallback' => function ($f) use ($url, $uploadedFile) {
				$this->assertSame($f, $uploadedFile);
				return $url;
			}
		]);
		$r = $mp->handleMediaEndpointRequest($this->makeRequest()->withUploadedFiles(['file' => $uploadedFile]));

		$this->assertEquals(201, $r->getStatusCode());
		$this->assertEquals($url, $r->getHeaderLine('location'));
	}

	public function testMediaEndpointHandlesInsufficientScopeError() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'mediaEndpointCallback' => 'insufficient_scope'
		]);
		$r = $mp->handleMediaEndpointRequest($this->makeRequest()->withUploadedFiles([
			'file' => new UploadedFile('php://input', 1000, 0)
		]));

		$this->assertEquals(403, $r->getStatusCode());
	}

	public function testMediaEndpointReturnsErrorOnInvalidRequest() {
		$mp = new MicropubAdapterMock([
			'verifyAccessTokenCallback' => $this->makeAccessToken(),
			'mediaEndpointCallback' => 'insufficient_scope'
		]);
		$r = $mp->handleMediaEndpointRequest($this->makeRequest()->withUploadedFiles([
			'not_the_right_key' => new UploadedFile('php://input', 1000, 0)
		]));

		$this->assertEquals(400, $r->getStatusCode());
	}
}
