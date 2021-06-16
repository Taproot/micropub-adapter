<?php

namespace Taproot\Micropub\Example;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Taproot\IndieAuth;

require __DIR__ . '/../../vendor/autoload.php';

$app = AppFactory::create();

$config = json_decode(file_get_contents(__DIR__.'/../data/config.json'), true);

$indieauthServer = new IndieAuth\Server([
	'secret' => $config['secret'],
	'tokenStorage' => __DIR__.'/../data/tokens/',
	'authenticationHandler' => new IndieAuth\Callback\SingleUserPasswordAuthenticationCallback(
		$config['user_profile'],
		$config['user_password'])
]);

$micropubAdapter = new ExampleMicropubAdapter();

// Add IndieAuth endpoints.
$app->any('/indieauth/authorization', function (Request $request, Response $response) use ($indieauthServer) {
	return $indieauthServer->handleAuthorizationEndpointRequest($request);
})->setName('indieauth.server.authorization_endpoint');

$app->any('/indieauth/token', function (Request $request, Response $response) use ($indieauthServer) {
	return $indieauthServer->handleTokenEndpointRequest($request);
})->setName('indieauth.server.token_endpoint');

// Index
$app->get('/', function (Request $request, Response $response) {
	$response->getBody()->write('MicropubAdapter demo site.');

	$baseUrl = $request->getUri()->withPath('/')->withQuery('')->withFragment('');

	$response = $response->withAddedHeader('Link', [
		"<{$baseUrl->withPath('micropub')}>; rel=\"micropub\"",
		"<{$baseUrl->withPath('indieauth/authorization')}>; rel=\"authorization_endpoint\"",
		"<{$baseUrl->withPath('indieauth/token')}>; rel=\"token_endpoint\"",
	]);
	return $response;
});

// Micropub Endpoint
$app->any('/micropub', function (Request $request, Response $response) use ($micropubAdapter) { 
	return $micropubAdapter->handleRequest($request);
});

// Micropub Media Endpoint
$app->any('/media-endpoint', function (Request $request, Response $response) use ($micropubAdapter) {
	return $micropubAdapter->handleMediaEndpointRequest($request);
});

$app->run();
