<?php

namespace Surreal\WebService\Data;


class SignupData
{
	protected array $extraAttributes = [];

	public ?string $ns = null;

	public ?string $db = null;

	public ?string $scope = null;

	public function __construct(?array $data = null) {
		if($data !== null) {
			foreach ($data as $key => $value) {
				if(property_exists($this, $key)) {
					$this->{$key} = $value;
				}
				else {
					$this->extraAttributes[$key] = $value;
				}
			}
		}
	}

	public function __set(string $name, $value): void
	{
		$this->extraAttributes[$name] = $value;
	}

	public function __get(string $name)
	{
		return $this->extraAttributes[$name] ?? null;
	}

	public function __isset(string $name): bool
	{
		return isset($this->extraAttributes[$name]) || (property_exists($this, $name) && $this->{$name} !== null);
	}

	public function __unset(string $name): void
	{
		unset($this->extraAttributes[$name]);
	}

	public function toArray(): array
	{
		$data = [];
		if($this->ns !== null) {
			$data['NS'] = $this->ns;
		}
		if($this->db !== null) {
			$data['DB'] = $this->db;
		}
		if($this->scope !== null) {
			$data['SC'] = $this->scope;
		}

		return array_merge($data, $this->extraAttributes);
	}

	/**
	 * @return string|null
	 */
	public function getNs(): ?string
	{
		return $this->ns;
	}

	/**
	 * @param string|null $ns
	 *
	 * @return SignupData
	 */
	public function ns(?string $ns): SignupData
	{
		$this->ns = $ns;

		return $this;
	}

	/**
	 * @return string|null
	 */
	public function getDb(): ?string
	{
		return $this->db;
	}

	/**
	 * @param string|null $db
	 *
	 * @return SignupData
	 */
	public function db(?string $db): SignupData
	{
		$this->db = $db;

		return $this;
	}

	/**
	 * @return string|null
	 */
	public function getScope(): ?string
	{
		return $this->scope;
	}

	/**
	 * @param string|null $scope
	 *
	 * @return SignupData
	 */
	public function scope(?string $scope): SignupData
	{
		$this->scope = $scope;

		return $this;
	}

	public function set(string $key, mixed $value):SignupData
	{
		$this->extraAttributes[$key] = $value;

		return $this;
	}


}
