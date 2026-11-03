<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Ledger\CurrentShopContext;
use App\Services\Ledger\LedgerService;
use App\Support\UuidV7;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoShopSeeder extends Seeder
{
    /**
     * Seeds an authentic Ethiopian retail shop with 30 realistic grocery products,
     * opening inventory ledger movements, and sample sales transactions.
     */
    public function run(LedgerService $ledger): void
    {
        // 1. Create Demo Shop
        $shop = Shop::firstOrCreate(
            ['name' => 'ሰላም ሱፐርማርኬት (Selam Retail)'],
            [
                'currency' => 'ETB',
                'language' => 'am',
                'low_stock_default_milli' => 5000, // 5 units default
                'timezone' => 'Africa/Addis_Ababa',
            ]
        );

        // 2. Create Owner User
        User::firstOrCreate(
            ['telegram_id' => 123456789],
            [
                'shop_id' => $shop->id,
                'role' => 'owner',
                'language' => 'am',
            ]
        );

        // Set context for shop-scoped models
        CurrentShopContext::set($shop);

        // 3. Define 30 Realistic Ethiopian Grocery Products
        // Prices in ETB (converted to integer cents * 100)
        // Opening stock in standard units (converted to integer thousandths * 1000)
        $items = [
            ['name' => 'Sugar / ስኳር (1kg)', 'sku' => 'SUG-01', 'unit' => 'kg', 'cost' => 95.00, 'sell' => 120.00, 'stock' => 50, 'low' => 10],
            ['name' => 'White Teff / ማኛ ጤፍ (1kg)', 'sku' => 'TEF-01', 'unit' => 'kg', 'cost' => 85.00, 'sell' => 110.00, 'stock' => 100, 'low' => 20],
            ['name' => 'Brown Teff / ሰርገኛ ጤፍ (1kg)', 'sku' => 'TEF-02', 'unit' => 'kg', 'cost' => 70.00, 'sell' => 90.00, 'stock' => 80, 'low' => 15],
            ['name' => 'Cooking Oil / ዘይት (3L)', 'sku' => 'OIL-03', 'unit' => 'bottle', 'cost' => 720.00, 'sell' => 850.00, 'stock' => 24, 'low' => 5],
            ['name' => 'Cooking Oil / ዘይት (5L)', 'sku' => 'OIL-05', 'unit' => 'bottle', 'cost' => 1200.00, 'sell' => 1400.00, 'stock' => 18, 'low' => 4],
            ['name' => 'Sidama Coffee / የሲዳማ ቡና (1kg)', 'sku' => 'COF-01', 'unit' => 'kg', 'cost' => 360.00, 'sell' => 450.00, 'stock' => 30, 'low' => 5],
            ['name' => 'Yirgacheffe Coffee / የይርጋጨፌ ቡና (1kg)', 'sku' => 'COF-02', 'unit' => 'kg', 'cost' => 440.00, 'sell' => 550.00, 'stock' => 25, 'low' => 5],
            ['name' => 'Berbere Spice / ንፁህ የደብረብርሃን በርበሬ (1kg)', 'sku' => 'SPC-01', 'unit' => 'kg', 'cost' => 300.00, 'sell' => 380.00, 'stock' => 20, 'low' => 4],
            ['name' => 'Shiro Powder / የሽሮ ዱቄት (1kg)', 'sku' => 'SPC-02', 'unit' => 'kg', 'cost' => 170.00, 'sell' => 220.00, 'stock' => 35, 'low' => 6],
            ['name' => 'Table Salt / የገበታ ጨው (1kg)', 'sku' => 'SLT-01', 'unit' => 'packet', 'cost' => 28.00, 'sell' => 40.00, 'stock' => 60, 'low' => 10],
            ['name' => 'B29 Laundry Soap / ቢ29 ሳሙና', 'sku' => 'SOP-01', 'unit' => 'bar', 'cost' => 35.00, 'sell' => 45.00, 'stock' => 120, 'low' => 20],
            ['name' => 'Lifebuoy Bath Soap / የመታጠቢያ ሳሙና', 'sku' => 'SOP-02', 'unit' => 'bar', 'cost' => 50.00, 'sell' => 65.00, 'stock' => 80, 'low' => 15],
            ['name' => 'Ariel Detergent / አሪየል ሳሙና (500g)', 'sku' => 'DET-01', 'unit' => 'packet', 'cost' => 145.00, 'sell' => 180.00, 'stock' => 40, 'low' => 8],
            ['name' => 'Dry Yeast / ካሚራ (100g)', 'sku' => 'YST-01', 'unit' => 'packet', 'cost' => 55.00, 'sell' => 75.00, 'stock' => 50, 'low' => 10],
            ['name' => 'Black Tea / ዋበር ጥቁር ሻይ (50 bags)', 'sku' => 'TEA-01', 'unit' => 'box', 'cost' => 98.00, 'sell' => 130.00, 'stock' => 30, 'low' => 5],
            ['name' => 'Shola Fresh Milk / የሾላ ወተት (500ml)', 'sku' => 'MLK-01', 'unit' => 'pouch', 'cost' => 48.00, 'sell' => 60.00, 'stock' => 40, 'low' => 8],
            ['name' => 'Farm Fresh Eggs / የሀገር እንቁላል (ደርዘን)', 'sku' => 'EGG-01', 'unit' => 'dozen', 'cost' => 144.00, 'sell' => 180.00, 'stock' => 25, 'low' => 5],
            ['name' => 'Red Lentils / ቀይ ምስር (1kg)', 'sku' => 'PUL-01', 'unit' => 'kg', 'cost' => 125.00, 'sell' => 160.00, 'stock' => 45, 'low' => 8],
            ['name' => 'Split Peas / የክክ ድፍን (1kg)', 'sku' => 'PUL-02', 'unit' => 'kg', 'cost' => 110.00, 'sell' => 140.00, 'stock' => 40, 'low' => 8],
            ['name' => 'Macaroni / ፓስታ ማካሮኒ (500g)', 'sku' => 'PAS-01', 'unit' => 'packet', 'cost' => 48.00, 'sell' => 65.00, 'stock' => 70, 'low' => 12],
            ['name' => 'Spaghetti / ስፓጌቲ (500g)', 'sku' => 'PAS-02', 'unit' => 'packet', 'cost' => 48.00, 'sell' => 65.00, 'stock' => 65, 'low' => 12],
            ['name' => 'Tomato Paste / ሳልሳ (400g)', 'sku' => 'TOM-01', 'unit' => 'can', 'cost' => 85.00, 'sell' => 110.00, 'stock' => 35, 'low' => 6],
            ['name' => 'Box Matches / ክብሪት (10 pcs)', 'sku' => 'MTC-01', 'unit' => 'pack', 'cost' => 22.00, 'sell' => 30.00, 'stock' => 50, 'low' => 10],
            ['name' => 'White Candles / ሻማ (6 pcs)', 'sku' => 'CND-01', 'unit' => 'pack', 'cost' => 68.00, 'sell' => 90.00, 'stock' => 40, 'low' => 6],
            ['name' => 'Tiger Razor Blades / ምላጭ (5 pcs)', 'sku' => 'BLD-01', 'unit' => 'pack', 'cost' => 24.00, 'sell' => 35.00, 'stock' => 60, 'low' => 10],
            ['name' => 'Bottled Water / አምቦ የታሸገ ውሃ (1L)', 'sku' => 'WTR-01', 'unit' => 'bottle', 'cost' => 25.00, 'sell' => 35.00, 'stock' => 90, 'low' => 15],
            ['name' => 'Baking Powder / የኬክ ዱቄት (100g)', 'sku' => 'BKP-01', 'unit' => 'can', 'cost' => 50.00, 'sell' => 70.00, 'stock' => 30, 'low' => 5],
            ['name' => 'Green Cardamom / ሄል (100g)', 'sku' => 'SPC-03', 'unit' => 'packet', 'cost' => 250.00, 'sell' => 320.00, 'stock' => 15, 'low' => 3],
            ['name' => 'Cloves / ቁንዶ በርበሬ (100g)', 'sku' => 'SPC-04', 'unit' => 'packet', 'cost' => 210.00, 'sell' => 280.00, 'stock' => 20, 'low' => 4],
            ['name' => 'Fresh Garlic / ነጭ ሽንኩርት (1kg)', 'sku' => 'VEG-01', 'unit' => 'kg', 'cost' => 200.00, 'sell' => 260.00, 'stock' => 25, 'low' => 5],
        ];

        DB::transaction(function () use ($shop, $ledger, $items): void {
            $createdProducts = [];

            foreach ($items as $data) {
                $costCents = (int) round($data['cost'] * 100);
                $sellCents = (int) round($data['sell'] * 100);
                $lowMilli = (int) ($data['low'] * 1000);

                /** @var Product $product */
                $product = Product::firstOrCreate(
                    ['shop_id' => $shop->id, 'sku' => $data['sku']],
                    [
                        'id' => UuidV7::generate(),
                        'name' => $data['name'],
                        'unit' => $data['unit'],
                        'cost_price_cents' => $costCents,
                        'sell_price_cents' => $sellCents,
                        'low_stock_default_milli' => $lowMilli,
                    ]
                );

                $createdProducts[] = $product;

                // Record opening stock via append-only ledger
                $existingMovements = DB::table('movements')
                    ->where('shop_id', $shop->id)
                    ->where('product_id', $product->id)
                    ->exists();

                if (!$existingMovements) {
                    $openingMilli = (int) ($data['stock'] * 1000);
                    $ledger->record(
                        movementId: UuidV7::generate(),
                        shopId: $shop->id,
                        productId: $product->id,
                        type: 'in',
                        qtyMilli: $openingMilli,
                        unitPriceCents: $costCents,
                        note: 'Opening stock (መክፈቻ ስቶክ)'
                    );
                }
            }

            // Record a couple of realistic recent sales transactions
            if (count($createdProducts) >= 3) {
                // Sale 1: Sugar (2 kg)
                $sugar = $createdProducts[0];
                $ledger->record(
                    movementId: UuidV7::generate(),
                    shopId: $shop->id,
                    productId: $sugar->id,
                    type: 'sale',
                    qtyMilli: -2000,
                    unitPriceCents: $sugar->sell_price_cents,
                    note: 'Customer walk-in sale'
                );

                // Sale 2: Cooking Oil 3L (1 bottle)
                $oil = $createdProducts[3];
                $ledger->record(
                    movementId: UuidV7::generate(),
                    shopId: $shop->id,
                    productId: $oil->id,
                    type: 'sale',
                    qtyMilli: -1000,
                    unitPriceCents: $oil->sell_price_cents,
                    note: 'Customer walk-in sale'
                );

                // Sale 3: Coffee (1 kg)
                $coffee = $createdProducts[5];
                $ledger->record(
                    movementId: UuidV7::generate(),
                    shopId: $shop->id,
                    productId: $coffee->id,
                    type: 'sale',
                    qtyMilli: -1000,
                    unitPriceCents: $coffee->sell_price_cents,
                    note: 'Customer walk-in sale'
                );
            }
        });
    }
}
