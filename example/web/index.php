<?php

namespace Taproot\Micropub\Example;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Interfaces\RouteCollectorProxyInterface;
use Taproot\IndieAuth;
use Webmozart\PathUtil\Path;

require __DIR__ . '/../../vendor/autoload.php';

$app = AppFactory::create();

/** @var array $config */
$config = json_decode(file_get_contents(__DIR__.'/../data/config.json'), true);

@mkdir($config['uploaded_file_path']);
@mkdir(__DIR__.'/../data/posts/');

$logger = new Logger('Micropub Example');
$logger->pushHandler(new RotatingFileHandler(__DIR__.'/../logs/micropub.log', 1));

// TODO: delete any posts older than a day.

$indieauthServer = new IndieAuth\Server([
	'secret' => $config['secret'],
	'tokenStorage' => __DIR__.'/../data/tokens/',
	'authenticationHandler' => new IndieAuth\Callback\SingleUserPasswordAuthenticationCallback(
		$config['secret'],
		$config['user_profile'],
		$config['user_password']
	),
	// I’m disabling PKCE because the micropub.rocks test suite doesn’t support it yet. 
	// PKCE is required by default, and should only be made optional for back-compatibility.
	'requirePKCE' => false,
	'logger' => $logger
]);

$micropubAdapter = new ExampleMicropubAdapter($indieauthServer, $config);

// Add IndieAuth endpoints.
$app->any('/indieauth/authorization', function (Request $request, Response $response) use ($indieauthServer, $logger) {
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

// h-entry endpoint
$app->get('/posts/{postId}', function (Request $request, Response $response, array $args) use ($logger, $micropubAdapter) {
	$entryId = $args['postId'];

	$postData = $micropubAdapter->getPostById($entryId);
	if (!is_array($postData)) {
		$response->getBody()->write("Not Found");
		return $response->withStatus(404);
	}

	if (!is_array($postData)) {
		$response->getBody()->write("Internal Error");
		return $response->withStatus('500');
	}

	if ($postData['deleted'] ?? false) {
		$response->getBody()->write("Deleted");
		return $response->withStatus(410);
	}

	$response->getBody()->write(IndieAuth\renderTemplate(__DIR__.'/../templates/post.html.php', [
		'post' => $postData
	]));
	return $response->withAddedHeader('Content-type', 'text/html');
});

$app->run();
