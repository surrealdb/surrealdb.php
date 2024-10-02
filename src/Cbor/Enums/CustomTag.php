<?php

namespace Surreal\Cbor\Enums;

enum CustomTag: int
{
	// Tags from the spec
	case SPEC_DATETIME = 0;
	case SPEC_UUID = 37;

	// Custom Tags
	case NONE = 6;
	case TABLE = 7;
	case RECORD_ID = 8;
	case STRING_UUID = 9;
	case STRING_DECIMAL = 10;
	case BINARY_DECIMAL = 11;
	
	case CUSTOM_DATETIME = 12;
	case STRING_DURATION = 13;
	case CUSTOM_DURATION = 14;
    case FUTURE = 15;

    // Ranges
    case RANGE = 49;
    case BOUND_INCLUDED = 50;
    case BOUND_EXCLUDED = 51;

	// Custom Geometries
	case GEOMETRY_POINT = 88;
	case GEOMETRY_LINE = 89;
	case GEOMETRY_POLYGON = 90;
	case GEOMETRY_MULTIPOINT = 91;
	case GEOMETRY_MULTILINE = 92;
	case GEOMETRY_MULTIPOLYGON = 93;
	case GEOMETRY_COLLECTION = 94;
}