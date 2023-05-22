<?php

namespace Surreal\Config;

use Spatie\Url\Url;

interface BaseConfigContract
{
	/**
	 * @return string|null
	 */
	public function getUrl(): ?string;

	/**
	 * @param string|null $url
	 *
	 * @return ConfigContract
	 */
	public function url(?string $url): BaseConfigContract;

	public function getApiUrl(): string;

	public function getWsUrl(): string;

}
