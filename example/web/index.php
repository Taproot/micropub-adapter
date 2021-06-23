<?php

namespace Taproot\Micropub\Example;

use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Taproot\IndieAuth;

require __DIR__ . '/../../vendor/autoload.php';

/** @var array $config */
$config = json_decode(file_get_contents(__DIR__.'/../data/config.json'), true);

$app = AppFactory::create();

// Ensure that the folders used by the app exist.
@mkdir($config['uploaded_file_path']);
@mkdir(__DIR__.'/../data/posts/');

// Set up a logger which we’ll use for everything.
$logger = new Logger('Micropub Example');
$logger->pushHandler(new RotatingFileHandler(__DIR__.'/../logs/micropub.log', 1));

// TODO: delete any posts older than a day.

// Set up an indieauth server.
$indieauthServer = new IndieAuth\Server([
	// Use the secret defined in the config file. Must be ≥64 chars long.
	'secret' => $config['secret'],

	// Store tokens in the file system.
	'tokenStorage' => __DIR__.'/../data/tokens/',

	// Use the simple single user password-based authentication handler bundled with the IndieAuth
	// library for authentication. Pass it our secret, our user data, and the password we’ll
	// log in with, hashed with password_hash().
	'authenticationHandler' => new IndieAuth\Callback\SingleUserPasswordAuthenticationCallback(
		$config['secret'],
		$config['user_profile'],
		$config['user_password']
	),

	// I’m disabling PKCE because the micropub.rocks test suite doesn’t support it yet. 
	// PKCE is required by default, and should only be made optional for back-compatibility.
	'requirePKCE' => false,
	
	// Log internal IndieAuth stuff for debugging purposes.
	'logger' => $logger
]);

// Make an instance of our Micropub Adapter, which requires access to the indieauth
// server, and information in the config array.
$micropubAdapter = new ExampleMicropubAdapter($indieauthServer, $config);

// Add IndieAuth endpoints.
$app->any('/indieauth/authorization', function (Request $request, Response $response) use ($indieauthServer, $logger) {
	return $indieauthServer->handleAuthorizationEndpointRequest($request);
});

$app->any('/indieauth/token', function (Request $request, Response $response) use ($indieauthServer) {
	return $indieauthServer->handleTokenEndpointRequest($request);
});

// Set up the index page, a very minimalist body with all the required indieauth+micropub links
// in headers.
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

// Micropub Endpoint, handled by our adapter.
$app->any('/micropub', function (Request $request, Response $response) use ($micropubAdapter) { 
	return $micropubAdapter->handleRequest($request);
});

// Micropub Media Endpoint, handled by our adapter.
$app->any('/media-endpoint', function (Request $request, Response $response) use ($micropubAdapter) {
	return $micropubAdapter->handleMediaEndpointRequest($request);
});

// Endpoint for viewing individual posts.
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

// Now that the app is set up, handle the current request.
$app->run();
