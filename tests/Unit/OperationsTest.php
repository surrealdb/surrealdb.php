<?php

use Surreal\Client;
use Surreal\Responses\ApiQueryResponse;
use Surreal\WebService\Data\SigninData;
use Surreal\WebService\Data\SignupData;
use Tests\Fixtures\PersonModel;

test('use', function () {
	Client::configure($config = createClientConfig(true));

	$response = Client::use($config->getNamespace(), $config->getDb());

	expect($response)->toBeInstanceOf(ApiQueryResponse::class)
		->and($response->hasError())->toBeFalse();
});

test('signup', function () {
	Client::configure($config = createClientConfig(true));

	$useResponse = Client::use($config->getNamespace(), $config->getDb());

	expect($useResponse)->toBeInstanceOf(ApiQueryResponse::class)
		->and($useResponse->hasError())->toBeFalse();

	$response = Client::signup(
		(new SignupData())
			->db($config->getDb())
			->ns($config->getNamespace())
			->scope('account')
			->set('email', 'user@user.test')
			->set('pass', 'test')
	);

	expect($response)->toBeString();
});

test('signin', function () {
	Client::configure($config = createClientConfig(true));

	$useResponse = Client::use($config->getNamespace(), $config->getDb());

	expect($useResponse)->toBeInstanceOf(ApiQueryResponse::class)
		->and($useResponse->hasError())->toBeFalse();

	$response = Client::signin(
		(new SigninData())
			->db($config->getDb())
			->ns($config->getNamespace())
			->scope('account')
			->set('email', 'user@user.test')
			->set('pass', 'test')
	);

	expect($response)->toBeString();
});

test('invalidate', function () {
	Client::configure($config = createClientConfig(true));

	$useResponse = Client::use($config->getNamespace(), $config->getDb());

	expect($useResponse)->toBeInstanceOf(ApiQueryResponse::class)
		->and($useResponse->hasError())->toBeFalse();

	Client::invalidate();
});

test('select', function () {
	Client::configure($config = createClientConfig(true));

	$useResponse = Client::use($config->getNamespace(), $config->getDb());
	expect($useResponse)->toBeInstanceOf(ApiQueryResponse::class)
		->and($useResponse->hasError())->toBeFalse();

	Client::signin(
		(new SigninData())
			->set('user', $config->getUsername())
			->set('pass', $config->getPassword())
	);

	$persons = Client::select('person', PersonModel::class);

	expect($persons)->toBeArray()
		->and($persons[0])->toBeInstanceOf(PersonModel::class);
});

test('create', function () {
	Client::configure($config = createClientConfig(true));

	$useResponse = Client::use($config->getNamespace(), $config->getDb());
	expect($useResponse)->toBeInstanceOf(ApiQueryResponse::class)
		->and($useResponse->hasError())->toBeFalse();

	Client::signin(
		(new SigninData())
			->set('user', $config->getUsername())
			->set('pass', $config->getPassword())
	);

	$person = Client::create('person', ['name' => 'Tobie'], PersonModel::class);

	expect($person)->toBeInstanceOf(PersonModel::class);
});


test('update', function () {
	Client::configure($config = createClientConfig(true));

	$useResponse = Client::use($config->getNamespace(), $config->getDb());
	expect($useResponse)->toBeInstanceOf(ApiQueryResponse::class)
		->and($useResponse->hasError())->toBeFalse();

	Client::signin(
		(new SigninData())
			->set('user', $config->getUsername())
			->set('pass', $config->getPassword())
	);

	Client::query('create person:Tobie SET name = "Tobie"');

	// Test updating single record
	$updatedTobie = Client::update('person:Tobie', ['name' => 'UpdatedTobie'], PersonModel::class);
	expect($updatedTobie)->toBeInstanceOf(PersonModel::class)
		->and($updatedTobie->name)->toBe('UpdatedTobie');

	// Test updating multiple records
	$updatedPersons = Client::update('person', ['name' => 'UpdatedPerson'], PersonModel::class);
	expect($updatedPersons)->toBeArray()
		->and($updatedPersons[0])->toBeInstanceOf(PersonModel::class)
		->and($updatedPersons[0]->name)->toBe('UpdatedPerson');

});
