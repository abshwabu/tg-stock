<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Context\CurrentShopContext;
use App\Models\Product;
use App\Models\Shop;
use App\Support\ImmediateTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SqlitePragmasTest extends TestCase
{
    use RefreshDatabase;

    public function test_sqlite_pragmas_are_configured_properly(): void
    {
        $connection = DB::connection();
        $this->assertSame('sqlite', $connection->getDriverName());

        $foreignKeys = DB::select('PRAGMA foreign_keys');
        $this->assertEquals(1, $foreignKeys[0]->foreign_keys, 'foreign_keys pragma must be ON (1)');

        $busyTimeout = DB::select('PRAGMA busy_timeout');
        $this->assertEquals(5000, $busyTimeout[0]->timeout, 'busy_timeout pragma must be 5000ms');

        $synchronous = DB::select('PRAGMA synchronous');
        // In SQLite: 0 = OFF, 1 = NORMAL, 2 = FULL, 3 = EXTRA
        $this->assertEquals(1, $synchronous[0]->synchronous, 'synchronous pragma must be NORMAL (1)');
    }

    public function test_foreign_key_constraints_are_enforced(): void
    {
        $this->expectException(QueryException::class);

        // Attempt to insert product referencing a non-existent shop UUID
        CurrentShopContext::runWith((string) Str::uuid7(), function (): void {
            Product::create([
                'name' => 'Foreign Key Failure',
                'unit' => 'pcs',
                'cost_price_cents' => 100,
                'sell_price_cents' => 200,
            ]);
        });
    }

    public function test_immediate_transaction_runs_and_commits(): void
    {
        $shop = Shop::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Immediate Test Shop',
            'currency' => 'ETB',
            'language' => 'en',
        ]);

        $created = ImmediateTransaction::run(function () use ($shop): Product {
            return CurrentShopContext::runWith($shop, function (): Product {
                return Product::create([
                    'name' => 'Immediate Item',
                    'unit' => 'pcs',
                    'cost_price_cents' => 500,
                    'sell_price_cents' => 800,
                ]);
            });
        });

        $this->assertNotNull($created->id);
        $this->assertDatabaseHas('products', ['id' => $created->id]);
    }

    public function test_immediate_transaction_rollbacks_on_exception(): void
    {
        $shop = Shop::create([
            'id' => (string) Str::uuid7(),
            'name' => 'Immediate Rollback Shop',
            'currency' => 'ETB',
            'language' => 'en',
        ]);

        $productId = (string) Str::uuid7();

        try {
            ImmediateTransaction::run(function () use ($shop, $productId): void {
                CurrentShopContext::runWith($shop, function () use ($productId): void {
                    Product::create([
                        'id' => $productId,
                        'name' => 'Will Be Rolled Back',
                        'unit' => 'pcs',
                        'cost_price_cents' => 500,
                        'sell_price_cents' => 800,
                    ]);
                });

                throw new \RuntimeException('Trigger rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Trigger rollback', $e->getMessage());
        }

        $this->assertDatabaseMissing('products', ['id' => $productId]);
    }
}
