<?php

use Surreal\Client;
use Surreal\WebService\JsonRpcWebService;
use Surreal\WebService\WebService;

test('creating with web service', function () {
	$config = createClientConfig();

	Client::configure($config);

	$service = Client::getWebService();

	expect($service)->toBeInstanceOf(WebService::class)
		->and($service->shouldReconstructPerRequest())->toBeTrue();

	$serviceTwo = Client::getWebService();

	expect($serviceTwo)->toBeInstanceOf(WebService::class)
		->and($serviceTwo)->not()->toBe($service);
});

test('creating with json rpc web service', function () {
	$config = createClientConfig(true);

	Client::configure($config);

	$service = Client::getWebService();

	expect($service)->toBeInstanceOf(JsonRpcWebService::class)
		->and($service->shouldReconstructPerRequest())->toBeFalse();

	$serviceTwo = Client::getWebService();

	expect($serviceTwo)->toBeInstanceOf(JsonRpcWebService::class)
		->and($serviceTwo)->toBe($service);
});
