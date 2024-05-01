<?php

namespace Surreal\Cbor\Types;

use Beau\CborPHP\utils\CborByteString;
use DateTimeInterface;
use Ramsey\Uuid\UuidInterface;

class Uuid
{
    public static function fromString(string $uuid): UuidInterface
    {
        return \Ramsey\Uuid\Uuid::fromString($uuid);
    }

    public static function fromCborByteString(CborByteString $byteString): UuidInterface
    {
        return \Ramsey\Uuid\Uuid::fromBytes($byteString->getByteString());
    }

    public static function fromBytes(string $bytes): UuidInterface
    {
        return \Ramsey\Uuid\Uuid::fromBytes($bytes);
    }

    public static function v1(): UuidInterface
    {
        return \Ramsey\Uuid\Uuid::uuid1();
    }

    public static function v2(
        int     $localDomain,
        int     $localIdentifier = null,
        ?string $node = null,
        ?int    $clockSeq = null
    ): UuidInterface
    {
        return \Ramsey\Uuid\Uuid::uuid2($localDomain, $localIdentifier, $node, $clockSeq);
    }

    public static function v3(UuidInterface $ns, string $name): UuidInterface
    {
        return \Ramsey\Uuid\Uuid::uuid3($ns, $name);
    }

    public static function v4(): UuidInterface
    {
        return \Ramsey\Uuid\Uuid::uuid4();
    }

    public static function v5(UuidInterface $ns, string $name): UuidInterface
    {
        return \Ramsey\Uuid\Uuid::uuid5($ns, $name);
    }

    public static function v6(
        ?string $node = null,
        ?int    $clockSeq = null,
    ): UuidInterface
    {
        return \Ramsey\Uuid\Uuid::uuid6($node, $clockSeq);
    }

    public static function v7(?DateTimeInterface $dateTime = null): UuidInterface
    {
        return \Ramsey\Uuid\Uuid::uuid7($dateTime);
    }

    public static function v8(string $bytes): UuidInterface
    {
        return \Ramsey\Uuid\Uuid::uuid8($bytes);
    }
}