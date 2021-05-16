<?php declare(strict_types=1);

namespace Taproot\Micropub;

use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use \Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

const MICROPUB_ERROR_CODES = ['invalid_request', 'unauthorized', 'insufficient_scope', 'forbidden'];

/**
 * Abstract Micropub Adapter Superclass
 * 
 * Subclass this class, implementing the various *Callback methods to handle different 
 * types of micropub request.
 * 
 * Subclasses **must** implement abstract callback methods in order to have a functional
 * micropub endpoint. All other callback methods are optional, and their functionality
 * is enabled if a subclass implements them.
 * 
 * Each callback is passed data corresponding to the type of micropub request dispatched
 * to it, but can also access the original request via the named $request parameter.
 * Each callback return data in a format defined by the callback, which will be 
 * converted into the appropriate HTTP Response. Optionally, a callback may short-circuit
 * the conversion by returning a HTTP Response, which will be returned by handleRequest().
 */
abstract class MicropubAdapter {

	/**
	 * @param array $user The validated access_token, made available for use in callback methods.
	 */
	private $user;

	/**
	 * @param RequestInterface $request The current request, made available for use in callback methods.
	 */
	private $request;
	
	/**
	 * @param LoggerInterface $logger The logger used by MicropubAdaptor for internal logging.
	 */
	private $logger;

	/**
	 * @param array $errorMessages An array mapping micropub and adapter-specific error codes to human-friendly descriptions.
	 */
	private $errorMessages = [
		// Built-in micropub error types
		'insufficient_scope' => 'Your access token does not grant the scope required for this action.',
		'forbidden' => 'The authenticated user does not have permission to perform this request.',
		'unauthorized' => 'The request did not provide an access token.',
		'invalid_request' => 'The request was invalid.',
		// Custom errors
		'access_token_invalid' => 'The provided access token could not be verified.',
		'missing_url_parameter' => 'The request did not provide the required url parameter.',
		'post_with_given_url_not_found' => 'A post with the given URL could not be found.',
		'not_implemented' => 'This functionality is not implemented.',
	];

	/**
	 * Verify Access Token Callback
	 * 
	 * Given an access token, verify it and return an array with user data if valid,
	 * or `false` if invalid. The user data array will typically look something like
	 * this:
	 * 
	 *     [
	 *       'me' => 'https://example.com',
	 *       'client_id' => 'https://clientapp.example',
	 *       'scope' => ['array', 'of', 'granted', 'scopes'],
	 *       'date_issued' => \Datetime
	 *     ]
	 * 
	 * MicropubAdapter treats the data as being opaque, and simply makes it
	 * available to your callback methods for further processing, so you’re free
	 * to structure it however you want.
	 * 
	 * You can also short-circuit the micropub request handling by returning an
	 * instance of \Psr\Http\Message\ResponseInterface, which handleRequest()
	 * will return unchanged.
	 * 
	 * @param string $token The Authentication: Bearer access token.
	 * @return array|string|false|ResponseInterface
	 * @link https://micropub.spec.indieweb.org/#authentication-0
	 * @api
	 */
	abstract public function verifyAccessTokenCallback(string $token);
	
	/**
	 * Micropub Extension Callback
	 * 
	 * This callback is called after an access token is verified, but before any micropub-
	 * specific handling takes place. This is the place to implement support for micropub
	 * extensions.
	 * 
	 * If a falsy value is returned, the request continues to be handled as a regular 
	 * micropub request. If it returns a truthy value (either a MP error code, an array to
	 * be returned as JSON, or a ready-made ResponseInterface), request handling is halted
	 * and the returned value is converted into a response and returned.
	 * 
	 * @param ServerRequestInterface $request
	 * @return false|array|string|ResponseInterface
	 * @link https://indieweb.org/Micropub-extensions
	 * @api
	 */
	public function extensionCallback(ServerRequestInterface $request) {
		// Default implementation: no-op;
		return false;
	}

	/**
	 * Configuration Query Callback
	 * 
	 * Handle a GET q=config query. Should return either a custom ResponseInterface, or an
	 * array structure conforming to the micropub specification, e.g.:
	 * 
	 *     [
	 *       'media-endpoint' => 'http://example.com/your-media-endpoint',
	 *       'syndicate-to' => [[
	 *         'uid' => 'https://myfavoritesocialnetwork.example/aaronpk', // Required
	 *         'name' => 'aaronpk on myfavoritesocialnetwork', // Required
	 *         'service' => [ // Optional
	 *           'name' => 'My Favorite Social Network',
	 *           'url' => 'https://myfavoritesocialnetwork.example/',
	 *           'photo' => 'https://myfavoritesocialnetwork.example/img/icon.png', 
	 *         ],
	 *         'user' => [ // Optional
	 *           'name' => 'aaronpk',
	 *           'photo' => 'https://myfavoritesocialnetwork.example/aaronpk',
	 *           'url' => 'https://myfavoritesocialnetwork.example/aaronpk/photo.jpg'
	 *         ]
	 *       ]]
	 *     ]
	 * 
	 * The results from this function are also used to respond to syndicate-to queries. If 
	 * a raw ResponseInterface is returned, that will be used as-is. If an array structure
	 * is returned, syndicate-to queries will extract the syndicate-to information and 
	 * return just that.
	 * 
	 * @param array $params The unaltered query string parameters from the request.
	 * @return array|string|ResponseInterface Return either an array with config data, a micropub error string, or a ResponseInterface to short-circuit
	 * @link https://micropub.spec.indieweb.org/#configuration
	 * @api
	 */
	public function configurationQueryCallback(array $params) {
		// Default response: an empty JSON object.
		return $this->toResponse('{}');
	}

	/**
	 * Source Query Callback
	 * 
	 * Handle a GET q=source query. Return a microformats2 canonical JSON representation
	 * of the post identified by $url, either as an array or as a ready-made ResponseInterface.
	 * 
	 * If the post identified by $url cannot be found, returning false will return a
	 * correctly-formatted error response.
	 * 
	 * @param string $url The URL of the post for which to return properties.
	 * @param array|null $properties = null The list of properties to return (all if null)
	 * @return array|false|string|ResponseInterface Return either an array with canonical mf2 data, false if the post could not be found, a micropub error string, or a ResponseInterface to short-circuit.
	 * @link https://micropub.spec.indieweb.org/#source-content
	 * @api
	 */
	public function sourceQueryCallback(string $url, array $properties = null) {
		// Default response: not implemented.
		return $this->toResponse([
			'error' => 'invalid_request',
			'error_description' => $this->errorMessages['not_implemented']
		]);
	}

	/**
	 * Delete Callback
	 * 
	 * Handle a POST action=delete request. Look for a post identified by the $url parameter.
	 * 
	 * * If it doesn’t exist: return `false` or `'invalid_request'` as a shortcut for an
	 *   HTTP 400 invalid_request response.
	 * * If the current access token scope doesn’t permit deletion, return `'insufficient_scope'`,
	 *   an array with `'error'` and `'error_description'` keys, or your own ResponseInterface.
	 * * If the post exists and can be deleted or is already deleted, delete it and return true.
	 * 
	 * @param string $url The URL of the post to be deleted.
	 * @return string|true|array|ResponseInterface
	 * @link https://micropub.spec.indieweb.org/#delete
	 * @api
	 */
	public function deleteCallback(string $url) {
		// Default response: not implemented.
		return $this->toResponse([
			'error' => 'invalid_request',
			'error_description' => $this->errorMessages['not_implemented']
		]);
	}

	/**
	 * Undelete Callback
	 * 
	 * Handle a POST action=undelete request.
	 * 
	 * * Look for a post identified by the $url parameter.
	 * * If it doesn’t exist: return `false` or `'invalid_request'` as a shortcut for an
	 *   HTTP 400 invalid_request response.
	 * * If the current access token scope doesn’t permit undeletion, return `'insufficient_scope'`,
	 *   an array with `'error'` and `'error_description'` keys, or your own ResponseInterface.
	 * * If the post exists and can be undeleted, do so. Return true for success, or a URL if the
	 *   undeletion caused the post’s URL to change.
	 * 
	 * @param string $url The URL of the post to be deleted.
	 * @return string|true|array true on basic success, otherwise either an error string, or a URL if the undeletion caused the post’s location to change.
	 * @link https://micropub.spec.indieweb.org/#delete
	 * @api
	 */
	public function undeleteCallback(string $url) {
		// Default response: not implemented.
		return $this->toResponse([
			'error' => 'invalid_request',
			'error_description' => $this->errorMessages['not_implemented']
		]);
	}

	/**
	 * Update Callback
	 * 
	 * Handles a query with action=update.
	 * 
	 * * Look for a post identified by the $url parameter.
	 * * If it doesn’t exist: return `false` or `'invalid_request'` as a shortcut for an
	 *   HTTP 400 invalid_request response.
	 * * If the current access token scope doesn’t permit updates, return `'insufficient_scope'`,
	 *   an array with `'error'` and `'error_description'` keys, or your own ResponseInterface.
	 * * If the post exists and can be updated, do so. Return true for basic success, or a URL if the
	 *   undeletion caused the post’s URL to change.
	 * 
	 * @param string $url The URL of the post to be updated.
	 * @param array $actions The parsed body of the request, containing 'replace', 'add' and/or 'delete' keys describing the operations to perfom on the post.
	 * @return true|string|array|ResponseInterface Return true for a basic success, a micropub error string, an array to be converted to a JSON response, or a ready-made ResponseInterface
	 * @link https://micropub.spec.indieweb.org/#update
	 * @api
	 */
	public function updateCallback(string $url, array $actions) {
		// Default response: not implemented.
		return $this->toResponse([
			'error' => 'invalid_request',
			'error_description' => $this->errorMessages['not_implemented']
		]);
	}

	/**
	 * Create Callback
	 * 
	 * Handles a create request. JSON parameters are left unchanged, urlencoded
	 * form parameters are normalized into canonical JSON form.
	 *
	 * * If the current access token scope doesn’t permit updates, return either
	 *   `'insufficient_scope'`, an array with `'error'` and `'error_description'`
	 *   keys, or your own ResponseInterface.
	 * * Create the post.
	 * * On an error, return either a micropub error code to be upgraded into a 
	 *   full error response, or your own ResponseInterface.
	 * * On success, return either the URL of the created post to be upgraded into 
	 *   a HTTP 201 success response, or your own ResponseInterface.
	 * 
	 * @param array $data The data to create a post with in canonical MF2 structure
	 * @param array $uploadedFiles an associative array mapping property names to UploadedFileInterface objects
	 * @return string|array|ResponseInterface A URL on success, a micropub error code, an array to be returned as JSON response, or a ready-made ResponseInterface
	 * @link https://micropub.spec.indieweb.org/#create
	 * @api
	 */
	public function createCallback(array $data, array $uploadedFiles) {
		// Default response: not implemented.
		return $this->toResponse([
			'error' => 'invalid_request',
			'error_description' => $this->errorMessages['not_implemented']
		]);
	}

	/**
	 * Media Endpoint Callback
	 * 
	 * If you want your micropub endpoint to also function as its own Media Endpoint, implement
	 * this callback and return your micropub endpoint URL in the `'media-endpoint'` property
	 * of your configurationQueryCallback.
	 * 
	 * To handle file upload requests:
	 * 
	 * * If the current access token scope doesn’t permit uploads, return either
	 *   `'insufficient_scope'`, an array with `'error'` and `'error_description'`
	 *   keys, or your own ResponseInterface.
	 * * Handle the uploaded file.
	 * * On an error, return either a micropub error code to be upgraded into a 
	 *   full error response, or your own ResponseInterface.
	 * * On success, return either the URL of the created URL to be upgraded into 
	 *   a HTTP 201 success response, or your own ResponseInterface.
	 * 
	 * If you don’t want a media endpoint, or want to implement
	 * your own elsewhere, simply ignore this method, resulting in its default no-op behaviour.
	 * 
	 * @param UploadedFileInterface $file The file to upload
	 * @return false|string|array|ResponseInterface Return a falsy value to continue handling the request, the URL of the uploaded file on success, a micropub error code to be upgraded into an error response, an array for a JSON response, or a ready-made ResponseInterface
	 * @link https://micropub.spec.indieweb.org/#media-endpoint
	 * @api
	 */
	public function mediaEndpointCallback(UploadedFileInterface $file) {
		// Default implementation: return false to continue processing the request.
		return false;
	}

	/**
	 * Micropub Media Endpoint Extension Callback
	 * 
	 * This callback is called after an access token is verified, but before any media-
	 * endpoint-specific handling takes place. This is the place to implement support 
	 * for micropub media endpoint extensions.
	 * 
	 * If a falsy value is returned, the request continues to be handled as a regular 
	 * micropub request. If it returns a truthy value (either a MP error code, an array to
	 * be returned as JSON, or a ready-made ResponseInterface), request handling is halted
	 * and the returned value is converted into a response and returned.
	 * 
	 * @param ServerRequestInterface $request
	 * @return false|array|string|ResponseInterface
	 * @link https://indieweb.org/Micropub-extensions
	 */
	public function mediaEndpointExtensionCallback(ServerRequestInterface $request) {
		// Default implementation: no-op.
		return false;
	}

	/**
	 * Get Logger
	 * 
	 * Returns an instance of Psr\LoggerInterface, used for logging. Override to
	 * provide with your logger of choice.
	 * 
	 * @return \Psr\Log\LoggerInterface
	 */
	private function getLogger(): LoggerInterface {
		if ($this->logger == null) {
			$this->logger = new NullLogger();
		}
		return $this->logger;
	}

	/**
	 * Handle Micropub Request
	 * 
	 * Handle an incoming request to a micropub endpoint, performing error checking and 
	 * handing execution off to the appropriate callback.
	 * 
	 * `$this->request` is set to the value of the `$request` argument, for use within
	 * callbacks. If the access token could be verified, `$this->user` is set to the value
	 * returned from `verifyAccessTokenCallback()` for use within callbacks.
	 * 
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 */
	public function handleRequest(ServerRequestInterface $request) {
		// Make $request available to callbacks.
		$this->request = $request;

		$logger = $this->getLogger();
		
		// Get and verify auth token.
		$accessToken = getAccessToken($request);
		if ($accessToken === null) {
			$logger->warning($this->errorMessages['unauthorized']);
			return new Response(401, ['content-type' => 'application/json'], json_encode([
				'error' => 'unauthorized',
				'error_description' => $this->errorMessages['unauthorized']
			]));
		}

		$accessTokenResult = $this->verifyAccessTokenCallback($accessToken);
		if ($accessTokenResult instanceof ResponseInterface) {
			return $accessTokenResult; // Short-circuit.
		} elseif ($accessTokenResult) {
			// Log success.
			$logger->info('Access token verified successfully.', ['user' => $accessTokenResult]);
			$this->user = $accessTokenResult;
		} else {
			// Log error, return not authorized response.
			$logger->error($this->errorMessages['access_token_invalid']);
			return new Response(403, ['content-type' => 'application/json'], json_encode([
				'error' => 'forbidden',
				'error_description' => $this->errorMessages['access_token_invalid']
			]));
		}
		
		// Give subclasses an opportunity to pre-emptively handle any extension cases before moving on to
		// standard micropub handling.
		$extensionCallbackResult = $this->extensionCallback($request);
		if ($extensionCallbackResult) {
			return $this->toResponse($extensionCallbackResult);
		}

		// Check against method.
		if (strtolower($request->getMethod()) == 'get') {
			$queryParams = $request->getQueryParams();

			if (array_key_exists('q', $queryParams)) {
				$q = $queryParams['q'];
				if ($q == 'config') {
					// Handle configuration query.
					$logger->info('Handling config query', $queryParams);
					return $this->toResponse($this->configurationQueryCallback($queryParams));
				} elseif ($q == 'source') {
					// Handle source query.
					$logger->info('Handling source query', $queryParams);

					// Normalize properties([]) paramter.
					if (array_key_exists('properties[]', $queryParams)) {
						$sourceProperties = $q['properties[]'];
					} elseif (array_key_exists('properties', $queryParams)) {
						$sourceProperties = [$q['properties']];
					} else {
						$sourceProperties = null;
					}

					// Check for a url parameter.
					if (!array_key_exists('url', $queryParams)) {
						$logger->error($this->errorMessages['missing_url_parameter']);
						return $this->toResponse(json_encode([
							'error' => 'invalid_request',
							'error_description' => $this->errorMessages['missing_url_parameter']
						]), 400);
					}

					$sourceQueryResult = $this->sourceQueryCallback($queryParams['url'], $sourceProperties);
					if ($sourceQueryResult === false) {
						// Returning false is a shortcut for an “invalid URL” error.
						$logger->error($this->errorMessages['post_with_given_url_not_found']);
						$sourceQueryResult = [
							'error' => 'invalid_request',
							'error_description' => $this->errorMessages['post_with_given_url_not_found']
						];
					}

					return $this->toResponse($sourceQueryResult);
				} elseif ($q == 'syndicate-to') {
					// Handle syndicate-to query via the configuration query callback.
					$logger->info('Handling syndicate-to query.', $queryParams);
					$configQueryResult = $this->configurationQueryCallback($queryParams);
					if ($configQueryResult instanceof ResponseInterface) {
						return $configQueryResult; // Short-circuit, assume that the response from q=config will suffice for q=syndicate-to.
					} elseif (is_array($configQueryResult and array_key_exists('syndicate-to', $configQueryResult))) {
						return new Response(200, ['content-type' => 'application/json'], json_encode([
							'syndicate-to' => $configQueryResult['syndicate-to']
						]));
					} else {
						// We don’t have anything to return, so return an empty object.
						return new Response(200, ['content-type' => 'application/json'], '{}');
					}
				}
			}

			// We weren’t able to handle this GET request.
			$logger->error('Micropub endpoint was not able to handle GET request', $queryParams);
			return $this->toResponse('invalid_request');
		} elseif (strtolower($request->getMethod()) == 'post') {
			$contentType = $request->getHeader('content-type')[0];
			$jsonRequest = $contentType == 'application/json';
			
			// Get a parsed body sufficient to determine the nature of the request.
			if ($jsonRequest) {
				$parsedBody = json_decode($request->getBody()->getContents(), true);
			} else {
				$parsedBody = $request->getParsedBody();
			}
			
			// Check for action.
			if (array_key_exists('action', $parsedBody)) {
				$action = $parsedBody['action'];
				if ($action == 'delete') {
					// Handle delete request.
					$logger->info('Handling delete request.', $parsedBody);
					if (array_key_exists('url', $parsedBody)) {
						$deleteResult = $this->deleteCallback($parsedBody['url']);
						if ($deleteResult === true) {
							// If the delete was successful, respond with an empty 204 response.
							return $this->toResponse('', 204);
						} else {
							return $this->toResponse($deleteResult);
						}
					} else {
						$logger->warning($this->errorMessages['missing_url_parameter']);
						return new Response(400, ['content-type' => 'application/json'], json_encode([
							'error' => 'invalid_request',
							'error_description' => $this->errorMessages['missing_url_parameter']
						]));
					}
					
				} elseif ($action == 'undelete') {
					// Handle undelete request.
					if (array_key_exists('url', $parsedBody)) {
						$undeleteResult = $this->undeleteCallback($parsedBody['url']);
						if ($undeleteResult === true) {
							// If the delete was successful, respond with an empty 204 response.
							return $this->toResponse('', 204);
						} elseif (is_string($undeleteResult) and !in_array($undeleteResult, MICROPUB_ERROR_CODES)) {
							// The non-error-code string returned from undelete is the URL of the new location of the
							// undeleted content.
							return new Response(201, ['location' => $undeleteResult]);
						} else {
							return $this->toResponse($undeleteResult);
						}
					} else {
						$logger->warning($this->errorMessages['missing_url_parameter']);
						return new Response(400, ['content-type' => 'application/json'], json_encode([
							'error' => 'invalid_request',
							'error_description' => $this->errorMessages['missing_url_parameter']
						]));
					}
				} elseif ($action == 'update') {
					// Handle update request.
					// Check for the required url parameter.
					if (!array_key_exists('url', $parsedBody)) {
						return new Response(400, ['content-type' => 'application/json'], json_encode([
							'error' => 'invalid_request',
							'error_description' => $this->errorMessages['missing_url_parameter']
						]));
					}
					
					$updateResult = $this->updateCallback($parsedBody['url'], $parsedBody);
					if ($updateResult === true) {
						// Basic success.
						return $this->toResponse('', 204);
					} elseif (is_string($updateResult) and !in_array($updateResult, MICROPUB_ERROR_CODES)) {
						// The non-error-code string returned from update is the URL of the new location of the
						// undeleted content.
						return new Response(201, ['location' => $updateResult]);
					} else {
						return $this->toResponse($updateResult);
					}
				}
			}

			// Assume that the request is a Create request.
			// If we’re dealing with an x-www-form-urlencoded or multipart/form-data request,
			// normalise form data to match JSON structure.
			if (!$jsonRequest) {
				$parsedBody = normalizeUrlencodedCreateRequest($parsedBody);
			}

			// Pass data off to create callback.
			$createResponse = $this->createCallback($parsedBody, $request->getUploadedFiles());
			if (is_string($createResponse) and !in_array($createResponse, MICROPUB_ERROR_CODES)) {
				// Success, return HTTP 201 with Location header.
				return new Response(201, ['location' => $createResponse]);
			} else {
				return $this->toResponse($createResponse);
			}

		}
		
		// Request method was something other than GET or POST.
		return $this->toResponse('invalid_request');
	}

	/**
	 * Handle Media Endpoint Request
	 * 
	 * Handle a request to a micropub media-endpoint.
	 * 
	 * As with `handleRequest()`, `$this->request` and `$this->user` are made available
	 * for use within callbacks.
	 * 
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 */
	public function handleMediaEndpointRequest(ServerRequestInterface $request) {
		$logger = $this->getLogger();
		$this->request = $request;

		// Get and verify auth token.
		$accessToken = getAccessToken($request);
		if ($accessToken === null) {
			$logger->warning($this->errorMessages['unauthorized']);
			return new Response(401, ['content-type' => 'application/json'], json_encode([
				'error' => 'unauthorized',
				'error_description' => $this->errorMessages['unauthorized']
			]));
		}

		$accessTokenResult = $this->verifyAccessTokenCallback($accessToken);
		if ($accessTokenResult instanceof ResponseInterface) {
			return $accessTokenResult; // Short-circuit.
		} elseif ($accessTokenResult) {
			// Log success.
			$logger->info('Access token verified successfully.', ['user' => $accessTokenResult]);
			$this->user = $accessTokenResult;
		} else {
			// Log error, return not authorized response.
			$logger->error($this->errorMessages['access_token_invalid']);
			return new Response(403, ['content-type' => 'application/json'], json_encode([
				'error' => 'forbidden',
				'error_description' => $this->errorMessages['access_token_invalid']
			]));
		}

		// Give implementations a chance to pre-empt regular media endpoint handling, in order
		// to implement extensions.
		$mediaEndpointExtensionResult = $this->mediaEndpointExtensionCallback($request);
		if ($mediaEndpointExtensionResult) {
			return $this->toResponse($mediaEndpointExtensionResult);
		}

		// Only support POST requests to the media endpoint.
		if (strtolower($request->getMethod()) != 'post') {
			$logger->error('Got a non-POST request to the media endpoint', ['method' => $request->getMethod()]);
			return $this->toResponse('invalid_request');
		}

		// Look for the presence of an uploaded file called 'file'
		if (array_key_exists('file', $request->getUploadedFiles())) {
			// This is most probably a media endpoint request.
			$mediaCallbackResult = $this->mediaEndpointCallback($request->getUploadedFiles()['file']);

			if ($mediaCallbackResult) {
				if (is_string($mediaCallbackResult) and !in_array($mediaCallbackResult, MICROPUB_ERROR_CODES)) {
					// Success! Return an HTTP 201 response with the location header.
					return new Response(201, ['location' => $mediaCallbackResult]);
				}

				// Otherwise, handle whatever it is we got.
				return $this->toResponse($mediaCallbackResult);
			}
		}

		// Either no file was provided, or mediaEndpointCallback returned a falsy value.
		return $this->toResponse('invalid_request');
	}

	/**
	 * To Response
	 * 
	 * Intelligently convert various shortcuts into a suitable instance of
	 * ResponseInterface. Existing ResponseInterfaces are passed through
	 * without alteration.
	 * 
	 * @param string|array|ResponseInterface $resultOrResponse
	 * @param int $status=200
	 * @return ResponseInterface
	 */
	function toResponse($resultOrResponse, $status=200) {
		if ($resultOrResponse instanceof ResponseInterface) {
			return $resultOrResponse;
		}

		// Convert micropub error messages into error responses.
		if (is_string($resultOrResponse) && in_array($resultOrResponse, MICROPUB_ERROR_CODES)) {
			$resultOrResponse = [
				'error' => $resultOrResponse,
				'error_description' => $this->errorMessages[$resultOrResponse]
			];
		}

		if ($resultOrResponse === null) {
			$resultOrResponse = '{}'; // Default to an empty object response if none given.
		} elseif (is_array($resultOrResponse)) {
			// If this is a known error response, adjust the status accordingly.
			if (array_key_exists('error', $resultOrResponse)) {
				if ($resultOrResponse['error'] == 'invalid_request') {
					$status = 400;
				} elseif ($resultOrResponse['error'] == 'unauthorized') {
					$status = 401;
				} elseif ($resultOrResponse['error'] == 'insufficient_scope') {
					$status = 403;
				} elseif ($resultOrResponse['error'] == 'forbidden') {
					$status = 403;
				}
			}
			$resultOrResponse = json_encode($resultOrResponse);
		}
		return new ResponseInterface($status, ['content-type' => 'json'], $resultOrResponse);
	}
}

/**
 * Get Access Token
 * 
 * Given a request, return the Micropub access token, or null.
 * 
 * @return string|null
 */
function getAccessToken(ServerRequestInterface $request) {
	if ($request->hasHeader('authorization')) {
		foreach ($request->getHeader('authorization') as $authVal) {
			if (strtolower(substr($authVal, 0, 6)) == 'bearer') {
				return substr($authVal, 7);
			}
		}
	}
	
	$parsedBody = $request->getParsedBody();
	if (array_key_exists('access_token', $parsedBody)) {
		return $parsedBody['access_token'];
	}

	return null;
}

/**
 * Normalize URL-encoded Create Request
 * 
 * Given an array of PHP-parsed form parameters (such as from $_POST), convert
 * them into canonical microformats2 format.
 * 
 * @param array $body
 * @return array 
 */
function normalizeUrlencodedCreateRequest(array $body) {
	$result = [
		'type' => ['h-entry'],
		'properties' => []
	];

	foreach ($body as $key => $value) {
		if ($key == 'h') {
			$result['type'] = ["h-$value"];
		} elseif (is_array($value)) {
			$result['properties'][rtrim($key, '[]')] = $value;
		} else {
			$result['properties'][$key] = [$value];
		}
	}

	return $result;
}
