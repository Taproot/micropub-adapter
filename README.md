# taproot/micropub-adapter

[![Latest Stable Version](http://poser.pugx.org/taproot/micropub-adapter/v)](https://packagist.org/packages/taproot/micropub-adapter) <a href="https://github.com/Taproot/micropub-adapter/actions/workflows/php.yml"><img src="https://github.com/taproot/micropub-adapter/actions/workflows/php.yml/badge.svg?branch=main" alt="" /></a> [![License](http://poser.pugx.org/taproot/micropub-adapter/license)](https://packagist.org/packages/taproot/micropub-adapter) [![Total Downloads](http://poser.pugx.org/taproot/micropub-adapter/downloads)](https://packagist.org/packages/taproot/micropub-adapter)

taproot/micropub-adapter is a simple and flexible way to add [Micropub](https://indieweb.org/Micropub) support to any PHP web app which uses PSR-7.

## Quick Links

* [API Documentation](https://taproot.github.io/micropub-adapter/)
* [Code Coverage](https://taproot.github.io/micropub-adapter/coverage/)
* [micropub.rocks implementation report](https://micropub.rocks/implementation-reports/servers/580/D3vyg58QCHfWI4TavNiT)

## Usage

micropub-adapter defines an abstract class, [`Taproot\Micropub\MicropubAdapter`](https://taproot.github.io/micropub-adapter/classes/Taproot-Micropub-MicropubAdapter.html), which implements the request handling logic for micropub and micropub media endpoints.

`MicropubAdapter` parses incoming micropub requests and dispatches them to callback methods for each action (create, delete, update, etc.). All you need to do is subclass `MicropubAdapter` and implement the relevant callback methods for the actions you want to support. Then, in your app, make an instance of your adapter, and call `handleRequest()` and `handleMediaEndpointRequest()` within your micropub and media endpoint requests, respectively.

See [the example app](https://github.com/Taproot/micropub-adapter/tree/main/example) for an example of how to subclass and use `MicropubAdapter`.

You’ll need some way of verifying the access tokens used to authenticate micropub requests — indeed, `verifyAccessTokenCallback()` is the only method which you’re absolutely required to implement! If your app doesn’t yet have [IndieAuth](https://indieweb.org/IndieAuth) endpoints capable of creating and verifying access tokens, you may want to use the companion library [taproot/indieauth](https://github.com/taproot/indieauth/) to add indieauth support to your app. The example app uses taproot/indieauth, so you can refer to that for an example of how to use the two libraries together.

## Contributing

If you have any questions about using this library, join the [indieweb chatroom](https://indieweb.org/discuss) and ping `barnaby`.

If you find a bug or problem with the library, or want to suggest a feature, please create an issue.

If discussions lead to you wanting to submit a pull request, following this process will increase the chances of it being accepted without a lengthy review process:

* Fork this repo to your own github account, and clone it to your development computer
* Run `./run_coverage.sh` and ensure that all tests pass
* If applicable, write failing regression tests e.g. for a bug you’re fixing
* Make your changes
* Run `./run_coverage.sh` and `open docs/coverage/index.html`. Make sure that the changes you made are covered by tests. taproot/micropub-adapter had 100% test coverage from version 0.1.0, and that number should never go down!
* Run `./vendor/bin/psalm` and and fix any warnings it brings up.
* Install and run `./phpDocumentor.phar` to regenerate the documentation if applicable.
* Push your changes and submit the PR

## Changelog

* v0.1.0 coming soon!
