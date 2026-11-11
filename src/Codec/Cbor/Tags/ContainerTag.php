<?php

namespace SurrealDB\SDK\Codec\Cbor\Tags;

use Beau\CborPHP\classes\CborContext;
use Beau\CborPHP\interfaces\CborTag;

/**
 * A tag whose content can hold any SurrealDB value, byte strings included:
 * a record ID, a range and its bounds, and a set.
 *
 * cbor.php reads the content of a tag handler with byte strings as plain
 * strings, so bytes inside it could not be told apart from text. The
 * deserializer does not register these handlers; its reviver reads them,
 * with the content decoded as everywhere else, and calls decode() without a
 * context. See {@see SurrealTags::reviver()}.
 */
interface ContainerTag extends CborTag
{
	public function decode(mixed $content, ?CborContext $context = null): mixed;
}
