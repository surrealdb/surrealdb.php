<?php

use Surreal\Thing;

if (!function_exists('wrap')) {
	/**
	 * If the given value is not an array and not null, wrap it in one.
	 *
	 * @param mixed $value
	 *
	 * @return array
	 */
	function wrap($value)
	{
		if (is_null($value)) {
			return [];
		}

		return is_array($value) ? $value : [$value];
	}
}

if (!function_exists('thing')) {
	function thing($value): Thing
	{
		if ($value instanceof Thing) {
			return $value;
		}

		return new Thing($value);
	}
}
