<?php declare(strict_types=1);

namespace Taproot\Micropub\Tests;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Taproot\Micropub\MicropubAdapter;

class MicropubAdapterMock extends MicropubAdapter {
	public $callbackResponses;
	
	public function __construct(array $callbackResponses) {
		if (!array_key_exists('verifyAccessTokenCallback', $callbackResponses)) {
			die('$callbackResponses MUST contain a response for verifyAccessTokenCallback.');
		}

		$this->callbackResponses = $callbackResponses;
	}

	public function verifyAccessTokenCallback(string $token) {
		return $this->callbackResponses['verifyAccessTokenCallback'];
	}

	public function extensionCallback(ServerRequestInterface $request) {
		if (array_key_exists('extensionCallback', $this->callbackResponses)) {
			return $this->callbackResponses['extensionCallback'];
		}
		
		return parent::extensionCallback($request);
	}

	public function configurationQueryCallback(array $params) {
		if (array_key_exists('configurationQueryCallback', $this->callbackResponses)) {
			return $this->callbackResponses['configurationQueryCallback'];
		}
		
		return parent::configurationQueryCallback($params);
	}

	public function sourceQueryCallback(string $url, ?array $properties = null) {
		if (array_key_exists('sourceQueryCallback', $this->callbackResponses)) {
			return $this->callbackResponses['sourceQueryCallback'];
		}
		
		return parent::sourceQueryCallback($url, $properties);
	}

	public function deleteCallback(string $url) {
		if (array_key_exists('deleteCallback', $this->callbackResponses)) {
			return $this->callbackResponses['deleteCallback'];
		}
		
		return parent::deleteCallback($url);
	}

	public function undeleteCallback(string $url) {
		if (array_key_exists('undeleteCallback', $this->callbackResponses)) {
			return $this->callbackResponses['undeleteCallback'];
		}
		
		return parent::undeleteCallback($url);
	}

	public function updateCallback(string $url, array $actions) {
		if (array_key_exists('updateCallback', $this->callbackResponses)) {
			return $this->callbackResponses['updateCallback'];
		}
		
		return parent::updateCallback($url, $actions);
	}

	public function createCallback(array $data, array $uploadedFiles) {
		if (array_key_exists('createCallback', $this->callbackResponses)) {
			return $this->callbackResponses['createCallback'];
		}
		
		return parent::createCallback($data, $uploadedFiles);
	}

	public function mediaEndpointCallback(UploadedFileInterface $file) {
		if (array_key_exists('mediaEndpointCallback', $this->callbackResponses)) {
			return $this->callbackResponses['mediaEndpointCallback'];
		}
		
		return parent::mediaEndpointCallback($file);
	}

	public function mediaEndpointExtensionCallback(ServerRequestInterface $request) {
		if (array_key_exists('mediaEndpointExtensionCallback', $this->callbackResponses)) {
			return $this->callbackResponses['mediaEndpointExtensionCallback'];
		}
		
		return parent::mediaEndpointExtensionCallback($request);
	}
}
