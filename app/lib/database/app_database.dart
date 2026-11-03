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
  TextColumn get type => text()(); // 'in', 'sale', 'adjust'
  IntColumn get qtyMilli => integer()(); // signed integer thousandths (e.g. 5000 = 5.000, -2500 = -2.500)
  IntColumn get unitPriceCents => integer().withDefault(const Constant(0))();
  TextColumn get note => text().nullable()();
  IntColumn get createdAtMs => integer()();

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

@DriftDatabase(tables: [Products, Movements, OutboxEntries, SyncMetaEntries, AppSettings])
class AppDatabase extends _$AppDatabase {
  AppDatabase([QueryExecutor? e]) : super(e ?? _openConnection());

  AppDatabase.inMemory() : super(NativeDatabase.memory());

  @override
  int get schemaVersion => 1;

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
    required String type, // 'in', 'sale', 'adjust'
    required int qtyMilli, // signed: positive for intake, negative for sale
    int unitPriceCents = 0,
    String? note,
  }) async {
    final movId = id ?? UuidGenerator.v7();
    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;

    return transaction(() async {
      final movement = Movement(
        id: movId,
        productId: productId,
        type: type,
        qtyMilli: qtyMilli,
        unitPriceCents: unitPriceCents,
        note: note,
        createdAtMs: nowMs,
      );

      await into(movements).insert(movement, mode: InsertMode.insertOrIgnore);

      final payload = {
        'id': movId,
        'product_id': productId,
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

  // --- Stock Queries ---

  /// Computes the authoritative stock for a product: SUM(movements).
  Future<int> getStock(String productId) async {
    final query = selectOnly(movements)
      ..addColumns([movements.qtyMilli.sum()])
      ..where(movements.productId.equals(productId));

    final row = await query.getSingle();
    return row.read(movements.qtyMilli.sum()) ?? 0;
  }

  /// Watches the stock for a given product reactively.
  Stream<int> watchStock(String productId) {
    final query = selectOnly(movements)
      ..addColumns([movements.qtyMilli.sum()])
      ..where(movements.productId.equals(productId));

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

  ProductWithStock({required this.product, required this.stockMilli});

  bool get isNegative => stockMilli < 0;
  bool get isLow => product.lowStockAtMilli != null && stockMilli <= product.lowStockAtMilli!;
}

LazyDatabase _openConnection() {
  return LazyDatabase(() async {
    final dbFolder = await getApplicationDocumentsDirectory();
    final file = File(p.join(dbFolder.path, 'inventory.sqlite'));
    return NativeDatabase.createInBackground(file);
  });
}
