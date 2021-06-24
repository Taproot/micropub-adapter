<?php

namespace Taproot\Micropub\Example;

use DateTime;
use DateTimeZone;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Taproot\Micropub\MicropubAdapter;
use Taproot\IndieAuth;
use Webmozart\PathUtil\Path;

/**
 * Example Micropub Adapter
 * 
 * An example of how to subclass MicropubAdapter to make a fully functional micropub
 * endpoint.
 */
class ExampleMicropubAdapter extends MicropubAdapter {
	protected IndieAuth\Server $indieAuthServer;

	protected array $config;

	/**
	 * Constructor
	 * 
	 * Subclasses will need a way of validating access tokens. In this case, that’s provided
	 * by passing in the instance of Taproot\IndieAuth\Server used to handle authentication.
	 */
	public function __construct(IndieAuth\Server $indieAuthServer, array $config, ?LoggerInterface $logger=null) {
		$this->indieAuthServer = $indieAuthServer;
		$this->config = $config;
		$this->logger = $logger ?? new NullLogger();
	}

	public function verifyAccessTokenCallback(string $token) {
		// If the provided access token refers to a valid access token, return the user
		// data, which will be available on $this->user for other callbacks to refer to.
		if ($userData = $this->indieAuthServer->getTokenStorage()->getAccessToken($token)) {
			// Convert space-separated string scope data into an array, for convenience.
			$userData['scope'] = explode(' ', $userData['scope'] ?? '');
			return $userData;
		}

		return false;
	}

	public function createCallback(array $data, array $uploadedFiles) {
		// Make sure the user has the create scope.
		if (!in_array('create', $this->user['scope'])) {
			return 'insufficient_scope';
		}

		// Set some internal properties.
		$dtCreated = new DateTime('now', new DateTimeZone($config['timezone'] ?? '+0000'));

		$data['properties']['published'] = [$dtCreated->format('c')];
		$data['properties']['author'] = [$this->user['me']];

		// As this is a simple example, we’re taking the lazy approach and making a UUID for each post.
		$postId = uniqid();
		$postUrl = (string) $this->request->getUri()->withFragment('')->withQuery('')->withPath("/posts/$postId");
		$data['properties']['url'] = [$postUrl];
		$data['id'] = $postId;

		// Handle any uploaded files.
		foreach ($uploadedFiles as $fileProp => $files) {
			// $files can either be an instance of UploadedFileInterface, or an array of the same, if
			// multiple files were uploaded under the same key.
			if (!is_array($files)) {
				$files = [$files];
			}

			foreach ($files as $file) {
				// Move the uploaded file to a permanent location, renaming it along the way.
				$fileName = sprintf(
					'%s.%s',
					uniqid(),
					pathinfo($file->getClientFilename(), PATHINFO_EXTENSION)
				);
				$filePath = Path::join($this->config['uploaded_file_path'], $fileName);
				$fileUrl = Path::join($this->config['uploaded_file_url'], $fileName);
				$file->moveTo($filePath);

				// Add the file to the post under its corresponding property name.
				if (!isset($data['properties'][$fileProp])) {
					$data['properties'][$fileProp] = [];
				}

				$data['properties'][$fileProp][] = $fileUrl;

				// Make an internal note of the file path, to ease automatic deletion
				// of uploads associated with posts.
				if (!isset($data['uploaded_file'])) {
					$data['uploaded_files'];
				}
				$data['uploaded_files'][] = $filePath;
			}
		}

		// Store some internal data outside the microformats2 structure, for reference.
		$data['access_token'] = $this->user;

		// Save the newly created post.
		$result = $this->savePost($postId, $data);
		if (false === $result) {
			return [
				'error' => 'internal_error',
				'error_description' => 'Saving the post failed.'
			];
		}

		// If everything worked out, return the URL of the newly-created post.
		return $postUrl;
	}

	public function updateCallback(string $url, array $actions) {
		// First, check that the user is allowed to update.
		if (!in_array('update', $this->user['scope'])) {
			return 'insufficient_scope';
		}

		// Check that the provided URL identifies a post we can update.
		if (!IndieAuth\urlComponentsMatch((string) $this->request->getUri(), $url, [PHP_URL_HOST])) {
			return 'invalid_request';
		}

		$urlPath = parse_url($url, PHP_URL_PATH);
		if (strpos($urlPath, '/posts/') !== 0) {
			return 'invalid_request';
		}

		$postId = rtrim(substr($urlPath, 7), '/');

		$postData = $this->getPostById($postId);

		if (!is_array($postData)) {
			return 'invalid_request';
		}

		// Make sure the post isn’t already deleted.
		if ($postData['deleted'] ?? false) {
			return 'invalid_request';
		}

		// At this point, we finally have a valid post which we can update.
		foreach ($actions['replace'] ?? [] as $propName => $newVal) {
			$postData['properties'][$propName] = $newVal;
		}

		foreach ($actions['add'] ?? [] as $propName => $newVal) {
			if (!isset($postData['properties'][$propName])) {
				$postData['properties'][$propName] = [];
			}
			$postData['properties'][$propName] = array_merge($postData['properties'][$propName], $newVal);
		}

		foreach ($actions['delete'] ?? [] as $key => $val) {
			if (is_string($key)) {
				// We’re deleting specific values from a multi-value property.
				$postData['properties'][$key] = array_filter($postData['properties'][$key] ?? [], function ($v) use ($val) {
					return !in_array($v, $val);
				});
			} else {
				// We’re deleting an entire property.
				unset($postData['properties'][$val]);
			}
		}

		// Save the post.
		if (false === $this->savePost($postId, $postData)) {
			return 'internal_error';
		}

		return true;
	}

	public function deleteCallback(string $url) {
		// First, check that the user is allowed to delete.
		if (!in_array('delete', $this->user['scope'])) {
			return 'insufficient_scope';
		}

		// Check that the provided URL identifies a post we can update.
		if (!IndieAuth\urlComponentsMatch((string) $this->request->getUri(), $url, [PHP_URL_HOST])) {
			return 'invalid_request';
		}

		$urlPath = parse_url($url, PHP_URL_PATH);
		if (strpos($urlPath, '/posts/') !== 0) {
			return 'invalid_request';
		}

		$postId = rtrim(substr($urlPath, 7), '/');

		$postData = $this->getPostById($postId);

		if (!is_array($postData)) {
			return 'invalid_request';
		}

		// Make sure the post isn’t already deleted.
		if ($postData['deleted'] ?? false) {
			return 'invalid_request';
		}

		// At this point, we finally have a valid post which we can delete.
		$postData['deleted'] = true;
		if (false === $this->savePost($postId, $postData)) {
			return 'internal_error';
		}

		return true;
	}

	public function undeleteCallback(string $url) {
		// First, check that the user is allowed to undelete.
		if (!in_array('undelete', $this->user['scope'])) {
			return 'insufficient_scope';
		}

		// Check that the provided URL identifies a post we can update.
		if (!IndieAuth\urlComponentsMatch((string) $this->request->getUri(), $url, [PHP_URL_HOST])) {
			return 'invalid_request';
		}

		$urlPath = parse_url($url, PHP_URL_PATH);
		if (strpos($urlPath, '/posts/') !== 0) {
			return 'invalid_request';
		}

		$postId = rtrim(substr($urlPath, 7), '/');

		$postData = $this->getPostById($postId);

		if (!is_array($postData)) {
			return 'invalid_request';
		}

		// Make sure the post can be undeleted.
		if (!($postData['deleted'] ?? false)) {
			return 'invalid_request';
		}

		// At this point, we finally have a valid post which we can undelete.
		unset($postData['deleted']);
		if (false === $this->savePost($postId, $postData)) {
			return 'internal_error';
		}

		return true;
	}

	public function configurationQueryCallback(array $params) {
		return [
			'media-endpoint' => (string) $this->request->getUri()->withQuery('')->withFragment('')->withPath('/media-endpoint')
		];
	}

	public function sourceQueryCallback(string $url, ?array $properties=null) {
		// Check that the provided URL identifies a post we can update.
		if (!IndieAuth\urlComponentsMatch((string) $this->request->getUri(), $url, [PHP_URL_HOST])) {
			return 'invalid_request';
		}

		$urlPath = parse_url($url, PHP_URL_PATH);
		if (strpos($urlPath, '/posts/') !== 0) {
			return 'invalid_request';
		}

		$postId = rtrim(substr($urlPath, 7), '/');

		$postData = $this->getPostById($postId);

		if (!is_array($postData)) {
			return 'invalid_request';
		}

		// Make sure the post isn’t deleted.
		if ($postData['deleted'] ?? false) {
			return 'invalid_request';
		}

		if (is_null($properties)) {
			// Return the whole post.
			return [
				'type' => $postData['type'],
				'properties' => $postData['properties']
			];
		} else {
			// If the request only wants specific properties, limit the properties returned to those.
			return [
				'properties' => array_filter($postData['properties'], function ($propName) use ($properties) {
					return in_array($propName, $properties);
				}, ARRAY_FILTER_USE_KEY)
			];
		}
	}

	public function mediaEndpointCallback(UploadedFileInterface $file) {
		// Check that the request has sufficient scope.
		if (!in_array('create', $this->user['scope'])) {
			return 'insufficient_scope';
		}

		// Move the uploaded file to a permanent location, renaming it along the way.
		$fileName = sprintf(
			'%s.%s',
			uniqid(),
			pathinfo($file->getClientFilename(), PATHINFO_EXTENSION)
		);
		$filePath = Path::join($this->config['uploaded_file_path'], $fileName);
		$fileUrl = Path::join($this->config['uploaded_file_url'], $fileName);
		$file->moveTo($filePath);

		return (string) $this->request->getUri()->withQuery('')->withFragment('')->withPath($fileUrl);
	}

	// Some internal methods which are not part of the micropub handling interface, but
	// which are useful both internally and by other routes.

	public function getPostById(string $id) {
		$entryFilename = "$id.json";
		$entriesPath = Path::canonicalize(__DIR__.'/../data/posts/');
		$entryPath = Path::canonicalize(Path::join($entriesPath, $entryFilename));

		// Prevent files outside the entries folder from being accessed.
		if (strpos($entryPath, $entriesPath) !== 0 or !file_exists($entryPath)) {
			return null;
		}

		return json_decode(file_get_contents($entryPath), true);
	}

	public function savePost(string $id, array $data) {
		return file_put_contents(__DIR__."/../data/posts/$id.json", json_encode($data, JSON_PRETTY_PRINT));
	}
}
