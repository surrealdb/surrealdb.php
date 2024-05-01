<?php

namespace Surreal\Curl;

enum HttpStatus: int
{
    case OK = 200;
    case UNAUTHORIZED = 401;
    case UNSUPPORTED_MEDIA_TYPE = 415;
    case INTERNAL_SERVER_ERROR = 500;
    case BAD_GATEWAY = 502;
}