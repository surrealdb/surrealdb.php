<?php
/** @noinspection SqlResolve */
/** @noinspection SqlNoDataSourceInspection */

use Surreal\Client;
use Surreal\Responses\ApiQueryResponse;
use Tests\Fixtures\PersonModel;


test('running basic sql query', function () {
	Client::configure($config = createClientConfig(true));

	$result = Client::query('select * from person where name = $name', ['name' => 'Tobie']);

	expect($result)->toBeInstanceOf(ApiQueryResponse::class)
		->and($result->hasError())->toBeFalse()
		->and($result->hasResponses())->toBeTrue()
		->and($result->firstResult()->getStatus())->toBe('OK')
		->and($result->count())->toBeGreaterThanOrEqual(1);
});

test('running failing query', function () {
	Client::configure($config = createClientConfig(true));

	$response = Client::query("CREATE ....");

	expect($response)->toBeInstanceOf(ApiQueryResponse::class)
		->and($response->hasError())->toBeTrue();
});
