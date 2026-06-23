<?php

namespace SurrealDB\SDK\Support;

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
