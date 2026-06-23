<?php

namespace SurrealDB\SDK\Connection;

/** The lifecycle status of a connection. */
enum ConnectionStatus: string
{
    case Disconnected = 'disconnected';
    case Connecting = 'connecting';
    case Reconnecting = 'reconnecting';
    case Connected = 'connected';
}
