<?php

namespace Taproot\Micropub\Example;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

require __DIR__ . '../../../../vendor/autoload.php';

$app = AppFactory::create();

$micropubAdapter = new ExampleMicropubAdapter();

// Index
$app->get('/', function (Request $request, Response $response) {
	$response->getBody()->write('MicropubAdapter demo site.');
});

// Micropub Endpoint
$app->any('/micropub', function (Request $request, Response $response) use ($micropubAdapter) {
	return $micropubAdapter->handleRequest($request);
});

// Micropub Media Endpoint
$app->any('/media-endpoint', function (Request $request, Response $response) use ($micropubAdapter) {
	return $micropubAdapter->handleMediaEndpointRequest($request);
});
