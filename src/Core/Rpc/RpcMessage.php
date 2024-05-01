<?php


namespace Surreal\Core\Rpc;

use Beau\CborPHP\exceptions\CborException;
use Surreal\Cbor\CBOR;

class RpcMessage
{
    public int $id;
    public string $method;
    public array $params = [];

    public function __construct(string $method)
    {
        $this->method = $method;
    }

    /**
     * Creates a new RpcMessage from an associative array
     * @param string $method
     * @return RpcMessage
     */
    public static function create(string $method): RpcMessage
    {
        return new RpcMessage($method);
    }

    public function setId(int $id): RpcMessage
    {
        $this->id = $id;
        return $this;
    }

    public function setParams(array $params): RpcMessage
    {
        $this->params = $params;
        return $this;
    }

    /**
     * Returns the message as an associative array
     * @return array
     */
    public function toAssoc(): array
    {
        return [
            "id" => $this->id,
            "method" => $this->method,
            "params" => $this->params
        ];
    }

    /**
     * @throws CborException
     */
    public function toCborString(): string
    {
        return CBOR::encode($this->toAssoc());
    }
}
