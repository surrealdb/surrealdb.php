<?php

namespace Surreal\WebService;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use JsonException;
use Spatie\Url\Url;
use Surreal\Config\ConfigContract;
use Surreal\Exceptions\FailedRpcResponseError;
use Surreal\Responses\ApiErrorResponse;
use Surreal\WebService\RpcMessages\QueryRpcMessage;
use Surreal\WebService\RpcMessages\RpcMessageContract;

class WebService extends BaseWebService implements WebServiceContract
{
	protected Url $baseUrl;

	public function __construct(ConfigContract $config)
	{
		parent::__construct($config);

		$this->baseUrl = Url::fromString($this->config->getApiUrl())->withPath('/sql');
	}

	public function prepareRequest(): WebServiceContract
	{
		return $this;
	}

	public function send(RpcMessageContract $message): Collection|ApiErrorResponse
	{
		if (!($message instanceof QueryRpcMessage)) {
			throw new \InvalidArgumentException('Only QueryRpcMessage is supported at the moment.');
		}

		$curl = curl_init();

		curl_setopt_array($curl, [
			CURLOPT_URL            => $this->baseUrl->withQueryParameters($message->getParams()[1] ?? []),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_POSTFIELDS     => $message->getParams()[0] ?? '',
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

		try {
			$serverMessage = json_decode($response, false, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw (new FailedRpcResponseError('The SurrealDB message is invalid.'))->setSentMessage($sentMessage);
		}

		// If the response from the server is an error, throw a failed response.
		if (isset($serverMessage->code) && isset($serverMessage->details)) {
			return new ApiErrorResponse($serverMessage);
		}

		return new Collection($serverMessage);
	}

	public function shouldReconstructPerRequest(): bool
	{
		return true;
	}
}
