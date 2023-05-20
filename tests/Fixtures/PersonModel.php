<?php

namespace Tests\Fixtures;

use Surreal\Model\SurrealModel;

/**
 * @extends SurrealModel<PersonModel>
 */
class PersonModel extends SurrealModel
{
	public ?string $id   = null;
	public ?string $name = null;
}
