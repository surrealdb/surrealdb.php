<?php

namespace Surreal;

use Spatie\Url\Url;

interface WebServiceContract
{

	public function buildRequestUrl(array $parameters = []): string;


	/**
	 * @param string $query
	 * @param array  $parameters
	 *
	 * @return mixed|object
	 */
	public function makeRequest(string $query, array $parameters = []): mixed;
}
