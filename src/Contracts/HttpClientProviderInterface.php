<?php

namespace SurrealDB\Contracts;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Supplies the PSR-18 client and PSR-17 factories an {@see HttpConnectionInterface}
 * needs to build and dispatch a request. Kept separate from the connection itself
 * so the resolution strategy (explicit instances, discovery, a pooled client, …)
 * can vary without touching how a single exchange is performed.
 */
interface HttpClientProviderInterface
{
    public function client(): ClientInterface;

    public function requestFactory(): RequestFactoryInterface;

    public function streamFactory(): StreamFactoryInterface;
}
