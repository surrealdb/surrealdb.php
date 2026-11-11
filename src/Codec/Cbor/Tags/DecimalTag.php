<?php

namespace SurrealDB\SDK\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use Beau\CborPHP\interfaces\CborTag;
use SurrealDB\SDK\Codec\Cbor\CborTag as Tag;
use SurrealDB\SDK\Exceptions\SerializationException;
use SurrealDB\SDK\Types\Decimal;
use function strlen;

/**
 * A decimal: tag 10 with the number as text.
 */
final class DecimalTag implements CborTag
{
	/**
	 * SurrealDB decimals hold at most 28 digits after the point.
	 */
	private const int SCALE = 28;

	/**
	 * The most digits a decoded decimal may have. SurrealDB decimals have at
	 * most 29 significant digits; this leaves room to spare, and keeps
	 * brick/math from building a huge number out of hostile text.
	 */
	private const int MAX_DIGITS = 64;

	public function tag(): int
	{
		return Tag::DECIMAL->value;
	}

	public function class(): string
	{
		return Decimal::class;
	}

	/**
	 * The decimal's text with any zeros past {@see self::SCALE} digits after
	 * the point dropped, which leaves the number the same:
	 * 0.000000000000000000000000000000 is sent with 28 zeros. No other digit
	 * is dropped.
	 *
	 * @param Decimal $value
	 */
	public function encode(object $value, CborContext $context): string
	{
		$text = (string) $value;
		$point = strpos($text, ".");

		if ($point === false) {
			return $text;
		}

		$excess = strlen($text) - $point - 1 - self::SCALE;

		if ($excess <= 0) {
			return $text;
		}

		return substr($text, 0, -$excess) . rtrim(substr($text, -$excess), "0");
	}

	/**
	 * The text is checked before brick/math reads it: an exponent such as
	 * 1e999999999 would exhaust memory, which no caller can catch, and text
	 * brick/math refuses would escape as its own exception.
	 */
	public function decode(mixed $content, CborContext $context): Decimal
	{
		$text = TagContent::text($content);
		$valid = preg_match(
			'/^[+-]?([0-9]+)(?:\.([0-9]+))?(?:[eE][+-]?[0-9]{1,2})?$/D',
			$text,
			$matches,
		) === 1;

		if (!$valid || strlen($matches[1]) + strlen($matches[2] ?? "") > self::MAX_DIGITS) {
			throw new SerializationException(
				"Serialization",
				"Invalid decimal in a SurrealDB CBOR tag: " . TagContent::excerpt($text),
			);
		}

		return new Decimal($text);
	}
}
