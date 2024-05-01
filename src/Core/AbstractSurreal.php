<?php

namespace Surreal\Core;

use Surreal\Core\Auth\SurrealAuth;

abstract class AbstractSurreal
{
    protected string $host;
    protected ?string $namespace = null;
    protected ?string $database = null;
    public SurrealAuth $auth;

    /**
     * @param string $host
     * @param array{namespace:string,database:string|null} $target
     * @param SurrealAuth|null $authorization
     */
    public function __construct(
        string        $host,
        array         $target = [],
        ?SurrealAuth $authorization = null
    )
    {
        $this->host = $host;
        $this->auth = $authorization ?? new SurrealAuth();

        $this->use($target);
    }

    /**
     * @param array{namespace:string|null,database:string|null} $target
     * @return null
     */
    public function use(array $target): null
    {
        $hasNamespace = array_key_exists("namespace", $target);
        $hasDatabase = array_key_exists("database", $target);

        if ($hasNamespace) {
            $this->setNamespace($target["namespace"]);
        }

        if ($hasDatabase) {
            $this->setDatabase($target["database"]);
        }

        return null;
    }

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
     * @param string|null $token
     */
    public function setToken(?string $token): void
    {
        $this->auth->setToken($token);
    }

    /**
     * Get the auth token
     * @return string|null
     */
    public function getToken(): ?string
    {
        return $this->auth->getToken();
    }

    /**
     * Set the auth scope
     * @param string|null $scope
     */
    public function setScope(?string $scope): void
    {
        $this->auth->setScope($scope);
    }

    /**
     * Get the auth scope
     * @return string|null
     */
    public function getScope(): ?string
    {
        return $this->auth->getScope();
    }
}