<?php

namespace Surreal\Curl;

use Surreal\Core\AbstractSurreal;
use Surreal\Exceptions\SurrealException;

class HttpHeader
{
    const TYPE_CBOR = "application/cbor";
    const TYPE_TEXT = "text/plain";

    private AbstractSurreal $instance;

    private array $headers = [];

    private function __construct(AbstractSurreal $surreal)
    {
        $this->instance = $surreal;
    }

    public static function create(AbstractSurreal $surreal): self
    {
        return new self($surreal);
    }

    public function setContentTypeHeader(string $type): HttpHeader
    {
        $this->headers[] = "Content-Type: " . $type;
        return $this;
    }

    public function setAcceptHeader(string $accept): HttpHeader
    {
        $this->headers[] = "Accept: " . $accept;
        return $this;
    }

    public function setAuthorizationHeader(): HttpHeader
    {
        $token = $this->instance->auth->getToken();

        if ($token) {
            $this->headers[] = "Authorization: Bearer " . $token;
        }

        return $this;
    }

    /**
     * @throws SurrealException
     */
    public function setNamespaceHeader(bool $required, ?string $override = null): HttpHeader
    {
        $namespace = $override ?? $this->instance->getNamespace();

        if ($required && !$namespace) {
            throw new SurrealException("Namespace is required for this request");
        }

        if ($namespace) {
            $this->headers[] = "Surreal-NS: " . $namespace;
        }

        return $this;
    }

    /**
     * @throws SurrealException
     */
    public function setDatabaseHeader(bool $required, ?string $override = null): HttpHeader
    {
        $database = $override ?? $this->instance->getDatabase();

        if ($required && !$database) {
            throw new SurrealException("Database is required for this request");
        }

        if ($database) {
            $this->headers[] = "Surreal-DB: " . $database;
        }

        return $this;
    }

    /**
     * @throws SurrealException
     */
    public function setScopeHeader(bool $required, ?string $override = null): HttpHeader
    {
        $scope = $override ?? $this->instance->auth->getScope();

        if ($required && !$scope) {
            throw new SurrealException("Scope is required for this request");
        }

        if ($scope) {
            $this->headers[] = "Surreal-SC: " . $scope;
        }

        return $this;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }
}