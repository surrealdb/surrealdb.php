<?php

namespace SurrealDB\Tests\Unit\Types;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SurrealDB\Exceptions\InvalidValueException;
use SurrealDB\Types\Duration;

final class DurationTest extends TestCase
{
    public function testParsesCompositeDuration(): void
    {
        $duration = Duration::fromString('1h30m');

        $this->assertSame(5400, $duration->seconds);
        $this->assertSame('1h30m', (string) $duration);
    }

    public function testNormalizesOnFormat(): void
    {
        $this->assertSame('1h30m', (string) Duration::fromString('90m'));
    }

    public function testSubSecondUnits(): void
    {
        $duration = Duration::fromString('500ms');

        $this->assertSame(500_000_000, $duration->nanoseconds);
        $this->assertSame('500ms', (string) $duration);
    }

    public function testZeroDuration(): void
    {
        $this->assertSame('0ns', (string) new Duration());
    }

    public function testEscapeIsBareLiteral(): void
    {
        $this->assertSame('1h30m', Duration::fromString('1h30m')->escape());
    }

    public function testJsonSerializeUsesTaggedForm(): void
    {
        $this->assertSame(['$duration' => '1h30m'], Duration::fromString('1h30m')->jsonSerialize());
    }

    public function testFactoryHelpers(): void
    {
        $this->assertSame(5_000_000_000, Duration::seconds(5)->totalNanoseconds());
        $this->assertSame('2h', (string) Duration::hours(2));
        $this->assertSame('1w', (string) Duration::days(7));
    }

    public function testArithmetic(): void
    {
        $sum = Duration::seconds(1)->add(Duration::milliseconds(500));
        $this->assertSame('1s500ms', (string) $sum);

        $scaled = Duration::seconds(2)->mul(3);
        $this->assertSame(6, $scaled->seconds);

        $divided = Duration::seconds(10)->div(4);
        $this->assertSame('2s500ms', (string) $divided);
    }

    public function testCompactTuple(): void
    {
        $this->assertSame([1, 500_000_000], Duration::fromString('1s500ms')->toCompact());
    }

    public function testEquality(): void
    {
        $this->assertTrue(Duration::fromString('1h')->equals(Duration::minutes(60)));
        $this->assertFalse(Duration::fromString('1h')->equals(Duration::fromString('2h')));
    }

    /**
     * Past 2^63 nanoseconds, about 292 years, a count of nanoseconds no longer
     * fits in a PHP integer. SurrealDB keeps seconds and nanoseconds apart.
     */
    public function testParsesDurationsLongerThan292Years(): void
    {
        $duration = Duration::fromString('300y');

        $this->assertSame([9_460_800_000, 0], $duration->toCompact());
        $this->assertSame('300y', (string) $duration);
        $this->assertSame('600y', (string) Duration::fromString('600y'));
        $this->assertSame('300y1ns', (string) Duration::fromString('300y1ns'));
    }

    public function testFactoriesBuildDurationsLongerThan292Years(): void
    {
        $this->assertTrue(Duration::years(300)->equals(Duration::fromString('300y')));
        $this->assertTrue(Duration::weeks(1_000_000)->div(1000)->equals(Duration::weeks(1000)));
        $this->assertSame([9_223_372_036_854_775, 807_000_000], Duration::milliseconds(PHP_INT_MAX)->toCompact());
        $this->assertSame([9_223_372_036_854, 775_807_000], Duration::microseconds(PHP_INT_MAX)->toCompact());
    }

    public function testSubSecondUnitsCarryIntoTheSeconds(): void
    {
        $this->assertSame([1_000_000_000_000, 0], Duration::fromString('1000000000000000ms')->toCompact());
        $this->assertSame('31709y41w2d1h46m40s', (string) Duration::fromString('1000000000000000ms'));
        // More nanoseconds than a PHP integer holds, but only 317 years.
        $this->assertSame('317y5w17h46m40s', (string) Duration::fromString('10000000000000000000ns'));
        $this->assertSame('3s500ms', (string) Duration::fromString('1500ms2000000000ns'));
    }

    public function testTheLongestDurationAPhpIntegerHolds(): void
    {
        $text = '292471208677y27w6d15h30m7s999ms999us999ns';
        $duration = Duration::fromString($text);

        $this->assertSame([PHP_INT_MAX, 999_999_999], $duration->toCompact());
        $this->assertSame($text, (string) $duration);
        $this->assertTrue($duration->equals(new Duration(PHP_INT_MAX, 999_999_999)));
    }

    /**
     * @return iterable<string, array{\Closure(): mixed}>
     */
    public static function tooLong(): iterable
    {
        yield 'duration::max as text' => [static fn (): Duration => Duration::fromString('584942417355y3w5d7h15s999ms999us999ns')];
        yield 'a second past the most' => [static fn (): Duration => Duration::fromString('292471208677y27w6d15h30m7s1000ms')];
        yield 'an amount past a PHP integer' => [static fn (): Duration => Duration::fromString('99999999999999999999s')];
        yield 'years' => [static fn (): Duration => Duration::years(PHP_INT_MAX)];
        yield 'nanoseconds carried' => [static fn (): Duration => new Duration(PHP_INT_MAX, 1_000_000_000)];
        yield 'add' => [static fn (): Duration => Duration::seconds(PHP_INT_MAX)->add(Duration::seconds(1))];
        yield 'mul' => [static fn (): Duration => Duration::seconds(PHP_INT_MAX)->mul(2)];
    }

    /**
     * @param \Closure(): mixed $build
     */
    #[DataProvider('tooLong')]
    public function testDurationsPastAPhpIntegerOfSecondsThrow(\Closure $build): void
    {
        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('Duration is longer than 9223372036854775807 seconds');

        $build();
    }

    public function testArithmeticOnDurationsLongerThan292Years(): void
    {
        $long = Duration::years(300);

        $this->assertSame('300y1ns', (string) $long->add(Duration::nanoseconds(1)));
        $this->assertSame('299y52w23h59m59s999ms999us999ns', (string) $long->sub(Duration::nanoseconds(1)));
        $this->assertTrue($long->mul(3)->equals(Duration::years(900)));
        $this->assertSame([1_351_542_857, 142_857_142], $long->div(7)->toCompact());
        $this->assertTrue($long->div(300)->equals(Duration::years(1)));
        $this->assertFalse($long->equals($long->add(Duration::nanoseconds(1))));
    }

    public function testTotalNanosecondsWhileTheyFitAPhpInteger(): void
    {
        $this->assertSame(9_208_512_000_000_000_000, Duration::years(292)->totalNanoseconds());
        $this->assertSame(PHP_INT_MAX, (new Duration(9_223_372_036, 854_775_807))->totalNanoseconds());
    }

    public function testTotalNanosecondsPastAPhpIntegerThrowsAClearError(): void
    {
        $this->expectException(InvalidValueException::class);
        $this->expectExceptionMessage('Duration 300y has more nanoseconds than a PHP integer holds');

        Duration::years(300)->totalNanoseconds();
    }
}
