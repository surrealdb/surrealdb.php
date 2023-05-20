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

test('mapping api query response to a dto', function () {
	$data     = json_decode('[{"time":"349.791\u00b5s","status":"OK","result":[{"id":"person:utpxestc6mjfed8rtaau","name":"Tobie"}]}]', false);
	$response = new ApiQueryResponse($data);

	expect($response->hasResponses())->toBeTrue();

	$result = $response->firstResult();

	expect($result->getStatus())->toBe('OK')
		->and($result->getTime())->toBe('349.791µs')
		->and($result->getResult())->toBeArray();

	$person = $result->first();

	expect($person->id)->toBe('person:utpxestc6mjfed8rtaau')
		->and($person->name)->toBe('Tobie');

});

test('mapping api failure response to dto', function () {
	$data     = json_decode('{"code":400,"details":"Request problems detected","description":"There is a problem with your request. Refer to the documentation for further information.","information":"There was a problem with the database: Parse error on line 1 at character 0 when parsing \'CREATE ....\'"}',
		false);
	$response = new ApiQueryResponse($data);

	expect($response->hasResponses())->toBeFalse()
		->and($response->hasError())->toBeTrue()
		->and($response->getErrorDetails())->toBe('Request problems detected')
		->and($response->getErrorDescription())->toBe('There is a problem with your request. Refer to the documentation for further information.')
		->and($response->getErrorInformation())->toBe('There was a problem with the database: Parse error on line 1 at character 0 when parsing \'CREATE ....\'');
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
