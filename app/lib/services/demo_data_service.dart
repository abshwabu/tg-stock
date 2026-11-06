import '../repositories/ledger_repository.dart';

class DemoDataService {
  final LedgerRepository repository;

  DemoDataService({required this.repository});

  static const String demoSettingKey = 'demo_products_loaded';
  static const List<String> demoProductIds = [
    '018d0000-0000-7000-8000-000000000001',
    '018d0000-0000-7000-8000-000000000002',
    '018d0000-0000-7000-8000-000000000003',
    '018d0000-0000-7000-8000-000000000004',
    '018d0000-0000-7000-8000-000000000005',
  ];

  Future<bool> isDemoProductsLoaded() async {
    final val = await repository.getSetting(demoSettingKey, defaultValue: 'false');
    return val == 'true';
  }

  /// Inserts representative Ethiopian shop items for immediate trial without onboarding friction
  Future<void> loadDemoProducts() async {
    final alreadyLoaded = await isDemoProductsLoaded();
    if (alreadyLoaded) return;

    // 1. Stockable item: White Teff
    await repository.createProduct(
      id: demoProductIds[0],
      name: 'ጤፍ (ማኛ) / White Teff',
      sku: 'TEFF-01',
      unit: 'kg',
      kind: 'stockable',
      costPriceCents: 9000,   // 90.00 ETB
      sellPriceCents: 11000,  // 110.00 ETB
      openingStockMilli: 50000, // 50 kg
      lowStockAtMilli: 10000,  // 10 kg
    );

    // 2. Stockable item: Sugar 1kg
    await repository.createProduct(
      id: demoProductIds[1],
      name: 'ስኳር / Sugar (1kg)',
      sku: 'SUGAR-01',
      unit: 'pcs',
      kind: 'stockable',
      costPriceCents: 11500,  // 115.00 ETB
      sellPriceCents: 13000,  // 130.00 ETB
      openingStockMilli: 100000, // 100 pcs
      lowStockAtMilli: 15000,  // 15 pcs
    );

    // 3. Stockable item: Sunflower Oil 3L
    await repository.createProduct(
      id: demoProductIds[2],
      name: 'የሱፍ ዘይት / Sunflower Oil 3L',
      sku: 'OIL-3L',
      unit: 'pcs',
      kind: 'stockable',
      costPriceCents: 58000,  // 580.00 ETB
      sellPriceCents: 65000,  // 650.00 ETB
      openingStockMilli: 30000,  // 30 pcs
      lowStockAtMilli: 5000,   // 5 pcs
    );

    // 4. Service item: Coffee Service (no stock)
    await repository.createProduct(
      id: demoProductIds[3],
      name: 'የቡና ቁርስ ማዘጋጀት / Coffee Service',
      sku: 'SRV-COFFEE',
      unit: 'service',
      kind: 'service',
      costPriceCents: 1500,   // 15.00 ETB
      sellPriceCents: 3500,   // 35.00 ETB
      askPrice: false,
    );

    // 5. Consumable item: Injera Pack (10 pcs)
    await repository.createProduct(
      id: demoProductIds[4],
      name: 'የእንጀራ ማሸጊያ / Injera Pack (10 pcs)',
      sku: 'CNS-INJERA',
      unit: 'pcs',
      kind: 'consumable',
      costPriceCents: 12000,  // 120.00 ETB
      sellPriceCents: 15000,  // 150.00 ETB
      materials: [
        {
          'product_id': demoProductIds[0], // Teff
          'qty_milli': 2000,               // uses 2 kg teff per pack
        }
      ],
    );

    await repository.setSetting(demoSettingKey, 'true');
  }

  /// Removes all demo products in one tap without affecting any real user products
  Future<void> removeDemoProducts() async {
    // Clear recipe links for demo products first so raw materials can be deleted
    for (final id in demoProductIds) {
      try {
        await repository.setProductMaterials(id, []);
      } catch (_) {}
    }

    // Delete in reverse dependency order (consumable first, then materials)
    for (final id in demoProductIds.reversed) {
      try {
        final prod = await repository.getProduct(id);
        if (prod != null && prod.deletedAtMs == null) {
          await repository.deleteProduct(id);
        }
      } catch (_) {}
    }
    await repository.setSetting(demoSettingKey, 'false');
  }
}
