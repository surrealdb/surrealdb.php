<?php

namespace Surreal\Core\Traits;

trait SurrealTrait
{
    private ?string $namespace = null;
    private ?string $database = null;
    private ?string $token = null;
    private ?string $scope = null;
    private array $params = [];

    /**
     * Set the current namespace
     * @param string|null $namespace
     * @return void
     */
    public function setNamespace(?string $namespace): void
    {
        $this->namespace = $namespace;
    }

    /**
     * Set the current database
     * @param string|null $database
     * @return void
     */
    public function setDatabase(?string $database): void
    {
        $this->database = $database;
    }

    /**
     * Returns the current set namespace
     * @return string|null
     */
    public function getNamespace(): ?string
    {
        return $this->namespace;
    }

    /**
     * Returns the current set database
     * @return string|null
     */
    public function getDatabase(): ?string
    {
        return $this->database;
    }

    /**
     * Set the auth token
     * @codeCoverageIgnore - Being used but false positive.
     * @param string|null $token
     * @return void
     */
    public function setToken(?string $token): void
    {
        $this->token = $token;
    }

    /**
     * Get the auth token
     * @return string|null
     */
    public function getToken(): ?string
    {
        return $this->token;
    }

    /**
     * Set the auth scope
     * @codeCoverageIgnore - Being used but false positive.
     * @param string|null $scope
     * @return void
     */
    public function setScope(?string $scope): void
    {
        $this->scope = $scope;
    }

    /**
     * Get the auth scope
     * @return string|null
     */
    public function getScope(): ?string
    {
        return $this->scope;
    }

    /**
     * Set a new parameter.
     * @param string $param
     * @param string $value
     * @return null
     */
    public function let(string $param, string $value): null
    {
        $this->params[$param] = $value;
        return null;
    }

    /**
     * Dismisses a previously set parameter
     * @param string $param
     * @return null
     */
    public function unset(string $param): null
    {
        unset($this->params[$param]);
        return null;
    }
}