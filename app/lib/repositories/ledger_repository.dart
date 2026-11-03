import 'package:drift/drift.dart';

import '../database/app_database.dart';
import '../utils/quantity_formatter.dart';

class InsufficientStockException implements Exception {
  final String productId;
  final String productName;
  final int currentStockMilli;
  final int requestedQtyMilli;

  InsufficientStockException({
    required this.productId,
    required this.productName,
    required this.currentStockMilli,
    required this.requestedQtyMilli,
  });

  @override
  String toString() =>
      'Insufficient stock for $productName. Available: ${QuantityFormatter.formatDisplay(currentStockMilli)}, requested: ${QuantityFormatter.formatDisplay(requestedQtyMilli)}';
}

class UndoExpiredException implements Exception {
  final String message;
  UndoExpiredException([this.message = 'Transactions older than 10 minutes cannot be undone.']);

  @override
  String toString() => message;
}

class NoMovementToUndoException implements Exception {
  final String message;
  NoMovementToUndoException([this.message = 'No recent movements to undo.']);

  @override
  String toString() => message;
}

class SaleLineItem {
  final Product product;
  final int qtyMilli; // positive requested quantity (e.g. 2000 for 2 units)
  final int unitPriceCents;

  SaleLineItem({
    required this.product,
    required this.qtyMilli,
    required this.unitPriceCents,
  });

  int get totalCents => (qtyMilli.abs() * unitPriceCents) ~/ 1000;
}

class MovementWithProduct {
  final Movement movement;
  final Product? product;

  MovementWithProduct({required this.movement, this.product});

  bool get canUndo {
    final now = DateTime.now().toUtc().millisecondsSinceEpoch;
    return (now - movement.createdAtMs) <= 600000; // 10 minutes (600,000 ms)
  }
}

class LedgerRepository {
  final AppDatabase db;

  LedgerRepository(this.db);

  // ==========================================
  // PRODUCTS (Mutable Catalog Metadata)
  // ==========================================

  Future<Product> createProduct({
    String? id,
    required String name,
    String? sku,
    String unit = 'pcs',
    int costPriceCents = 0,
    int sellPriceCents = 0,
    int? lowStockAtMilli,
    int? openingStockMilli,
  }) async {
    final prod = await db.createProduct(
      id: id,
      name: name,
      sku: sku,
      unit: unit,
      costPriceCents: costPriceCents,
      sellPriceCents: sellPriceCents,
      lowStockAtMilli: lowStockAtMilli,
    );

    if (openingStockMilli != null && openingStockMilli > 0) {
      await recordStockIn(
        productId: prod.id,
        qtyMilli: openingStockMilli,
        unitPriceCents: costPriceCents,
        note: 'Opening stock',
      );
    }

    return prod;
  }

  Future<void> updateProduct(Product product) async {
    await db.updateProduct(product);
  }

  Future<void> deleteProduct(String id) async {
    await db.deleteProduct(id);
  }

  Future<Product?> getProduct(String id) async {
    return (db.select(db.products)..where((p) => p.id.equals(id))).getSingleOrNull();
  }

  Future<List<ProductWithStock>> listProducts({String? query, bool includeDeleted = false}) async {
    var q = db.select(db.products);
    if (!includeDeleted) {
      q = q..where((p) => p.deletedAtMs.isNull());
    }

    final queryClean = query?.trim().toLowerCase();
    if (queryClean != null && queryClean.isNotEmpty) {
      q = q
        ..where((p) =>
            p.name.lower().like('%$queryClean%') |
            (p.sku.isNotNull() & p.sku.lower().like('%$queryClean%')));
    }

    final prods = await (q..orderBy([(p) => OrderingTerm(expression: p.name, mode: OrderingMode.asc)])).get();

    final result = <ProductWithStock>[];
    for (final p in prods) {
      final stock = await stockOf(p.id);
      result.add(ProductWithStock(product: p, stockMilli: stock));
    }
    return result;
  }

  Stream<List<ProductWithStock>> watchProducts({String? query}) {
    return db.select(db.movements).watch().asyncMap((_) => listProducts(query: query));
  }

  Future<List<Product>> getRecentProducts({int limit = 8}) async {
    // Finds products with most recent movements
    final recentMovs = await (db.select(db.movements)
          ..orderBy([(m) => OrderingTerm(expression: m.createdAtMs, mode: OrderingMode.desc)])
          ..limit(30))
        .get();

    final seenProductIds = <String>{};
    final result = <Product>[];

    for (final m in recentMovs) {
      if (seenProductIds.add(m.productId)) {
        final prod = await getProduct(m.productId);
        if (prod != null && prod.deletedAtMs == null) {
          result.add(prod);
          if (result.length >= limit) break;
        }
      }
    }

    if (result.length < limit) {
      final moreProds = await (db.select(db.products)
            ..where((p) => p.deletedAtMs.isNull())
            ..limit(limit - result.length))
          .get();
      for (final p in moreProds) {
        if (seenProductIds.add(p.id)) {
          result.add(p);
        }
      }
    }

    return result;
  }

  // ==========================================
  // MOVEMENTS (Sole Write Path for Ledger)
  // ==========================================

  /// Records stock intake (supplier delivery or inventory addition).
  Future<Movement> recordStockIn({
    required String productId,
    required int qtyMilli,
    int unitPriceCents = 0,
    String? note,
  }) async {
    assert(qtyMilli > 0, 'Stock in quantity must be positive');
    return db.recordMovement(
      productId: productId,
      type: 'in',
      qtyMilli: qtyMilli,
      unitPriceCents: unitPriceCents,
      note: note,
    );
  }

  /// Records a point-of-sale customer purchase with multiple line items.
  Future<List<Movement>> recordSale({
    required List<SaleLineItem> items,
    String? note,
  }) async {
    if (items.isEmpty) return [];

    final allowNegative = await getAllowNegativeStock();

    // 1. Stock check: if negative stock not allowed, verify sufficient inventory
    if (!allowNegative) {
      for (final item in items) {
        final currentStock = await stockOf(item.product.id);
        if (currentStock < item.qtyMilli) {
          throw InsufficientStockException(
            productId: item.product.id,
            productName: item.product.name,
            currentStockMilli: currentStock,
            requestedQtyMilli: item.qtyMilli,
          );
        }
      }
    }

    // 2. Ingest movements within a single atomic database transaction
    final movements = <Movement>[];
    await db.transaction(() async {
      for (final item in items) {
        final mov = await db.recordMovement(
          productId: item.product.id,
          type: 'sale',
          qtyMilli: -item.qtyMilli.abs(), // Strictly negative for sale
          unitPriceCents: item.unitPriceCents,
          note: note ?? 'Sale',
        );
        movements.add(mov);
      }
    });

    return movements;
  }

  /// Records a physical counted adjustment with explicit reason.
  /// Stores signed difference = (countedQtyMilli - currentStockMilli).
  Future<Movement> recordAdjustment({
    required String productId,
    required int countedQtyMilli,
    required String reason, // 'damaged', 'lost', 'count_correction', 'other'
    String? note,
  }) async {
    final currentStock = await stockOf(productId);
    final diffMilli = countedQtyMilli - currentStock;

    final reasonLabel = switch (reason) {
      'damaged' => 'Damaged / Spoilage (የተበላሸ)',
      'lost' => 'Lost / Shrinkage (የጠፋ)',
      'count_correction' => 'Count Correction (የቆጠራ እርማት)',
      _ => 'Other (ሌላ ምክንያት)',
    };

    final combinedNote = note != null ? '$reasonLabel: $note' : reasonLabel;
    final prod = await getProduct(productId);

    return db.recordMovement(
      productId: productId,
      type: 'adjust',
      qtyMilli: diffMilli,
      unitPriceCents: prod?.costPriceCents ?? 0,
      note: combinedNote,
    );
  }

  /// Undoes own last action within 10 minutes via a compensating movement.
  /// Never deletes or mutates the original movement.
  Future<Movement> undoLastAction() async {
    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;

    // Find the most recent movement
    final lastMovement = await (db.select(db.movements)
          ..orderBy([(m) => OrderingTerm(expression: m.createdAtMs, mode: OrderingMode.desc)])
          ..limit(1))
        .getSingleOrNull();

    if (lastMovement == null) {
      throw NoMovementToUndoException();
    }

    final elapsedMs = nowMs - lastMovement.createdAtMs;
    const tenMinutesMs = 10 * 60 * 1000; // 600,000 ms

    if (elapsedMs > tenMinutesMs) {
      throw UndoExpiredException(
        'Transaction was performed ${(elapsedMs / 60000).toStringAsFixed(1)} minutes ago. '
        'Actions can only be undone within 10 minutes.',
      );
    }

    // Compensating movement: signed opposite quantity
    final compensatingQtyMilli = -lastMovement.qtyMilli;

    return db.recordMovement(
      productId: lastMovement.productId,
      type: 'adjust',
      qtyMilli: compensatingQtyMilli,
      unitPriceCents: lastMovement.unitPriceCents,
      note: 'Undo: compensating movement for ${lastMovement.id} (${lastMovement.type})',
    );
  }

  // ==========================================
  // STOCK CALCULATION & INVARIANTS
  // ==========================================

  /// Authoritative Stock = SUM(movements.qtyMilli)
  Future<int> stockOf(String productId) async {
    return db.getStock(productId);
  }

  Stream<int> watchStockOf(String productId) {
    return db.watchStock(productId);
  }

  /// Calculates total sales in ETB cents for today (midnight local time to now).
  Future<int> todaySalesTotalCents() async {
    final now = DateTime.now();
    final startOfDayMs = DateTime(now.year, now.month, now.day).toUtc().millisecondsSinceEpoch;

    final sales = await (db.select(db.movements)
          ..where((m) => m.type.equals('sale') & m.createdAtMs.isBiggerOrEqualValue(startOfDayMs)))
        .get();

    int totalCents = 0;
    for (final s in sales) {
      totalCents += (s.qtyMilli.abs() * s.unitPriceCents) ~/ 1000;
    }
    return totalCents;
  }

  Stream<int> watchTodaySalesTotalCents() {
    return db.select(db.movements).watch().asyncMap((_) => todaySalesTotalCents());
  }

  /// Count of active products where stock is at or below their low stock threshold.
  Future<int> lowStockCount() async {
    final prods = await (db.select(db.products)..where((p) => p.deletedAtMs.isNull())).get();
    int count = 0;
    for (final p in prods) {
      final stock = await stockOf(p.id);
      final threshold = p.lowStockAtMilli ?? 5000; // 5.000 units default
      if (stock <= threshold) {
        count++;
      }
    }
    return count;
  }

  Stream<int> watchLowStockCount() {
    return db.select(db.movements).watch().asyncMap((_) => lowStockCount());
  }

  // ==========================================
  // HISTORY & AUDIT QUERIES
  // ==========================================

  Future<List<MovementWithProduct>> listMovements({
    String? productId,
    String? type,
    DateTime? from,
    DateTime? to,
    int limit = 100,
  }) async {
    var q = db.select(db.movements);

    if (productId != null) {
      q = q..where((m) => m.productId.equals(productId));
    }
    if (type != null && type != 'all') {
      q = q..where((m) => m.type.equals(type));
    }
    if (from != null) {
      q = q..where((m) => m.createdAtMs.isBiggerOrEqualValue(from.toUtc().millisecondsSinceEpoch));
    }
    if (to != null) {
      q = q..where((m) => m.createdAtMs.isSmallerOrEqualValue(to.toUtc().millisecondsSinceEpoch));
    }

    final rows = await (q
          ..orderBy([(m) => OrderingTerm(expression: m.createdAtMs, mode: OrderingMode.desc)])
          ..limit(limit))
        .get();

    final result = <MovementWithProduct>[];
    for (final m in rows) {
      final prod = await getProduct(m.productId);
      result.add(MovementWithProduct(movement: m, product: prod));
    }
    return result;
  }

  Stream<List<MovementWithProduct>> watchMovements({String? type}) {
    return db.select(db.movements).watch().asyncMap((_) => listMovements(type: type));
  }

  // ==========================================
  // SETTINGS REPOSITORY
  // ==========================================

  Future<String?> getSetting(String key, {String? defaultValue}) async {
    final entry = await (db.select(db.appSettings)..where((s) => s.key.equals(key))).getSingleOrNull();
    return entry?.value ?? defaultValue;
  }

  Future<void> setSetting(String key, String value) async {
    await db.into(db.appSettings).insert(
      AppSettingsCompanion.insert(key: key, value: value),
      mode: InsertMode.insertOrReplace,
    );
  }

  Future<bool> getAllowNegativeStock() async {
    final val = await getSetting('allow_negative_stock', defaultValue: 'false');
    return val == 'true';
  }

  Future<void> setAllowNegativeStock(bool allow) async {
    await setSetting('allow_negative_stock', allow ? 'true' : 'false');
  }

  Future<String> getLanguage() async {
    return (await getSetting('language', defaultValue: 'am'))!;
  }

  Future<void> setLanguage(String lang) async {
    await setSetting('language', lang);
  }

  Future<String> getShopName() async {
    return (await getSetting('shop_name', defaultValue: 'Inventory Shop'))!;
  }

  Future<void> setShopName(String name) async {
    await setSetting('shop_name', name.trim());
  }

  Future<bool> isFirstRunCompleted() async {
    final val = await getSetting('first_run_completed', defaultValue: 'false');
    return val == 'true';
  }

  Future<void> setFirstRunCompleted(bool completed) async {
    await setSetting('first_run_completed', completed ? 'true' : 'false');
  }

  Future<bool> isAppLockEnabled() async {
    final val = await getSetting('app_lock_enabled', defaultValue: 'false');
    return val == 'true';
  }

  Future<void> setAppLockEnabled(bool enabled) async {
    await setSetting('app_lock_enabled', enabled ? 'true' : 'false');
  }

  Future<String?> getAppLockPin() async {
    return getSetting('app_lock_pin');
  }

  Future<void> setAppLockPin(String? pin) async {
    if (pin != null) {
      await setSetting('app_lock_pin', pin);
    }
  }
}
