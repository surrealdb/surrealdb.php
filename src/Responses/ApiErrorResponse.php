<?php

namespace Surreal\Responses;

/**
 * Maps an api error of:
 * {"code":400,"details":"...","description":"...","information":"..."}
 * to this class
 */
class ApiErrorResponse
{
	public int    $code;
	public string $description;
	public string $details;
	public string $information;

	public function __construct(object $response)
	{
		$this->code        = $response->code;
		$this->description = $response->description;
		$this->details     = $response->details;
		$this->information = $response->information;
	}

}