<?php

namespace Surreal\Config;

use Spatie\Url\Url;

class BaseConfig implements BaseConfigContract
{

	private ?string $url = null;

	/**
	 * @return string|null
	 */
	public function getUrl(): ?string
	{
		return $this->url;
	}

	/**
	 * @param string|null $url
	 *
	 * @return BaseConfigContract
	 */
	public function url(?string $url): BaseConfigContract
	{
		$this->url = $url;

		return $this;
	}

	public function getApiUrl(): string
	{
		$url = Url::fromString($this->getUrl());

		return $url->withScheme($url->isSecure() ? 'https' : 'http')->__toString();
	}

	public function getWsUrl(): string
	{
		$url = Url::fromString($this->getUrl());

		return $url->withScheme($url->isSecure() ? 'wss' : 'ws')->__toString();
	}


}
