<?php

namespace Surreal\Serialization;

interface SerializationFactoryContract extends SerializationContract
{
	public function createSerializer(): SerializationFactoryContract;
}
