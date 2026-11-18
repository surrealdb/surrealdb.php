<?php

namespace SurrealDB\Support;

use function count;
use function is_array;
use function strlen;

/** Minimal, signature-less JWT payload reader used for token expiry scheduling. */
final class Jwt
{
    /**
     * @return array<string,mixed>|null
     */
    public static function parse(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) < 2) {
            return null;
        }

        $payload = self::base64UrlDecode($parts[1]);

        if ($payload === null) {
            return null;
        }

        $data = json_decode($payload, true);

        return is_array($data) ? $data : null;
    }

    public static function expiry(string $token): ?int
    {
        $exp = self::parse($token)['exp'] ?? null;

        return is_numeric($exp) ? (int) $exp : null;
    }

    /**
     * Who a token signs in as: its namespace, database, access method and
     * user or record id claims. Renewing a token keeps them; signing in as
     * someone else changes them. Null for no token, or one that cannot be
     * read, so two of those count as the same (nobody known).
     *
     * @return array{NS: mixed, DB: mixed, AC: mixed, ID: mixed}|null
     */
    public static function identity(?string $token): ?array
    {
        $claims = $token === null ? null : self::parse($token);

        if ($claims === null) {
            return null;
        }

        return [
            'NS' => $claims['NS'] ?? $claims['ns'] ?? null,
            'DB' => $claims['DB'] ?? $claims['db'] ?? null,
            'AC' => $claims['AC'] ?? $claims['ac'] ?? null,
            'ID' => $claims['ID'] ?? $claims['id'] ?? null,
        ];
    }

    private static function base64UrlDecode(string $input): ?string
    {
        $remainder = strlen($input) % 4;

        if ($remainder !== 0) {
            $input .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($input, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
