<?php

namespace Surreal\Cbor\Types;

use DateTimeInterface;

final readonly class DateTime
{
    public \DateTime $time;
    
    public function __construct(\DateTime $time)
    {
        $this->time = $time;
    }

    public function __toString(): string
    {
        return $this->time->format(DateTimeInterface::ATOM);
    }

    public function getTimestamp(): int
    {
        return $this->time->getTimestamp();
    }

    public function getDateTime(): \DateTime
    {
        return $this->time;
    }

    public function getDateTimeImmutable(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromMutable($this->time);
    }

    public static function fromCborCustomDate(array $data): \DateTime
    {
        $date = new \DateTime();
        $date->setTimestamp($data[0]);
        $date->setTime(0, 0, 0, $data[1]);
        return $date;
    }
}