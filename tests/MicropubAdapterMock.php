<?php declare(strict_types=1);

namespace Taproot\Micropub\Tests;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Taproot\Micropub\MicropubAdapter;

// Callbacks can legitimately return null, so resolve() returns a special value to indicate
// that the callback isn’t “implemented” at all.
const CALLBACK_NOT_IMPLEMENTED = 999999999999999;

/**
 * Micropub Adapter Mock
 * 
 * A barebones subclass of MicropubAdapter used for testing.
 * 
 * Each callback method returns the value under the same key as it’s method name within
 * `$this->callbackResponses` if present, otherwise it calls the superclass method to
 * simulate the method not being implemented.
 */
class MicropubAdapterMock extends MicropubAdapter {
	public $callbackResponses;
	
	private function resolve($callback, $args) {
		if (array_key_exists($callback, $this->callbackResponses)) {
			if (is_callable($this->callbackResponses[$callback])) {
				return call_user_func_array($this->callbackResponses[$callback], $args);
			} else {
				return $this->callbackResponses[$callback];
			}
		}
		return CALLBACK_NOT_IMPLEMENTED;
	}

	public function __construct(array $callbackResponses) {
		if (!array_key_exists('verifyAccessTokenCallback', $callbackResponses)) {
			die('$callbackResponses MUST contain a response for verifyAccessTokenCallback.');
		}

		$this->callbackResponses = $callbackResponses;
	}

	public function verifyAccessTokenCallback(string $token) {
		return $this->resolve('verifyAccessTokenCallback', [$token]);
	}

	public function extensionCallback(ServerRequestInterface $request) {
		$r = $this->resolve('extensionCallback', [$request]);
		if ($r !== CALLBACK_NOT_IMPLEMENTED) {
			return $r;
		}
		
		return parent::extensionCallback($request);
	}

	public function configurationQueryCallback(array $params) {
		$r = $this->resolve('configurationQueryCallback', [$params]);
		if ($r !== CALLBACK_NOT_IMPLEMENTED) {
			return $r;
		}
		
		return parent::configurationQueryCallback($params);
	}

	public function sourceQueryCallback(string $url, ?array $properties = null) {
		$r = $this->resolve('sourceQueryCallback', [$url, $properties]);
		if ($r !== CALLBACK_NOT_IMPLEMENTED) {
			return $r;
		}
		
		return parent::sourceQueryCallback($url, $properties);
	}

	public function deleteCallback(string $url) {
		$r = $this->resolve('deleteCallback', [$url]);
		if ($r !== CALLBACK_NOT_IMPLEMENTED) {
			return $r;
		}
		
		return parent::deleteCallback($url);
	}

	public function undeleteCallback(string $url) {
		$r = $this->resolve('undeleteCallback', [$url]);
		if ($r !== CALLBACK_NOT_IMPLEMENTED) {
			return $r;
		}
		
		return parent::undeleteCallback($url);
	}

	public function updateCallback(string $url, array $actions) {
		$r = $this->resolve('updateCallback', [$url, $actions]);
		if ($r !== CALLBACK_NOT_IMPLEMENTED) {
			return $r;
		}
		
		return parent::updateCallback($url, $actions);
	}

	public function createCallback(array $data, array $uploadedFiles) {
		$r = $this->resolve('createCallback', [$data, $uploadedFiles]);
		if ($r !== CALLBACK_NOT_IMPLEMENTED) {
			return $r;
		}
		
		return parent::createCallback($data, $uploadedFiles);
	}

	public function mediaEndpointCallback(UploadedFileInterface $file) {
		$r = $this->resolve('mediaEndpointCallback', [$file]);
		if ($r !== CALLBACK_NOT_IMPLEMENTED) {
			return $r;
		}
		
		return parent::mediaEndpointCallback($file);
	}

	public function mediaEndpointExtensionCallback(ServerRequestInterface $request) {
		$r = $this->resolve('mediaEndpointExtensionCallback', [$request]);
		if ($r !== CALLBACK_NOT_IMPLEMENTED) {
			return $r;
		}
		
		return parent::mediaEndpointExtensionCallback($request);
	}
}
