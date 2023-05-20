<?php

namespace Surreal;

use Spatie\Url\Url;

class WebService implements WebServiceContract
{

	protected ConfigContract $config;

	public function __construct(ConfigContract $config)
	{
		$this->config = $config;
	}

	public function buildRequestUrl(array $parameters = []): string
	{
		return Url::fromString($this->config->getUrl())
			->withPath('/sql')
			->withQueryParameters($parameters);
	}

	/**
	 * @param string $query
	 * @param array  $parameters
	 *
	 * @return mixed|object
	 */
	public function makeRequest(string $query, array $parameters = []):mixed
	{
		$curl = curl_init();

		curl_setopt_array($curl, [
			CURLOPT_URL            => $this->buildRequestUrl($parameters),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_POSTFIELDS     => $query,
			CURLOPT_HTTPHEADER     => [
				'Content-Type: application/json',
				'Accept: application/json',
				'NS: ' . $this->config->getNamespace(),
				'DB: ' . $this->config->getDb(),
				'Authorization: Basic ' . base64_encode($this->config->getUsername() . ':' . $this->config->getPassword()),
			],
		]);

		$response = curl_exec($curl);

		curl_close($curl);

		return json_decode($response, false);
	}

}
