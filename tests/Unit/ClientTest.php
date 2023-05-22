<?php

use Surreal\Client;
use Surreal\Responses\ApiQueryResponse;
use Tests\Fixtures\PersonModel;

test('running basic sql query', function () {
	Client::configure($config = createClientConfig());

	$response = Client::query("CREATE person SET name = 'Tobie'");

	expect($response)->toBeInstanceOf(ApiQueryResponse::class)
		->and($response->hasError())->toBeFalse()
		->and($response->hasResponses())->toBeTrue()
		->and($response->firstResult()->getStatus())->toBe('OK');
});

test('running failing query', function () {
	Client::configure($config = createClientConfig());

	$response = Client::query("CREATE ....");

	expect($response)->toBeInstanceOf(ApiQueryResponse::class)
		->and($response->hasError())->toBeTrue();
});

test('query with serialization & model mapping', function () {
	Client::configure($config = createClientConfig());

	Client::query("CREATE person:tobie SET name = 'Tobie'");

	$response = Client::queryModel(PersonModel::class, "select * from person:tobie;");

	expect($response)->toBeInstanceOf(ApiQueryResponse::class)
		->and($response->hasError())->toBeFalse()
		->and($response->hasResponses())->toBeTrue()
		->and($response->firstResult()->getStatus())->toBe('OK');


	$model = $response->firstResult()->first();

	expect($model)->toBeInstanceOf(PersonModel::class)
		->and($model->id)->toBe('person:tobie')
		->and($model->name)->toBe('Tobie');
});
