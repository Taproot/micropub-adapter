<?php

namespace Taproot\Micropub\Example;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Interfaces\RouteCollectorProxyInterface;

require __DIR__ . '/../../vendor/autoload.php';

$app = AppFactory::create();

$micropubAdapter = new ExampleMicropubAdapter();

// Add IndieAuth endpoints.
$app->group('/indieauth', function (RouteCollectorProxyInterface $group) {
	$group->any('/authorization', function (Request $request, Response $response) {
		
	})->setName('indieauth.server.authorization_endpoint');

	$group->post('/token', function (Request $request, Response $response) {
		
	})->setName('indieauth.server.token_endpoint');
});

// Index
$app->get('/', function (Request $request, Response $response) {
	$response->getBody()->write('MicropubAdapter demo site.');
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
