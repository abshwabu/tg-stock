import 'dart:convert';
import 'dart:io';

import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

import '../utils/quantity_formatter.dart';
import '../utils/uuid_generator.dart';

part 'app_database.g.dart';

const defaultStarterCatalogBanks = [
  {
    'key': 'bank.cbe',
    'name': 'Commercial Bank of Ethiopia',
    'name_am': 'የኢትዮጵያ ንግድ ባንክ',
  },
  {'key': 'bank.awash', 'name': 'Awash Bank', 'name_am': 'አዋሽ ባንክ'},
  {'key': 'bank.dashen', 'name': 'Dashen Bank', 'name_am': 'ዳሸን ባንክ'},
  {
    'key': 'bank.abyssinia',
    'name': 'Bank of Abyssinia',
    'name_am': 'አቢሲኒያ ባንክ',
  },
  {'key': 'bank.wegagen', 'name': 'Wegagen Bank', 'name_am': 'ወጋገን ባንክ'},
  {'key': 'bank.hibret', 'name': 'Hibret Bank', 'name_am': 'ኅብረት ባንክ'},
  {
    'key': 'bank.nib',
    'name': 'Nib International Bank',
    'name_am': 'ንብ ኢንተርናሽናል ባንክ',
  },
  {
    'key': 'bank.coop',
    'name': 'Cooperative Bank of Oromia',
    'name_am': 'የኦሮሚያ ኅብረት ሥራ ባንክ',
  },
  {'key': 'bank.oromia', 'name': 'Oromia Bank', 'name_am': 'ኦሮሚያ ባንክ'},
  {
    'key': 'bank.lion',
    'name': 'Lion International Bank',
    'name_am': 'አንበሳ ኢንተርናሽናል ባንክ',
  },
  {'key': 'bank.zemen', 'name': 'Zemen Bank', 'name_am': 'ዘመን ባንክ'},
  {
    'key': 'bank.bunna',
    'name': 'Bunna International Bank',
    'name_am': 'ቡና ኢንተርናሽናል ባንክ',
  },
  {'key': 'bank.berhan', 'name': 'Berhan Bank', 'name_am': 'ብርሃን ባንክ'},
  {'key': 'bank.abay', 'name': 'Abay Bank', 'name_am': 'ዓባይ ባንክ'},
  {'key': 'bank.enat', 'name': 'Enat Bank', 'name_am': 'እናት ባንክ'},
  {'key': 'bank.amhara', 'name': 'Amhara Bank', 'name_am': 'አማራ ባንክ'},
  {'key': 'bank.ahadu', 'name': 'Ahadu Bank', 'name_am': 'አሃዱ ባንክ'},
  {'key': 'bank.siinqee', 'name': 'Siinqee Bank', 'name_am': 'ሲንቄ ባንክ'},
  {'key': 'bank.tsehay', 'name': 'Tsehay Bank', 'name_am': 'ፀሐይ ባንክ'},
  {
    'key': 'bank.global',
    'name': 'Global Bank Ethiopia',
    'name_am': 'ግሎባል ባንክ ኢትዮጵያ',
  },
  {'key': 'bank.gadaa', 'name': 'Gadaa Bank', 'name_am': 'ገዳ ባንክ'},
  {'key': 'bank.hijra', 'name': 'Hijra Bank', 'name_am': 'ሂጅራ ባንክ'},
  {'key': 'bank.zamzam', 'name': 'ZamZam Bank', 'name_am': 'ዘምዘም ባንክ'},
  {
    'key': 'bank.addis',
    'name': 'Addis International Bank',
    'name_am': 'አዲስ ኢንተርናሽናል ባንክ',
  },
  {'key': 'bank.debub', 'name': 'Debub Global Bank', 'name_am': 'ደቡብ ግሎባል ባንክ'},
  {'key': 'bank.shabelle', 'name': 'Shabelle Bank', 'name_am': 'ሻበሌ ባንክ'},
];

const defaultStarterCatalogWallets = [
  {'key': 'wallet.telebirr', 'name': 'Telebirr', 'name_am': 'ቴሌብር'},
  {'key': 'wallet.mpesa', 'name': 'M-Pesa', 'name_am': 'ኤም-ፔሳ'},
  {'key': 'wallet.cbebirr', 'name': 'CBE Birr', 'name_am': 'ሲቢኢ ብር'},
  {'key': 'wallet.hellocash', 'name': 'HelloCash', 'name_am': 'ሄሎካሽ'},
  {'key': 'wallet.amole', 'name': 'Amole', 'name_am': 'አሞሌ'},
  {'key': 'wallet.kacha', 'name': 'Kacha', 'name_am': 'ካቻ'},
  {'key': 'wallet.awashbirr', 'name': 'Awash Birr', 'name_am': 'አዋሽ ብር'},
];

class Products extends Table {
  TextColumn get id => text()(); // UUIDv7
  TextColumn get name => text()();
  TextColumn get sku => text().nullable()();
  TextColumn get unit => text().withDefault(const Constant('pcs'))();
  TextColumn get kind => text().withDefault(
    const Constant('stockable'),
  )(); // 'stockable', 'service', 'consumable'
  IntColumn get costPriceCents => integer().withDefault(const Constant(0))();
  IntColumn get sellPriceCents => integer().withDefault(const Constant(0))();
  BoolColumn get askPrice => boolean().withDefault(const Constant(false))();
  IntColumn get lowStockAtMilli => integer().nullable()();
  BoolColumn get isActive => boolean().withDefault(const Constant(true))();
  IntColumn get kindChangedAtMs => integer().nullable()();
  IntColumn get updatedAtMs => integer()();
  IntColumn get deletedAtMs => integer().nullable()();

  @override
  Set<Column> get primaryKey => {id};
}

class ProductMaterials extends Table {
  TextColumn get productId => text()();
  TextColumn get materialProductId => text()();
  IntColumn get qtyMilli =>
      integer()(); // quantity of raw material used per ONE unit sold

  @override
  Set<Column> get primaryKey => {productId, materialProductId};
}

class Movements extends Table {
  TextColumn get id => text()(); // UUIDv7
  TextColumn get productId => text()();
  TextColumn get saleId => text().nullable()();
  TextColumn get locationId => text().nullable()();
  TextColumn get transferId => text().nullable()();
  TextColumn get sourceMovementId => text().nullable()();
  TextColumn get type =>
      text()(); // 'in', 'sale', 'adjust', 'transfer_out', 'transfer_in', 'consume'
  IntColumn get qtyMilli =>
      integer()(); // signed integer thousandths (e.g. 5000 = 5.000, -2500 = -2.500)
  IntColumn get unitPriceCents => integer().withDefault(const Constant(0))();
  BoolColumn get tracksStock => boolean().withDefault(const Constant(true))();
  TextColumn get note => text().nullable()();
  IntColumn get createdAtMs => integer()();

  @override
  Set<Column> get primaryKey => {id};
}

class Locations extends Table {
  TextColumn get id => text()(); // UUIDv7
  TextColumn get name => text()();
  TextColumn get kind => text().withDefault(
    const Constant('store'),
  )(); // 'store', 'warehouse', 'other'
  BoolColumn get isDefault => boolean().withDefault(const Constant(false))();
  BoolColumn get isActive => boolean().withDefault(const Constant(true))();
  IntColumn get updatedAtMs => integer()();

  @override
  Set<Column> get primaryKey => {id};
}

class Sales extends Table {
  TextColumn get id => text()(); // UUIDv7
  TextColumn get locationId => text().nullable()();
  TextColumn get customerId => text().nullable()();
  IntColumn get totalCents => integer()();
  TextColumn get dueDate => text().nullable()(); // 'YYYY-MM-DD'
  TextColumn get note => text().nullable()();
  BoolColumn get legacy => boolean().withDefault(const Constant(false))();
  IntColumn get voidedAtMs => integer().nullable()();
  IntColumn get createdAtMs => integer()();
  IntColumn get updatedAtMs => integer()();

  @override
  Set<Column> get primaryKey => {id};
}

class Payments extends Table {
  TextColumn get id => text()(); // UUIDv7
  TextColumn get saleId => text().nullable()();
  TextColumn get customerId => text().nullable()();
  TextColumn get locationId => text().nullable()();
  TextColumn get method => text()(); // 'cash', 'bank', 'wallet'
  TextColumn get providerKey => text().nullable()();
  IntColumn get amountCents => integer()(); // Signed: negative = reversal
  TextColumn get reference => text().nullable()();
  IntColumn get receivedAtMs => integer()();
  IntColumn get createdAtMs => integer()();

  @override
  Set<Column> get primaryKey => {id};
}

class Customers extends Table {
  TextColumn get id => text()(); // UUIDv7
  TextColumn get name => text()();
  TextColumn get phone => text().nullable()();
  TextColumn get note => text().nullable()();
  IntColumn get createdAtMs => integer()();
  IntColumn get updatedAtMs => integer()();
  IntColumn get deletedAtMs => integer().nullable()();

  @override
  Set<Column> get primaryKey => {id};
}

class PaymentProviders extends Table {
  TextColumn get providerKey =>
      text()(); // 'bank.cbe', 'wallet.telebirr', 'custom.<uuid>'
  TextColumn get kind => text()(); // 'bank', 'wallet'
  TextColumn get name => text().nullable()(); // Custom name
  BoolColumn get enabled => boolean().withDefault(const Constant(true))();
  BoolColumn get favorite => boolean().withDefault(const Constant(false))();
  IntColumn get updatedAtMs => integer()();

  @override
  Set<Column> get primaryKey => {providerKey};
}

class OutboxEntries extends Table {
  TextColumn get id => text()(); // Client UUIDv7
  TextColumn get entity =>
      text()(); // 'product', 'movement', 'sale', 'payment', 'customer', 'payment_provider'
  TextColumn get entityId => text()();
  TextColumn get op => text()(); // 'upsert', 'delete'
  TextColumn get payloadJson => text()();
  IntColumn get clientTs => integer()();
  TextColumn get status => text().withDefault(
    const Constant('pending'),
  )(); // 'pending', 'synced', 'failed'
  IntColumn get retryCount => integer().withDefault(const Constant(0))();
  TextColumn get errorReason => text().nullable()();
  IntColumn get createdAt => integer()();

  @override
  Set<Column> get primaryKey => {id};
}

class SyncMetaEntries extends Table {
  TextColumn get key => text()();
  TextColumn get value => text()();

  @override
  Set<Column> get primaryKey => {key};
}

class AppSettings extends Table {
  TextColumn get key => text()();
  TextColumn get value => text()();

  @override
  Set<Column> get primaryKey => {key};
}

@DriftDatabase(
  tables: [
    Products,
    ProductMaterials,
    Movements,
    OutboxEntries,
    SyncMetaEntries,
    AppSettings,
    Locations,
    Sales,
    Payments,
    Customers,
    PaymentProviders,
  ],
)
class AppDatabase extends _$AppDatabase {
  AppDatabase([QueryExecutor? e]) : super(e ?? _openConnection());

  AppDatabase.inMemory() : super(NativeDatabase.memory());

  @override
  int get schemaVersion => 4;

  @override
  MigrationStrategy get migration => MigrationStrategy(
    onCreate: (m) async {
      await m.createAll();
      await _createIndexes();
      await _seedDefaultLocationIfEmpty();
      await _seedStarterPaymentProviders();
      await _seedDefaultPaymentSettings();
      await _seedDefaultSetting('block_sale_when_material_short', 'false');
      await backfillLegacySales();
    },
    onUpgrade: (m, from, to) async {
      if (from < 2) {
        // 1. Create locations table
        await m.createTable(locations);

        // 2. Add columns to movements
        await m.addColumn(movements, movements.locationId);
        await m.addColumn(movements, movements.transferId);

        // 3. Create default location ("Main")
        final mainId = UuidGenerator.v7();
        final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;
        await into(locations).insert(
          LocationsCompanion.insert(
            id: mainId,
            name: 'Main',
            kind: const Value('store'),
            isDefault: const Value(true),
            isActive: const Value(true),
            updatedAtMs: nowMs,
          ),
          mode: InsertMode.insertOrIgnore,
        );

        // 4. Backfill every existing movement to default location ("Main")
        await customStatement(
          'UPDATE movements SET location_id = ? WHERE location_id IS NULL;',
          [mainId],
        );
      }

      if (from < 3) {
        // 1. Create tables sales, payments, customers, payment_providers
        await m.createTable(sales);
        await m.createTable(payments);
        await m.createTable(customers);
        await m.createTable(paymentProviders);

        // 2. Add sale_id column to movements
        await m.addColumn(movements, movements.saleId);

        // 3. Seed starter payment providers and settings
        await _seedStarterPaymentProviders();
        await _seedDefaultPaymentSettings();
      }

      if (from < 4) {
        // 1. Add columns to products
        await m.addColumn(products, products.kind);
        await m.addColumn(products, products.askPrice);
        await m.addColumn(products, products.kindChangedAtMs);

        // 2. Add columns to movements
        await m.addColumn(movements, movements.tracksStock);
        await m.addColumn(movements, movements.sourceMovementId);

        // 3. Create product_materials table
        await m.createTable(productMaterials);

        // 4. Seed default setting for block_sale_when_material_short
        await _seedDefaultSetting('block_sale_when_material_short', 'false');
      }

      if (from < 3) {
        // Backfill legacy sales after all movement columns are ready
        await backfillLegacySales();
      }

      await _createIndexes();
    },
    beforeOpen: (details) async {
      await customStatement('PRAGMA foreign_keys = ON;');
      await _createIndexes();
    },
  );

  Future<void> _seedDefaultSetting(String key, String value) async {
    await customStatement(
      'INSERT OR IGNORE INTO app_settings (key, value) VALUES (?, ?);',
      [key, value],
    );
  }

  Future<void> _createIndexes() async {
    await customStatement(
      'CREATE INDEX IF NOT EXISTS idx_movements_created_type ON movements(created_at_ms, type);',
    );
    await customStatement(
      'CREATE INDEX IF NOT EXISTS idx_movements_product ON movements(product_id);',
    );
    await customStatement(
      'CREATE INDEX IF NOT EXISTS idx_movements_location ON movements(location_id);',
    );
    await customStatement(
      'CREATE INDEX IF NOT EXISTS idx_movements_transfer ON movements(transfer_id);',
    );
    await customStatement(
      'CREATE INDEX IF NOT EXISTS idx_movements_sale ON movements(sale_id);',
    );
    await customStatement(
      'CREATE INDEX IF NOT EXISTS idx_movements_tracks_stock ON movements(tracks_stock);',
    );
    await customStatement(
      'CREATE INDEX IF NOT EXISTS idx_movements_source ON movements(source_movement_id);',
    );
    await customStatement(
      'CREATE INDEX IF NOT EXISTS idx_products_deleted ON products(deleted_at_ms);',
    );
    await customStatement(
      'CREATE INDEX IF NOT EXISTS idx_product_materials_product ON product_materials(product_id);',
    );
    await customStatement(
      'CREATE INDEX IF NOT EXISTS idx_product_materials_material ON product_materials(material_product_id);',
    );
    await customStatement(
      'CREATE INDEX IF NOT EXISTS idx_sales_customer ON sales(customer_id);',
    );
    await customStatement(
      'CREATE INDEX IF NOT EXISTS idx_sales_created ON sales(created_at_ms);',
    );
    await customStatement(
      'CREATE INDEX IF NOT EXISTS idx_payments_sale ON payments(sale_id);',
    );
    await customStatement(
      'CREATE INDEX IF NOT EXISTS idx_payments_customer ON payments(customer_id);',
    );
    await customStatement(
      'CREATE INDEX IF NOT EXISTS idx_payments_received ON payments(received_at_ms);',
    );
    await customStatement(
      'CREATE INDEX IF NOT EXISTS idx_customers_phone ON customers(phone);',
    );
  }

  Future<void> _seedDefaultLocationIfEmpty() async {
    final count = await (select(locations)..limit(1)).get();
    if (count.isEmpty) {
      final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;
      await into(locations).insert(
        LocationsCompanion.insert(
          id: UuidGenerator.v7(),
          name: 'Main',
          kind: const Value('store'),
          isDefault: const Value(true),
          isActive: const Value(true),
          updatedAtMs: nowMs,
        ),
        mode: InsertMode.insertOrIgnore,
      );
    }
  }

  Future<void> _seedStarterPaymentProviders() async {
    final existing = await (select(paymentProviders)..limit(1)).get();
    if (existing.isNotEmpty) return;

    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;
    for (final b in defaultStarterCatalogBanks) {
      final isFav = b['key'] == 'bank.cbe' || b['key'] == 'bank.awash';
      await into(paymentProviders).insert(
        PaymentProvidersCompanion.insert(
          providerKey: b['key']!,
          kind: 'bank',
          name: Value(b['name']),
          enabled: const Value(true),
          favorite: Value(isFav),
          updatedAtMs: nowMs,
        ),
        mode: InsertMode.insertOrIgnore,
      );
    }

    for (final w in defaultStarterCatalogWallets) {
      final isFav =
          w['key'] == 'wallet.telebirr' || w['key'] == 'wallet.cbebirr';
      await into(paymentProviders).insert(
        PaymentProvidersCompanion.insert(
          providerKey: w['key']!,
          kind: 'wallet',
          name: Value(w['name']),
          enabled: const Value(true),
          favorite: Value(isFav),
          updatedAtMs: nowMs,
        ),
        mode: InsertMode.insertOrIgnore,
      );
    }
  }

  Future<void> _seedDefaultPaymentSettings() async {
    final enabledMethods = await (select(
      appSettings,
    )..where((s) => s.key.equals('payment_methods_enabled'))).getSingleOrNull();
    if (enabledMethods == null) {
      await into(appSettings).insert(
        const AppSetting(
          key: 'payment_methods_enabled',
          value: '["cash","bank","wallet","dube"]',
        ),
        mode: InsertMode.insertOrIgnore,
      );
    }
    final dubeDays = await (select(
      appSettings,
    )..where((s) => s.key.equals('dube_default_days'))).getSingleOrNull();
    if (dubeDays == null) {
      await into(appSettings).insert(
        const AppSetting(key: 'dube_default_days', value: '14'),
        mode: InsertMode.insertOrIgnore,
      );
    }
    final reqRef =
        await (select(appSettings)
              ..where((s) => s.key.equals('require_payment_reference')))
            .getSingleOrNull();
    if (reqRef == null) {
      await into(appSettings).insert(
        const AppSetting(key: 'require_payment_reference', value: 'false'),
        mode: InsertMode.insertOrIgnore,
      );
    }
  }

  Future<void> backfillLegacySales() async {
    final legacyMovements =
        await (select(movements)
              ..where((m) => m.type.equals('sale') & m.saleId.isNull())
              ..orderBy([
                (m) => OrderingTerm(
                  expression: m.createdAtMs,
                  mode: OrderingMode.asc,
                ),
              ]))
            .get();

    if (legacyMovements.isEmpty) return;

    final groups = <String, List<Movement>>{};
    for (final mov in legacyMovements) {
      final loc = mov.locationId ?? 'unknown';
      final minute = mov.createdAtMs ~/ 60000;
      final key = '$loc:$minute';
      groups.putIfAbsent(key, () => []).add(mov);
    }

    for (final entry in groups.entries) {
      final groupMovs = entry.value;
      if (groupMovs.isEmpty) continue;

      final saleId = UuidGenerator.v7();
      final locId = groupMovs.first.locationId;
      final minCreatedAt = groupMovs.first.createdAtMs;
      final maxCreatedAt = groupMovs.last.createdAtMs;

      int groupTotalCents = 0;
      for (final m in groupMovs) {
        groupTotalCents += ((m.qtyMilli.abs() * m.unitPriceCents) / 1000)
            .round();
      }

      final sale = Sale(
        id: saleId,
        locationId: locId,
        customerId: null,
        totalCents: groupTotalCents,
        dueDate: null,
        note: 'Legacy sale',
        legacy: true,
        voidedAtMs: null,
        createdAtMs: minCreatedAt,
        updatedAtMs: maxCreatedAt,
      );

      await into(sales).insert(sale, mode: InsertMode.insertOrIgnore);

      final movIds = groupMovs.map((m) => m.id).toList();
      await (update(movements)..where((m) => m.id.isIn(movIds))).write(
        MovementsCompanion(saleId: Value(saleId)),
      );
    }
  }

  static Future<File> getDatabaseFile() async {
    final dbFolder = await getApplicationDocumentsDirectory();
    return File(p.join(dbFolder.path, 'inventory.sqlite'));
  }

  // --- Transactions & Mutations with Outbox Invariant ---

  /// Creates a new product and appends to Outbox in the same transaction.
  Future<Product> createProduct({
    String? id,
    required String name,
    String? sku,
    String unit = 'pcs',
    String kind = 'stockable',
    int costPriceCents = 0,
    int sellPriceCents = 0,
    bool askPrice = false,
    int? lowStockAtMilli,
    int? kindChangedAtMs,
    List<Map<String, dynamic>>? materials,
  }) async {
    final prodId = id ?? UuidGenerator.v7();
    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;

    return transaction(() async {
      final product = Product(
        id: prodId,
        name: name,
        sku: sku,
        unit: unit,
        kind: kind,
        costPriceCents: costPriceCents,
        sellPriceCents: sellPriceCents,
        askPrice: askPrice,
        kindChangedAtMs: kindChangedAtMs,
        lowStockAtMilli: lowStockAtMilli,
        isActive: true,
        updatedAtMs: nowMs,
        deletedAtMs: null,
      );

      await into(products).insert(product, mode: InsertMode.insertOrReplace);

      List<Map<String, dynamic>>? wireMaterials;
      if (materials != null && materials.isNotEmpty) {
        wireMaterials = [];
        for (final mat in materials) {
          final matProdId = mat['product_id'] as String;
          final qMilli = mat['qty_milli'] is int
              ? mat['qty_milli'] as int
              : QuantityFormatter.parseQty(mat['qty']?.toString() ?? '0');
          await into(productMaterials).insert(
            ProductMaterialsCompanion.insert(
              productId: prodId,
              materialProductId: matProdId,
              qtyMilli: qMilli,
            ),
            mode: InsertMode.insertOrReplace,
          );
          wireMaterials.add({
            'product_id': matProdId,
            'qty': QuantityFormatter.toWireQty(qMilli),
          });
        }
      }

      final payload = {
        'id': prodId,
        'name': name,
        'sku': sku,
        'unit': unit,
        'kind': kind,
        'cost_price_cents': costPriceCents,
        'sell_price_cents': sellPriceCents,
        'ask_price': askPrice,
        'materials': wireMaterials,
        'kind_changed_at': kindChangedAtMs,
        'low_stock_at': lowStockAtMilli != null
            ? QuantityFormatter.toWireQty(lowStockAtMilli)
            : null,
        'is_active': true,
        'updated_at': nowMs,
      };

      await into(outboxEntries).insert(
        OutboxEntriesCompanion.insert(
          id: UuidGenerator.v7(),
          entity: 'product',
          entityId: prodId,
          op: 'upsert',
          payloadJson: jsonEncode(payload),
          clientTs: nowMs,
          status: const Value('pending'),
          createdAt: nowMs,
        ),
      );

      return product;
    });
  }

  /// Updates an existing product and appends to Outbox in the same transaction.
  Future<void> updateProduct(
    Product product, {
    List<Map<String, dynamic>>? materials,
  }) async {
    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;
    final updated = product.copyWith(updatedAtMs: nowMs);

    await transaction(() async {
      await update(products).replace(updated);

      List<Map<String, dynamic>>? wireMaterials;
      if (materials != null) {
        await (delete(
          productMaterials,
        )..where((pm) => pm.productId.equals(updated.id))).go();
        wireMaterials = [];
        for (final mat in materials) {
          final matProdId = mat['product_id'] as String;
          final qMilli = mat['qty_milli'] is int
              ? mat['qty_milli'] as int
              : QuantityFormatter.parseQty(mat['qty']?.toString() ?? '0');
          await into(productMaterials).insert(
            ProductMaterialsCompanion.insert(
              productId: updated.id,
              materialProductId: matProdId,
              qtyMilli: qMilli,
            ),
            mode: InsertMode.insertOrReplace,
          );
          wireMaterials.add({
            'product_id': matProdId,
            'qty': QuantityFormatter.toWireQty(qMilli),
          });
        }
      } else {
        final existingMats = await (select(
          productMaterials,
        )..where((pm) => pm.productId.equals(updated.id))).get();
        if (existingMats.isNotEmpty) {
          wireMaterials = existingMats
              .map(
                (m) => {
                  'product_id': m.materialProductId,
                  'qty': QuantityFormatter.toWireQty(m.qtyMilli),
                },
              )
              .toList();
        }
      }

      final payload = {
        'id': updated.id,
        'name': updated.name,
        'sku': updated.sku,
        'unit': updated.unit,
        'kind': updated.kind,
        'cost_price_cents': updated.costPriceCents,
        'sell_price_cents': updated.sellPriceCents,
        'ask_price': updated.askPrice,
        'materials': wireMaterials,
        'kind_changed_at': updated.kindChangedAtMs,
        'low_stock_at': updated.lowStockAtMilli != null
            ? QuantityFormatter.toWireQty(updated.lowStockAtMilli!)
            : null,
        'is_active': updated.isActive,
        'updated_at': nowMs,
      };

      await into(outboxEntries).insert(
        OutboxEntriesCompanion.insert(
          id: UuidGenerator.v7(),
          entity: 'product',
          entityId: updated.id,
          op: 'upsert',
          payloadJson: jsonEncode(payload),
          clientTs: nowMs,
          status: const Value('pending'),
          createdAt: nowMs,
        ),
      );
    });
  }

  /// Soft deletes a product and appends a delete change to Outbox.
  Future<void> deleteProduct(String id) async {
    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;

    await transaction(() async {
      final existing = await (select(
        products,
      )..where((p) => p.id.equals(id))).getSingleOrNull();
      if (existing == null) return;

      final updated = existing.copyWith(
        deletedAtMs: Value(nowMs),
        isActive: false,
        updatedAtMs: nowMs,
      );

      await update(products).replace(updated);

      final existingMats = await (select(
        productMaterials,
      )..where((pm) => pm.productId.equals(id))).get();
      final wireMaterials = existingMats.isNotEmpty
          ? existingMats
                .map(
                  (m) => {
                    'product_id': m.materialProductId,
                    'qty': QuantityFormatter.toWireQty(m.qtyMilli),
                  },
                )
                .toList()
          : null;

      final payload = {
        'id': id,
        'name': existing.name,
        'sku': existing.sku,
        'unit': existing.unit,
        'kind': existing.kind,
        'cost_price_cents': existing.costPriceCents,
        'sell_price_cents': existing.sellPriceCents,
        'ask_price': existing.askPrice,
        'materials': wireMaterials,
        'kind_changed_at': existing.kindChangedAtMs,
        'low_stock_at': existing.lowStockAtMilli != null
            ? QuantityFormatter.toWireQty(existing.lowStockAtMilli!)
            : null,
        'is_active': false,
        'updated_at': nowMs,
      };

      await into(outboxEntries).insert(
        OutboxEntriesCompanion.insert(
          id: UuidGenerator.v7(),
          entity: 'product',
          entityId: id,
          op: 'delete',
          payloadJson: jsonEncode(payload),
          clientTs: nowMs,
          status: const Value('pending'),
          createdAt: nowMs,
        ),
      );
    });
  }

  /// Records an append-only inventory movement and writes to Outbox in the same transaction.
  Future<Movement> recordMovement({
    String? id,
    required String productId,
    required String
    type, // 'in', 'sale', 'adjust', 'transfer_out', 'transfer_in', 'consume'
    required int
    qtyMilli, // signed: positive for intake, negative for sale/consume
    int unitPriceCents = 0,
    bool tracksStock = true,
    String? note,
    String? locationId,
    String? transferId,
    String? saleId,
    String? sourceMovementId,
    int? createdAtMs,
  }) async {
    final movId = id ?? UuidGenerator.v7();
    final nowMs = createdAtMs ?? DateTime.now().toUtc().millisecondsSinceEpoch;

    return transaction(() async {
      String? resolvedLocId = locationId;
      if (resolvedLocId == null) {
        final defLoc =
            await (select(locations)
                  ..where(
                    (l) => l.isDefault.equals(true) & l.isActive.equals(true),
                  )
                  ..limit(1))
                .getSingleOrNull();
        resolvedLocId = defLoc?.id;
      }

      final movement = Movement(
        id: movId,
        productId: productId,
        type: type,
        qtyMilli: qtyMilli,
        unitPriceCents: unitPriceCents,
        tracksStock: tracksStock,
        note: note,
        createdAtMs: nowMs,
        locationId: resolvedLocId,
        transferId: transferId,
        saleId: saleId,
        sourceMovementId: sourceMovementId,
      );

      await into(movements).insert(movement, mode: InsertMode.insertOrIgnore);

      final payload = {
        'id': movId,
        'product_id': productId,
        'location_id': resolvedLocId,
        'transfer_id': transferId,
        'sale_id': saleId,
        'source_movement_id': sourceMovementId,
        'type': type,
        'tracks_stock': tracksStock,
        'qty': QuantityFormatter.toWireQty(qtyMilli),
        'unit_price_cents': unitPriceCents,
        'note': note,
        'created_at': nowMs,
      };

      await into(outboxEntries).insert(
        OutboxEntriesCompanion.insert(
          id: UuidGenerator.v7(),
          entity: 'movement',
          entityId: movId,
          op: 'upsert',
          payloadJson: jsonEncode(payload),
          clientTs: nowMs,
          status: const Value('pending'),
          createdAt: nowMs,
        ),
      );

      return movement;
    });
  }

  /// Creates a new location and writes to Outbox in the same transaction.
  Future<Location> createLocation({
    String? id,
    required String name,
    String kind = 'store',
    bool isDefault = false,
  }) async {
    final locId = id ?? UuidGenerator.v7();
    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;

    return transaction(() async {
      if (isDefault) {
        await (update(locations)..where((l) => l.isDefault.equals(true))).write(
          const LocationsCompanion(isDefault: Value(false)),
        );
      }

      final location = Location(
        id: locId,
        name: name,
        kind: kind,
        isDefault: isDefault,
        isActive: true,
        updatedAtMs: nowMs,
      );

      await into(locations).insert(location, mode: InsertMode.insertOrReplace);

      final payload = {
        'id': locId,
        'name': name,
        'kind': kind,
        'is_default': isDefault,
        'is_active': true,
        'updated_at': nowMs,
      };

      await into(outboxEntries).insert(
        OutboxEntriesCompanion.insert(
          id: UuidGenerator.v7(),
          entity: 'location',
          entityId: locId,
          op: 'upsert',
          payloadJson: jsonEncode(payload),
          clientTs: nowMs,
          status: const Value('pending'),
          createdAt: nowMs,
        ),
      );

      return location;
    });
  }

  /// Updates an existing location and writes to Outbox in the same transaction.
  Future<void> updateLocation(Location location) async {
    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;
    final updated = location.copyWith(updatedAtMs: nowMs);

    await transaction(() async {
      if (updated.isDefault) {
        await (update(locations)..where(
              (l) => l.id.isNotValue(updated.id) & l.isDefault.equals(true),
            ))
            .write(const LocationsCompanion(isDefault: Value(false)));
      }

      await update(locations).replace(updated);

      final payload = {
        'id': updated.id,
        'name': updated.name,
        'kind': updated.kind,
        'is_default': updated.isDefault,
        'is_active': updated.isActive,
        'updated_at': nowMs,
      };

      await into(outboxEntries).insert(
        OutboxEntriesCompanion.insert(
          id: UuidGenerator.v7(),
          entity: 'location',
          entityId: updated.id,
          op: 'upsert',
          payloadJson: jsonEncode(payload),
          clientTs: nowMs,
          status: const Value('pending'),
          createdAt: nowMs,
        ),
      );
    });
  }

  /// Deactivates a location and writes to Outbox.
  Future<void> deleteLocation(String id) async {
    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;

    await transaction(() async {
      final existing = await (select(
        locations,
      )..where((l) => l.id.equals(id))).getSingleOrNull();
      if (existing == null) return;

      final updated = existing.copyWith(
        isActive: false,
        isDefault: false,
        updatedAtMs: nowMs,
      );

      await update(locations).replace(updated);

      final payload = {
        'id': id,
        'name': existing.name,
        'kind': existing.kind,
        'is_default': false,
        'is_active': false,
        'updated_at': nowMs,
      };

      await into(outboxEntries).insert(
        OutboxEntriesCompanion.insert(
          id: UuidGenerator.v7(),
          entity: 'location',
          entityId: id,
          op: 'delete',
          payloadJson: jsonEncode(payload),
          clientTs: nowMs,
          status: const Value('pending'),
          createdAt: nowMs,
        ),
      );
    });
  }

  // --- Sales, Payments, Customers & Providers Mutations ---

  /// Creates a sale and writes to Outbox in the same transaction.
  Future<Sale> createSale({
    String? id,
    String? locationId,
    String? customerId,
    required int totalCents,
    String? dueDate,
    String? note,
    bool legacy = false,
    int? createdAtMs,
  }) async {
    final saleId = id ?? UuidGenerator.v7();
    final nowMs = createdAtMs ?? DateTime.now().toUtc().millisecondsSinceEpoch;

    final sale = Sale(
      id: saleId,
      locationId: locationId,
      customerId: customerId,
      totalCents: totalCents,
      dueDate: dueDate,
      note: note,
      legacy: legacy,
      voidedAtMs: null,
      createdAtMs: nowMs,
      updatedAtMs: nowMs,
    );

    await into(sales).insert(sale, mode: InsertMode.insertOrReplace);

    final payload = {
      'id': saleId,
      'location_id': locationId,
      'customer_id': customerId,
      'total_cents': totalCents,
      'due_date': dueDate,
      'note': note,
      'created_at': nowMs,
      'voided_at': null,
    };

    await into(outboxEntries).insert(
      OutboxEntriesCompanion.insert(
        id: UuidGenerator.v7(),
        entity: 'sale',
        entityId: saleId,
        op: 'upsert',
        payloadJson: jsonEncode(payload),
        clientTs: nowMs,
        status: const Value('pending'),
        createdAt: nowMs,
      ),
    );

    return sale;
  }

  /// Records a payment (sale payment, customer repayment, or reversal) and writes to Outbox.
  Future<Payment> recordPayment({
    String? id,
    String? saleId,
    String? customerId,
    String? locationId,
    required String method,
    String? providerKey,
    required int amountCents,
    String? reference,
    int? receivedAtMs,
  }) async {
    final payId = id ?? UuidGenerator.v7();
    final nowMs = receivedAtMs ?? DateTime.now().toUtc().millisecondsSinceEpoch;

    final payment = Payment(
      id: payId,
      saleId: saleId,
      customerId: customerId,
      locationId: locationId,
      method: method,
      providerKey: providerKey,
      amountCents: amountCents,
      reference: reference,
      receivedAtMs: nowMs,
      createdAtMs: nowMs,
    );

    await into(payments).insert(payment, mode: InsertMode.insertOrIgnore);

    final payload = {
      'id': payId,
      'sale_id': saleId,
      'customer_id': customerId,
      'location_id': locationId,
      'method': method,
      'provider_key': providerKey,
      'amount_cents': amountCents,
      'reference': reference,
      'received_at': nowMs,
    };

    await into(outboxEntries).insert(
      OutboxEntriesCompanion.insert(
        id: UuidGenerator.v7(),
        entity: 'payment',
        entityId: payId,
        op: 'upsert',
        payloadJson: jsonEncode(payload),
        clientTs: nowMs,
        status: const Value('pending'),
        createdAt: nowMs,
      ),
    );

    return payment;
  }

  /// Upserts a customer (name, normalized phone, note) and writes to Outbox.
  Future<Customer> upsertCustomer({
    String? id,
    required String name,
    String? phone,
    String? note,
  }) async {
    final custId = id ?? UuidGenerator.v7();
    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;

    final existing = await (select(
      customers,
    )..where((c) => c.id.equals(custId))).getSingleOrNull();

    final customer = Customer(
      id: custId,
      name: name,
      phone: phone,
      note: note,
      createdAtMs: existing?.createdAtMs ?? nowMs,
      updatedAtMs: nowMs,
      deletedAtMs: null,
    );

    await into(customers).insert(customer, mode: InsertMode.insertOrReplace);

    final payload = {
      'id': custId,
      'name': name,
      'phone': phone,
      'note': note,
      'updated_at': nowMs,
      'deleted_at': null,
    };

    await into(outboxEntries).insert(
      OutboxEntriesCompanion.insert(
        id: UuidGenerator.v7(),
        entity: 'customer',
        entityId: custId,
        op: 'upsert',
        payloadJson: jsonEncode(payload),
        clientTs: nowMs,
        status: const Value('pending'),
        createdAt: nowMs,
      ),
    );

    return customer;
  }

  /// Soft deletes a customer and writes to Outbox.
  Future<void> deleteCustomer(String id) async {
    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;
    final existing = await (select(
      customers,
    )..where((c) => c.id.equals(id))).getSingleOrNull();
    if (existing == null) return;

    final updated = existing.copyWith(
      deletedAtMs: Value(nowMs),
      updatedAtMs: nowMs,
    );
    await update(customers).replace(updated);

    final payload = {
      'id': id,
      'name': existing.name,
      'phone': existing.phone,
      'note': existing.note,
      'updated_at': nowMs,
      'deleted_at': nowMs,
    };

    await into(outboxEntries).insert(
      OutboxEntriesCompanion.insert(
        id: UuidGenerator.v7(),
        entity: 'customer',
        entityId: id,
        op: 'delete',
        payloadJson: jsonEncode(payload),
        clientTs: nowMs,
        status: const Value('pending'),
        createdAt: nowMs,
      ),
    );
  }

  /// Upserts a payment provider (catalog or `custom.<uuid>`) and writes to Outbox.
  Future<PaymentProvider> upsertPaymentProvider({
    required String providerKey,
    required String kind,
    String? name,
    bool enabled = true,
    bool favorite = false,
  }) async {
    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;

    final provider = PaymentProvider(
      providerKey: providerKey,
      kind: kind,
      name: name,
      enabled: enabled,
      favorite: favorite,
      updatedAtMs: nowMs,
    );

    await into(
      paymentProviders,
    ).insert(provider, mode: InsertMode.insertOrReplace);

    final payload = {
      'provider_key': providerKey,
      'kind': kind,
      'name': name,
      'enabled': enabled,
      'favorite': favorite,
      'updated_at': nowMs,
    };

    await into(outboxEntries).insert(
      OutboxEntriesCompanion.insert(
        id: UuidGenerator.v7(),
        entity: 'payment_provider',
        entityId: providerKey,
        op: 'upsert',
        payloadJson: jsonEncode(payload),
        clientTs: nowMs,
        status: const Value('pending'),
        createdAt: nowMs,
      ),
    );

    return provider;
  }

  /// Updates a sale's due date and writes to Outbox.
  Future<void> updateSaleDueDate({
    required String saleId,
    required String dueDate,
  }) async {
    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;
    final existing = await (select(
      sales,
    )..where((s) => s.id.equals(saleId))).getSingleOrNull();
    if (existing == null) return;

    final updated = existing.copyWith(
      dueDate: Value(dueDate),
      updatedAtMs: nowMs,
    );
    await update(sales).replace(updated);

    final payload = {
      'id': saleId,
      'location_id': existing.locationId,
      'customer_id': existing.customerId,
      'total_cents': existing.totalCents,
      'due_date': dueDate,
      'note': existing.note,
      'created_at': existing.createdAtMs,
      'voided_at': existing.voidedAtMs,
    };

    await into(outboxEntries).insert(
      OutboxEntriesCompanion.insert(
        id: UuidGenerator.v7(),
        entity: 'sale',
        entityId: saleId,
        op: 'upsert',
        payloadJson: jsonEncode(payload),
        clientTs: nowMs,
        status: const Value('pending'),
        createdAt: nowMs,
      ),
    );
  }

  /// Marks a sale voided and writes to Outbox.
  Future<void> markSaleVoided({
    required String saleId,
    required int voidedAtMs,
  }) async {
    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;
    final existing = await (select(
      sales,
    )..where((s) => s.id.equals(saleId))).getSingleOrNull();
    if (existing == null) return;

    final updated = existing.copyWith(
      voidedAtMs: Value(voidedAtMs),
      updatedAtMs: nowMs,
    );
    await update(sales).replace(updated);

    final payload = {
      'id': saleId,
      'location_id': existing.locationId,
      'customer_id': existing.customerId,
      'total_cents': existing.totalCents,
      'due_date': existing.dueDate,
      'note': existing.note,
      'created_at': existing.createdAtMs,
      'voided_at': voidedAtMs,
    };

    await into(outboxEntries).insert(
      OutboxEntriesCompanion.insert(
        id: UuidGenerator.v7(),
        entity: 'sale',
        entityId: saleId,
        op: 'upsert',
        payloadJson: jsonEncode(payload),
        clientTs: nowMs,
        status: const Value('pending'),
        createdAt: nowMs,
      ),
    );
  }

  // --- Stock Queries ---

  /// Computes the authoritative stock for a product: SUM(movements where tracks_stock = true), optionally filtered by location.
  Future<int> getStock(String productId, {String? locationId}) async {
    var query = selectOnly(movements)
      ..addColumns([movements.qtyMilli.sum()])
      ..where(
        movements.productId.equals(productId) &
            movements.tracksStock.equals(true),
      );

    if (locationId != null) {
      query = query..where(movements.locationId.equals(locationId));
    }

    final row = await query.getSingle();
    return row.read(movements.qtyMilli.sum()) ?? 0;
  }

  /// Watches the stock for a given product reactively, optionally filtered by location.
  Stream<int> watchStock(String productId, {String? locationId}) {
    var query = selectOnly(movements)
      ..addColumns([movements.qtyMilli.sum()])
      ..where(
        movements.productId.equals(productId) &
            movements.tracksStock.equals(true),
      );

    if (locationId != null) {
      query = query..where(movements.locationId.equals(locationId));
    }

    return query.watchSingle().map(
      (row) => row.read(movements.qtyMilli.sum()) ?? 0,
    );
  }

  /// Checks if any product currently has negative stock (only stockable products).
  Future<bool> hasNegativeStock() async {
    final prods = await (select(
      products,
    )..where((p) => p.deletedAtMs.isNull() & p.kind.equals('stockable'))).get();
    for (final p in prods) {
      final stock = await getStock(p.id);
      if (stock < 0) return true;
    }
    return false;
  }

  /// Gets all active products with their current calculated stock.
  Future<List<ProductWithStock>> getAllProductsWithStock() async {
    final prods = await (select(
      products,
    )..where((p) => p.deletedAtMs.isNull())).get();
    final list = <ProductWithStock>[];
    for (final p in prods) {
      final s = await getStock(p.id);
      list.add(ProductWithStock(product: p, stockMilli: s));
    }
    return list;
  }

  Stream<List<ProductWithStock>> watchAllProductsWithStock() {
    // Watches movements table to recompute stock whenever any movement is inserted
    return select(movements).watch().asyncMap((_) => getAllProductsWithStock());
  }

  // --- Outbox Management ---

  /// Reads pending outbox entries up to [limit], ordered by relational dependency priority
  /// (locations -> providers -> customers -> products -> sales -> movements -> payments),
  /// then by client creation order (createdAt ASC).
  Future<List<OutboxEntry>> getPendingOutboxBatch({int limit = 100}) async {
    const entityPriority = CustomExpression<int>(
      "CASE entity "
      "WHEN 'location' THEN 1 "
      "WHEN 'payment_provider' THEN 2 "
      "WHEN 'customer' THEN 3 "
      "WHEN 'product' THEN 4 "
      "WHEN 'sale' THEN 5 "
      "WHEN 'movement' THEN 6 "
      "WHEN 'payment' THEN 7 "
      "ELSE 8 END",
    );

    return (select(outboxEntries)
          ..where((o) => o.status.equals('pending'))
          ..orderBy([
            (o) =>
                OrderingTerm(expression: entityPriority, mode: OrderingMode.asc),
            (o) =>
                OrderingTerm(expression: o.createdAt, mode: OrderingMode.asc),
          ])
          ..limit(limit))
        .get();
  }

  /// Counts the number of pending changes waiting to sync.
  Future<int> countPendingOutbox() async {
    final query = selectOnly(outboxEntries)
      ..addColumns([outboxEntries.id.count()])
      ..where(outboxEntries.status.equals('pending'));

    final row = await query.getSingle();
    return row.read(outboxEntries.id.count()) ?? 0;
  }

  Stream<int> watchPendingOutboxCount() {
    final query = selectOnly(outboxEntries)
      ..addColumns([outboxEntries.id.count()])
      ..where(outboxEntries.status.equals('pending'));

    return query.watchSingle().map(
      (row) => row.read(outboxEntries.id.count()) ?? 0,
    );
  }

  /// Marks accepted outbox entries as synced (or deletes them to conserve space).
  Future<void> markOutboxAccepted(List<String> ids) async {
    if (ids.isEmpty) return;
    await transaction(() async {
      await (update(outboxEntries)..where((o) => o.id.isIn(ids))).write(
        const OutboxEntriesCompanion(status: Value('synced')),
      );
    });
  }

  /// Marks rejected outbox entries as failed with server reason.
  Future<void> markOutboxRejected(List<Map<String, String>> rejected) async {
    if (rejected.isEmpty) return;
    await transaction(() async {
      for (final r in rejected) {
        final id = r['id'];
        final reason = r['reason'];
        if (id != null) {
          await (update(outboxEntries)..where((o) => o.id.equals(id))).write(
            OutboxEntriesCompanion(
              status: const Value('failed'),
              errorReason: Value(reason),
            ),
          );
        }
      }
    });
  }

  /// Retries changes that failed during an earlier sync attempt.
  /// A failed record may have been rejected because its related product or
  /// location had not reached the server yet; initial history sync should be
  /// able to replay it after pairing.
  Future<void> requeueFailedOutbox() async {
    await (update(
      outboxEntries,
    )..where((o) => o.status.equals('failed'))).write(
      const OutboxEntriesCompanion(
        status: Value('pending'),
        errorReason: Value.absent(),
      ),
    );
  }

  /// Requeues every local change for a deliberate full server rebuild.
  /// Replays are idempotent on the server and preserve phone data after a
  /// server database reset.
  Future<void> requeueAllOutboxForFullResync() async {
    await update(outboxEntries).write(
      const OutboxEntriesCompanion(
        status: Value('pending'),
        errorReason: Value.absent(),
      ),
    );
  }

  /// True when an older install has rejected history or legacy sales whose
  /// headers were never queued for upload.
  Future<bool> needsHistoricalSyncRepair() async {
    final rows = await customSelect('''
      SELECT (
        EXISTS (SELECT 1 FROM outbox_entries WHERE status = 'failed')
        OR EXISTS (
          SELECT 1 FROM sales s
          WHERE s.legacy = 1
            AND NOT EXISTS (
              SELECT 1 FROM outbox_entries o
              WHERE o.entity = 'sale' AND o.entity_id = s.id
            )
        )
      ) AS needs
    ''').get();
    return (rows.first.data['needs'] as num?)?.toInt() == 1;
  }

  /// Prepares offline history for initial sync upload if not already in outbox.
  Future<int> seedOutboxFromHistory() async {
    return transaction(() async {
      int queued = 0;
      final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;

      // 1. All locations (prerequisite for sales, movements, payments)
      final allLocs = await select(locations).get();
      for (final l in allLocs) {
        final exists = await (select(
          outboxEntries,
        )..where((o) => o.entityId.equals(l.id))).getSingleOrNull();
        if (exists == null) {
          final payload = {
            'id': l.id,
            'name': l.name,
            'kind': l.kind,
            'is_default': l.isDefault,
            'is_active': l.isActive,
            'updated_at': l.updatedAtMs,
          };

          await into(outboxEntries).insert(
            OutboxEntriesCompanion.insert(
              id: UuidGenerator.v7(),
              entity: 'location',
              entityId: l.id,
              op: l.isActive ? 'upsert' : 'delete',
              payloadJson: jsonEncode(payload),
              clientTs: l.updatedAtMs,
              status: const Value('pending'),
              createdAt: nowMs,
            ),
          );
          queued++;
        }
      }

      // 2. All payment providers
      final allProvs = await select(paymentProviders).get();
      for (final p in allProvs) {
        final exists = await (select(
          outboxEntries,
        )..where((o) => o.entityId.equals(p.providerKey))).getSingleOrNull();
        if (exists == null) {
          final payload = {
            'provider_key': p.providerKey,
            'kind': p.kind,
            'name': p.name,
            'enabled': p.enabled,
            'favorite': p.favorite,
            'updated_at': p.updatedAtMs,
          };

          await into(outboxEntries).insert(
            OutboxEntriesCompanion.insert(
              id: UuidGenerator.v7(),
              entity: 'payment_provider',
              entityId: p.providerKey,
              op: 'upsert',
              payloadJson: jsonEncode(payload),
              clientTs: p.updatedAtMs,
              status: const Value('pending'),
              createdAt: nowMs,
            ),
          );
          queued++;
        }
      }

      // 3. All customers
      final allCusts = await select(customers).get();
      for (final c in allCusts) {
        final exists = await (select(
          outboxEntries,
        )..where((o) => o.entityId.equals(c.id))).getSingleOrNull();
        if (exists == null) {
          final payload = {
            'id': c.id,
            'name': c.name,
            'phone': c.phone,
            'note': c.note,
            'updated_at': c.updatedAtMs,
            'deleted_at': c.deletedAtMs,
          };

          await into(outboxEntries).insert(
            OutboxEntriesCompanion.insert(
              id: UuidGenerator.v7(),
              entity: 'customer',
              entityId: c.id,
              op: c.deletedAtMs != null ? 'delete' : 'upsert',
              payloadJson: jsonEncode(payload),
              clientTs: c.updatedAtMs,
              status: const Value('pending'),
              createdAt: nowMs,
            ),
          );
          queued++;
        }
      }

      // 4. All products
      final allProds = await select(products).get();
      for (final p in allProds) {
        final exists = await (select(
          outboxEntries,
        )..where((o) => o.entityId.equals(p.id))).getSingleOrNull();
        if (exists == null) {
          final mats = await (select(
            productMaterials,
          )..where((pm) => pm.productId.equals(p.id))).get();
          final materialsWire = mats.isNotEmpty
              ? mats
                    .map(
                      (m) => {
                        'product_id': m.materialProductId,
                        'qty': QuantityFormatter.toWireQty(m.qtyMilli),
                      },
                    )
                    .toList()
              : null;

          final payload = {
            'id': p.id,
            'name': p.name,
            'sku': p.sku,
            'unit': p.unit,
            'kind': p.kind,
            'cost_price_cents': p.costPriceCents,
            'sell_price_cents': p.sellPriceCents,
            'ask_price': p.askPrice,
            'materials': materialsWire,
            'kind_changed_at': p.kindChangedAtMs,
            'low_stock_at': p.lowStockAtMilli != null
                ? QuantityFormatter.toWireQty(p.lowStockAtMilli!)
                : null,
            'is_active': p.isActive,
            'updated_at': p.updatedAtMs,
          };

          await into(outboxEntries).insert(
            OutboxEntriesCompanion.insert(
              id: UuidGenerator.v7(),
              entity: 'product',
              entityId: p.id,
              op: p.deletedAtMs != null ? 'delete' : 'upsert',
              payloadJson: jsonEncode(payload),
              clientTs: p.updatedAtMs,
              status: const Value('pending'),
              createdAt: nowMs,
            ),
          );
          queued++;
        }
      }

      // 5. All sales, including legacy sales backfilled from pre-sale-schema movements.
      // Sales headers must be uploaded before their movements and payments so the server can
      // link items and preserve the historical ledger.
      final allSales = await select(sales).get();
      for (final s in allSales) {
        final exists = await (select(
          outboxEntries,
        )..where((o) => o.entityId.equals(s.id))).getSingleOrNull();
        if (exists == null) {
          final payload = {
            'id': s.id,
            'location_id': s.locationId,
            'customer_id': s.customerId,
            'total_cents': s.totalCents,
            'due_date': s.dueDate,
            'note': s.note,
            'legacy': s.legacy,
            'created_at': s.createdAtMs,
            'voided_at': s.voidedAtMs,
          };

          await into(outboxEntries).insert(
            OutboxEntriesCompanion.insert(
              id: UuidGenerator.v7(),
              entity: 'sale',
              entityId: s.id,
              op: 'upsert',
              payloadJson: jsonEncode(payload),
              clientTs: s.createdAtMs,
              status: const Value('pending'),
              createdAt: nowMs,
            ),
          );
          queued++;
        }
      }

      // 6. All movements
      final allMovs = await select(movements).get();
      for (final m in allMovs) {
        final exists = await (select(
          outboxEntries,
        )..where((o) => o.entityId.equals(m.id))).getSingleOrNull();
        if (exists == null) {
          final payload = {
            'id': m.id,
            'product_id': m.productId,
            'location_id': m.locationId,
            'transfer_id': m.transferId,
            'sale_id': m.saleId,
            'source_movement_id': m.sourceMovementId,
            'type': m.type,
            'tracks_stock': m.tracksStock,
            'qty': QuantityFormatter.toWireQty(m.qtyMilli),
            'unit_price_cents': m.unitPriceCents,
            'note': m.note,
            'created_at': m.createdAtMs,
          };

          await into(outboxEntries).insert(
            OutboxEntriesCompanion.insert(
              id: UuidGenerator.v7(),
              entity: 'movement',
              entityId: m.id,
              op: 'upsert',
              payloadJson: jsonEncode(payload),
              clientTs: m.createdAtMs,
              status: const Value('pending'),
              createdAt: nowMs,
            ),
          );
          queued++;
        }
      }

      // 7. All payments
      final allPayments = await select(payments).get();
      for (final pay in allPayments) {
        final exists = await (select(
          outboxEntries,
        )..where((o) => o.entityId.equals(pay.id))).getSingleOrNull();
        if (exists == null) {
          final payload = {
            'id': pay.id,
            'sale_id': pay.saleId,
            'customer_id': pay.customerId,
            'location_id': pay.locationId,
            'method': pay.method,
            'provider_key': pay.providerKey,
            'amount_cents': pay.amountCents,
            'reference': pay.reference,
            'received_at': pay.receivedAtMs,
          };

          await into(outboxEntries).insert(
            OutboxEntriesCompanion.insert(
              id: UuidGenerator.v7(),
              entity: 'payment',
              entityId: pay.id,
              op: 'upsert',
              payloadJson: jsonEncode(payload),
              clientTs: pay.receivedAtMs,
              status: const Value('pending'),
              createdAt: nowMs,
            ),
          );
          queued++;
        }
      }

      return queued;
    });
  }

  // --- Meta Key-Value Storage ---

  Future<String?> getMeta(String key) async {
    final entry = await (select(
      syncMetaEntries,
    )..where((s) => s.key.equals(key))).getSingleOrNull();
    return entry?.value;
  }

  Future<void> setMeta(String key, String value) async {
    await into(syncMetaEntries).insert(
      SyncMetaEntry(key: key, value: value),
      mode: InsertMode.insertOrReplace,
    );
  }

  Future<int> getServerCursor() async {
    final val = await getMeta('server_cursor');
    return val != null ? (int.tryParse(val) ?? 0) : 0;
  }

  Future<void> setServerCursor(int cursor) async {
    await setMeta('server_cursor', cursor.toString());
  }

  Future<DateTime?> getLastSyncedAt() async {
    final val = await getMeta('last_synced_at_ms');
    if (val == null) return null;
    final ms = int.tryParse(val);
    return ms != null
        ? DateTime.fromMillisecondsSinceEpoch(ms, isUtc: true)
        : null;
  }

  Future<void> setLastSyncedAt(DateTime dt) async {
    await setMeta(
      'last_synced_at_ms',
      dt.toUtc().millisecondsSinceEpoch.toString(),
    );
  }
}

class ProductWithStock {
  final Product product;
  final int stockMilli;
  final Map<String, int> stockByLocation;
  final int inTransitMilli;

  ProductWithStock({
    required this.product,
    required this.stockMilli,
    this.stockByLocation = const {},
    this.inTransitMilli = 0,
  });

  bool get isStockable => product.kind == 'stockable';
  bool get isService => product.kind == 'service';
  bool get isConsumable => product.kind == 'consumable';
  bool get isNegative => isStockable && stockMilli < 0;
  bool get isLow =>
      isStockable &&
      product.lowStockAtMilli != null &&
      stockMilli <= product.lowStockAtMilli!;
}

LazyDatabase _openConnection() {
  return LazyDatabase(() async {
    final file = await AppDatabase.getDatabaseFile();
    return NativeDatabase.createInBackground(file);
  });
}
