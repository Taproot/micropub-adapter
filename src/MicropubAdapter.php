<?php declare(strict_types=1);

namespace Taproot\Micropub;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Config
 * 
 * 
 */
class Config {

}

/**
 * Handle Request
 * 
 * Handle an incoming request to a webmention endpoint, dispatching it to the appropriate 
 * callback defined in the $config parameter.
 * 
 * @param RequestInterface $request
 * @param Config $config
 * @return 
 */
function handleRequest(RequestInterface $request, Config $config) {
	return;
}
