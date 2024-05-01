<?php

namespace Surreal\Curl;

enum HttpContentType: string
{
    case JSON = "application/json";
    case CBOR = "application/cbor";
    case UTF8 = "text/plain; charset=utf-8";

    /**
     * There is an issue since surreal 1.4.0 where it returns the wrong content type.
     * So instead of application/cbor it returns application/surreal.
     * We can leave this here for now, but we should remove it in the future.
     */
    case SURREAL = "application/surrealdb";
}