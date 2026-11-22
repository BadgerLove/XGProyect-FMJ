<?php

declare(strict_types=1);

namespace Tests\Unit\App\Services;

use App\Services\FormatService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Amounts in words for expedition finds: "11.8 million Crystal collected." (Dale, 22 Nov 2026) */
#[CoversClass(FormatService::class)]
class FormatServiceReadableNumberTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            'zero' => [0, '0'],
            'small' => [845120, '845.120'],
            'just under a million' => [999999, '999.999'],
            'one million' => [1000000, '1 million'],
            'rounds down' => [1049999, '1 million'],
            'dale\'s crystal' => [11841840, '11.8 million'],
            'float input' => [11841840.7, '11.8 million'],
            'top of the millions' => [999949999, '999.9 million'],
            'rounds up into billions' => [999999999, '1 billion'],
            'billions' => [2300000000, '2.3 billion'],
            'trillions' => [4000000000000, '4 trillion'],
            'past the last word' => [1500000000000000, '1500 trillion'],
            'negative' => [-2500000, '-2.5 million'],
        ];
    }

    #[DataProvider('amounts')]
    public function testReadableNumber(int | float $amount, string $expected): void
    {
        app()->setLocale('en');

        $this->assertSame($expected, (new FormatService())->readableNumber($amount));
    }

    public function testSpanishWords(): void
    {
        app()->setLocale('es');

        $this->assertSame('11.8 millones', (new FormatService())->readableNumber(11841840));

        app()->setLocale('en');
    }
}
