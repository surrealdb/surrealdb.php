<?php

namespace Surreal\Core\Auth;

class SurrealAuth
{
    protected ?string $token = null;
    protected ?string $scope = null;

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
}