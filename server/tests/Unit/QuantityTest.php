<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LedgerService;
use PHPUnit\Framework\TestCase;

class QuantityTest extends TestCase
{
    public function test_parses_3_decimal_strings_to_thousandths(): void
    {
        $this->assertSame(2500, LedgerService::parseQty('2.500'));
        $this->assertSame(125, LedgerService::parseQty('0.125'));
        $this->assertSame(10000, LedgerService::parseQty('10.000'));
        $this->assertSame(0, LedgerService::parseQty('0.000'));
        $this->assertSame(1, LedgerService::parseQty('0.001'));
    }

    public function test_parses_negative_quantities_correctly(): void
    {
        $this->assertSame(-2500, LedgerService::parseQty('-2.500'));
        $this->assertSame(-125, LedgerService::parseQty('-0.125'));
        $this->assertSame(-1500, LedgerService::parseQty('-1.500'));
        $this->assertSame(0, LedgerService::parseQty('-0.000'));
    }

    public function test_parses_partial_decimals_and_integers(): void
    {
        $this->assertSame(2500, LedgerService::parseQty('2.5'));
        $this->assertSame(2500, LedgerService::parseQty('2.50'));
        $this->assertSame(2000, LedgerService::parseQty('2'));
        $this->assertSame(2500, LedgerService::parseQty(2500));
        $this->assertSame(-1500, LedgerService::parseQty(-1500));
    }

    public function test_formats_thousandths_to_3_decimal_canonical_strings(): void
    {
        $this->assertSame('2.500', LedgerService::formatQty(2500));
        $this->assertSame('0.125', LedgerService::formatQty(125));
        $this->assertSame('10.000', LedgerService::formatQty(10000));
        $this->assertSame('0.000', LedgerService::formatQty(0));
        $this->assertSame('-2.500', LedgerService::formatQty(-2500));
        $this->assertSame('-0.125', LedgerService::formatQty(-125));
        $this->assertSame('-1.500', LedgerService::formatQty(-1500));
    }

    public function test_integer_arithmetic_avoids_floating_point_drift(): void
    {
        // 1.125 + 0.875 = 2.000 (spices/grains in kg)
        $qty1 = LedgerService::parseQty('1.125');
        $qty2 = LedgerService::parseQty('0.875');
        $sum = $qty1 + $qty2;

        $this->assertSame(2000, $sum);
        $this->assertSame('2.000', LedgerService::formatQty($sum));

        // In standard floating point, 0.1 + 0.2 != 0.3
        // In thousandths integer math:
        $p1 = LedgerService::parseQty('0.100'); // 100
        $p2 = LedgerService::parseQty('0.200'); // 200
        $p3 = LedgerService::parseQty('0.300'); // 300
        $this->assertSame($p3, $p1 + $p2);

        // Subtraction (sale): 2.500 - 1.250 = 1.250
        $stock = LedgerService::parseQty('2.500');
        $sale = LedgerService::parseQty('1.250');
        $remaining = $stock - $sale;

        $this->assertSame(1250, $remaining);
        $this->assertSame('1.250', LedgerService::formatQty($remaining));
    }
}
