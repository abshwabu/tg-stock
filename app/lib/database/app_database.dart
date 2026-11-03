import 'dart:convert';
import 'dart:io';

import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

import '../utils/quantity_formatter.dart';
import '../utils/uuid_generator.dart';

part 'app_database.g.dart';

class Products extends Table {
  TextColumn get id => text()(); // UUIDv7
  TextColumn get name => text()();
  TextColumn get sku => text().nullable()();
  TextColumn get unit => text().withDefault(const Constant('pcs'))();
  IntColumn get costPriceCents => integer().withDefault(const Constant(0))();
  IntColumn get sellPriceCents => integer().withDefault(const Constant(0))();
  IntColumn get lowStockAtMilli => integer().nullable()();
  BoolColumn get isActive => boolean().withDefault(const Constant(true))();
  IntColumn get updatedAtMs => integer()();
  IntColumn get deletedAtMs => integer().nullable()();

  @override
  Set<Column> get primaryKey => {id};
}

class Movements extends Table {
  TextColumn get id => text()(); // UUIDv7
  TextColumn get productId => text()();
  TextColumn get locationId => text().nullable()();
  TextColumn get transferId => text().nullable()();
  TextColumn get type => text()(); // 'in', 'sale', 'adjust', 'transfer_out', 'transfer_in'
  IntColumn get qtyMilli => integer()(); // signed integer thousandths (e.g. 5000 = 5.000, -2500 = -2.500)
  IntColumn get unitPriceCents => integer().withDefault(const Constant(0))();
  TextColumn get note => text().nullable()();
  IntColumn get createdAtMs => integer()();

  @override
  Set<Column> get primaryKey => {id};
}

class Locations extends Table {
  TextColumn get id => text()(); // UUIDv7
  TextColumn get name => text()();
  TextColumn get kind => text().withDefault(const Constant('store'))(); // 'store', 'warehouse', 'other'
  BoolColumn get isDefault => boolean().withDefault(const Constant(false))();
  BoolColumn get isActive => boolean().withDefault(const Constant(true))();
  IntColumn get updatedAtMs => integer()();

  @override
  Set<Column> get primaryKey => {id};
}

class OutboxEntries extends Table {
  TextColumn get id => text()(); // Client UUIDv7
  TextColumn get entity => text()(); // 'product', 'movement'
  TextColumn get entityId => text()();
  TextColumn get op => text()(); // 'upsert', 'delete'
  TextColumn get payloadJson => text()();
  IntColumn get clientTs => integer()();
  TextColumn get status => text().withDefault(const Constant('pending'))(); // 'pending', 'synced', 'failed'
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

@DriftDatabase(tables: [Products, Movements, OutboxEntries, SyncMetaEntries, AppSettings, Locations])
class AppDatabase extends _$AppDatabase {
  AppDatabase([QueryExecutor? e]) : super(e ?? _openConnection());

  AppDatabase.inMemory() : super(NativeDatabase.memory());

  @override
  int get schemaVersion => 2;

  @override
  MigrationStrategy get migration => MigrationStrategy(
    onCreate: (m) async {
      await m.createAll();
      await _createIndexes();
      await _seedDefaultLocationIfEmpty();
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

        await _createIndexes();
      }
    },
    beforeOpen: (details) async {
      await customStatement('PRAGMA foreign_keys = ON;');
      await _createIndexes();
    },
  );

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
      'CREATE INDEX IF NOT EXISTS idx_products_deleted ON products(deleted_at_ms);',
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
    int costPriceCents = 0,
    int sellPriceCents = 0,
    int? lowStockAtMilli,
  }) async {
    final prodId = id ?? UuidGenerator.v7();
    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;

    return transaction(() async {
      final product = Product(
        id: prodId,
        name: name,
        sku: sku,
        unit: unit,
        costPriceCents: costPriceCents,
        sellPriceCents: sellPriceCents,
        lowStockAtMilli: lowStockAtMilli,
        isActive: true,
        updatedAtMs: nowMs,
        deletedAtMs: null,
      );

      await into(products).insert(product, mode: InsertMode.insertOrReplace);

      final payload = {
        'id': prodId,
        'name': name,
        'sku': sku,
        'unit': unit,
        'cost_price_cents': costPriceCents,
        'sell_price_cents': sellPriceCents,
        'low_stock_at': lowStockAtMilli != null ? QuantityFormatter.toWireQty(lowStockAtMilli) : null,
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
  Future<void> updateProduct(Product product) async {
    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;
    final updated = product.copyWith(updatedAtMs: nowMs);

    await transaction(() async {
      await update(products).replace(updated);

      final payload = {
        'id': updated.id,
        'name': updated.name,
        'sku': updated.sku,
        'unit': updated.unit,
        'cost_price_cents': updated.costPriceCents,
        'sell_price_cents': updated.sellPriceCents,
        'low_stock_at': updated.lowStockAtMilli != null ? QuantityFormatter.toWireQty(updated.lowStockAtMilli!) : null,
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
      final existing = await (select(products)..where((p) => p.id.equals(id))).getSingleOrNull();
      if (existing == null) return;

      final updated = existing.copyWith(
        deletedAtMs: Value(nowMs),
        isActive: false,
        updatedAtMs: nowMs,
      );

      await update(products).replace(updated);

      final payload = {
        'id': id,
        'name': existing.name,
        'sku': existing.sku,
        'unit': existing.unit,
        'cost_price_cents': existing.costPriceCents,
        'sell_price_cents': existing.sellPriceCents,
        'low_stock_at': existing.lowStockAtMilli != null ? QuantityFormatter.toWireQty(existing.lowStockAtMilli!) : null,
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
    required String type, // 'in', 'sale', 'adjust', 'transfer_out', 'transfer_in'
    required int qtyMilli, // signed: positive for intake, negative for sale
    int unitPriceCents = 0,
    String? note,
    String? locationId,
    String? transferId,
    int? createdAtMs,
  }) async {
    final movId = id ?? UuidGenerator.v7();
    final nowMs = createdAtMs ?? DateTime.now().toUtc().millisecondsSinceEpoch;

    return transaction(() async {
      String? resolvedLocId = locationId;
      if (resolvedLocId == null) {
        final defLoc = await (select(locations)
              ..where((l) => l.isDefault.equals(true) & l.isActive.equals(true))
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
        note: note,
        createdAtMs: nowMs,
        locationId: resolvedLocId,
        transferId: transferId,
      );

      await into(movements).insert(movement, mode: InsertMode.insertOrIgnore);

      final payload = {
        'id': movId,
        'product_id': productId,
        'location_id': resolvedLocId,
        'transfer_id': transferId,
        'type': type,
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
        await (update(locations)..where((l) => l.id.isNotValue(updated.id) & l.isDefault.equals(true))).write(
          const LocationsCompanion(isDefault: Value(false)),
        );
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
      final existing = await (select(locations)..where((l) => l.id.equals(id))).getSingleOrNull();
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

  // --- Stock Queries ---

  /// Computes the authoritative stock for a product: SUM(movements), optionally filtered by location.
  Future<int> getStock(String productId, {String? locationId}) async {
    var query = selectOnly(movements)
      ..addColumns([movements.qtyMilli.sum()])
      ..where(movements.productId.equals(productId));

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
      ..where(movements.productId.equals(productId));

    if (locationId != null) {
      query = query..where(movements.locationId.equals(locationId));
    }

    return query.watchSingle().map((row) => row.read(movements.qtyMilli.sum()) ?? 0);
  }

  /// Checks if any product currently has negative stock.
  Future<bool> hasNegativeStock() async {
    final prods = await (select(products)..where((p) => p.deletedAtMs.isNull())).get();
    for (final p in prods) {
      final stock = await getStock(p.id);
      if (stock < 0) return true;
    }
    return false;
  }

  /// Gets all active products with their current calculated stock.
  Future<List<ProductWithStock>> getAllProductsWithStock() async {
    final prods = await (select(products)..where((p) => p.deletedAtMs.isNull())).get();
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

  /// Reads pending outbox entries up to [limit].
  Future<List<OutboxEntry>> getPendingOutboxBatch({int limit = 200}) async {
    return (select(outboxEntries)
          ..where((o) => o.status.equals('pending'))
          ..orderBy([(o) => OrderingTerm(expression: o.createdAt, mode: OrderingMode.asc)])
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

    return query.watchSingle().map((row) => row.read(outboxEntries.id.count()) ?? 0);
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

  /// Prepares offline history for initial sync upload if not already in outbox.
  Future<int> seedOutboxFromHistory() async {
    return transaction(() async {
      int queued = 0;
      final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;

      // 1. All products
      final allProds = await select(products).get();
      for (final p in allProds) {
        final exists = await (select(outboxEntries)..where((o) => o.entityId.equals(p.id))).getSingleOrNull();
        if (exists == null) {
          final payload = {
            'id': p.id,
            'name': p.name,
            'sku': p.sku,
            'unit': p.unit,
            'cost_price_cents': p.costPriceCents,
            'sell_price_cents': p.sellPriceCents,
            'low_stock_at': p.lowStockAtMilli != null ? QuantityFormatter.toWireQty(p.lowStockAtMilli!) : null,
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

      // 2. All movements
      final allMovs = await select(movements).get();
      for (final m in allMovs) {
        final exists = await (select(outboxEntries)..where((o) => o.entityId.equals(m.id))).getSingleOrNull();
        if (exists == null) {
          final payload = {
            'id': m.id,
            'product_id': m.productId,
            'location_id': m.locationId,
            'transfer_id': m.transferId,
            'type': m.type,
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

      // 3. All locations
      final allLocs = await select(locations).get();
      for (final l in allLocs) {
        final exists = await (select(outboxEntries)..where((o) => o.entityId.equals(l.id))).getSingleOrNull();
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

      return queued;
    });
  }

  // --- Meta Key-Value Storage ---

  Future<String?> getMeta(String key) async {
    final entry = await (select(syncMetaEntries)..where((s) => s.key.equals(key))).getSingleOrNull();
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
    return ms != null ? DateTime.fromMillisecondsSinceEpoch(ms, isUtc: true) : null;
  }

  Future<void> setLastSyncedAt(DateTime dt) async {
    await setMeta('last_synced_at_ms', dt.toUtc().millisecondsSinceEpoch.toString());
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

  bool get isNegative => stockMilli < 0;
  bool get isLow => product.lowStockAtMilli != null && stockMilli <= product.lowStockAtMilli!;
}

LazyDatabase _openConnection() {
  return LazyDatabase(() async {
    final file = await AppDatabase.getDatabaseFile();
    return NativeDatabase.createInBackground(file);
  });
}
