# taproot/micropub-adapter

[![Latest Stable Version](http://poser.pugx.org/taproot/micropub-adapter/v)](https://packagist.org/packages/taproot/micropub-adapter) <a href="https://github.com/Taproot/micropub-adapter/actions/workflows/php.yml"><img src="https://github.com/taproot/micropub-adapter/actions/workflows/php.yml/badge.svg?branch=main" alt="" /></a> [![License](http://poser.pugx.org/taproot/micropub-adapter/license)](https://packagist.org/packages/taproot/micropub-adapter) [![Total Downloads](http://poser.pugx.org/taproot/micropub-adapter/downloads)](https://packagist.org/packages/taproot/micropub-adapter)

taproot/micropub-adapter is a simple and flexible way to add [Micropub](https://indieweb.org/Micropub) support to any PHP web app using PSR-7.

## Quick Links

* [API Documentation](https://taproot.github.io/micropub-adapter/namespaces/taproot-micropub.html)
* [Code Coverage](https://taproot.github.io/micropub-adapter/coverage/)
* [micropub.rocks implementation report](https://micropub.rocks/implementation-reports/servers/580/D3vyg58QCHfWI4TavNiT)

## Installation

taproot/micropub-adapter is currently tested against and compatible with PHP 7.3, 7.4, 8.0 and 8.1.

Install taproot/micropub-adapter using [composer](https://getcomposer.org/):

    composer.phar require taproot/micropub-adapter
    composer.phar install (or composer.phar update)

Versioned releases are GPG signed so you can verify that the code hasn’t been tampered with.

    gpg --recv-keys 1C00430B19C6B426922FE534BEF8CE58118AD524
    cd vendor/taproot/micropub-adapter
    git tag -v v0.1.2 # Replace with the version you have installed

## Usage

### Subclassing MicropubAdapter

micropub-adapter defines an abstract class, [`Taproot\Micropub\MicropubAdapter`](https://taproot.github.io/micropub-adapter/classes/Taproot-Micropub-MicropubAdapter.html), which implements the request handling logic for micropub and micropub media endpoints. It parses incoming micropub requests and dispatches them to callback methods for each action (create, delete, update, etc.). It handles basic validation and error conditions, normalises incoming data for you, and converts return values from callback methods to valid responses.

All you need to do is subclass `MicropubAdapter` and implement the relevant callback methods for the actions you want to support. Then, in your app, make an instance of your adapter, and call `handleRequest()` and `handleMediaEndpointRequest()` within your micropub and media endpoint requests, respectively.

See [the example app](https://github.com/Taproot/micropub-adapter/tree/main/example) for an example of how to subclass and use `MicropubAdapter`.

#### Callback Methods

Refer to the API documentation for the paramters and possible return values of each callback. Optional callbacks have a default no-op implementation.

**Required** for any functionality:

* [`verifyAccessTokenCallback()`](https://taproot.github.io/micropub-adapter/classes/Taproot-Micropub-MicropubAdapter.html#method_verifyAccessTokenCallback): this callback is responsible for validating the access token used to authorize micropub requests, and providing user/scope data for use in the other callbacks.

**Micropub endpoint action callbacks** — implement whichever are relevant for your use-case:

* [`configurationQueryCallback()`](https://taproot.github.io/micropub-adapter/classes/Taproot-Micropub-MicropubAdapter.html#method_configurationQueryCallback)
* [`sourceQueryCallback()`](https://taproot.github.io/micropub-adapter/classes/Taproot-Micropub-MicropubAdapter.html#method_sourceQueryCallback)
* [`createCallback()`](https://taproot.github.io/micropub-adapter/classes/Taproot-Micropub-MicropubAdapter.html#method_createCallback)
* [`updateCallback()`](https://taproot.github.io/micropub-adapter/classes/Taproot-Micropub-MicropubAdapter.html#method_updateCallback)
* [`deleteCallback()`](https://taproot.github.io/micropub-adapter/classes/Taproot-Micropub-MicropubAdapter.html#method_deleteCallback)
* [`undeleteCallback()`](https://taproot.github.io/micropub-adapter/classes/Taproot-Micropub-MicropubAdapter.html#method_undeleteCallback)

**Media Endpoint callbacks** — implement to enable the media endpoint. As routing is out of the scope of this library, you’ll have to add a `media-endpoint` value to the array returned by `configurationQueryCallback()` in order for clients to be able to discover the media endpoint.

* [`mediaEndpointCallback()`](https://taproot.github.io/micropub-adapter/classes/Taproot-Micropub-MicropubAdapter.html#method_mediaEndpointCallback)

**Extension callbacks** — these are called after the incoming request is authenticated, but before any micropub-specific handling occurs. This allows your subclass to implement [micropub extensions](https://indieweb.org/Micropub-extensions). Implementations of these methods should check to see if a request requires extension handling (e.g. a `?q=source` request without a `url` parameter, which would return an error if handled by the logic surrounding `sourceQueryCallback()`). If the request requires extension handling, handle it and return a Response or error value. Otherwise, return `false` to continue handling the request as usual.

* [`extensionCallback()`](https://taproot.github.io/micropub-adapter/classes/Taproot-Micropub-MicropubAdapter.html#method_extensionCallback)
* [`mediaEndpointExtensionCallback()`](https://taproot.github.io/micropub-adapter/classes/Taproot-Micropub-MicropubAdapter.html#method_mediaEndpointExtensionCallback)

### Access Tokens and IndieAuth

You’ll need some way of verifying the access tokens used to authenticate micropub requests — indeed, `verifyAccessTokenCallback()` is the only method which you’re absolutely required to implement! If your app doesn’t yet have [IndieAuth](https://indieweb.org/IndieAuth) endpoints capable of creating and verifying access tokens, you may want to use the companion library [taproot/indieauth](https://github.com/taproot/indieauth/) to add indieauth support to your app. The example app uses taproot/indieauth, so you can refer to that for an example of how to use the two libraries together.

## Contributing

If you have any questions about using this library, join the [indieweb chatroom](https://indieweb.org/discuss) and ping `barnaby`.

If you find a bug or problem with the library, or want to suggest a feature, please [create an issue](https://github.com/Taproot/micropub-adapter/issues/new).

If discussions lead to you wanting to submit a pull request, following this process, while not required, will increase the chances of it quickly being accepted:

* Fork this repo to your own github account, and clone it to your development computer.
* Run `./run_coverage.sh` and ensure that all tests pass — you’ll need XDebug for code coverage data.
* If applicable, write failing regression tests e.g. for a bug you’re fixing.
* Make your changes.
* Run `./run_coverage.sh` and `open docs/coverage/index.html`. Make sure that the changes you made are covered by tests. taproot/micropub-adapter had 100% test coverage from version 0.1.0, and that number should never go down!
* Run `./vendor/bin/psalm` and and fix any warnings it brings up.
* Install and run `./phpDocumentor.phar` to regenerate the documentation if applicable.
* Push your changes and submit the PR.

## Changelog

### v0.1.2
2023-07-24

* Handle JSON requests correctly when there is more than one `content-type` header — thanks @oddevan!

### v0.1.1
2022-10-03

* Updated example to use latest features from taproot/indieauth
* Allowed use of psr/log v2 and v3
* Allowed use of monolog v3 when testing
* Added PHP 8.1 to the test matrix, enabled manual dispatch.

### v0.1.0
2021-06-24

Initial release.
