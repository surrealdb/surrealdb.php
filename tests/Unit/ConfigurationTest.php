<?php

use Surreal\Client;

test('configuring the client', function () {
	Client::configure($config = createClientConfig());

	expect(Client::getConfig())->toBe($config);
});
