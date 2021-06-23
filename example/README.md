# Example Micropub Adapter Application

This folder contains an example application which uses taproot/micropub-adapter. It
serves both as reference for how to use the library, and also an app which can be used for the
https://micropub.rocks/ test suite.

It’s configured by a JSON file at `data/config.json`. See `data/config.sample.json` for an example.

Posts and indieauth tokens are stored in `data/`, so that folder should be writable by your web server
process.

The concrete Micropub Adapter subclass can be found at `src/ExampleMicropubAdapter.php`.

`web/index.php` sets up a simple web app using Slim, with IndieAuth endpoints, MicroPub
endpoints and an endpoint for viewing posts.