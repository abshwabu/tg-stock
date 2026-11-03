import 'dart:convert';

import 'package:drift/drift.dart';

import '../database/app_database.dart';
import '../utils/quantity_formatter.dart';
import '../utils/uuid_generator.dart';

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
  final Location? location;

  MovementWithProduct({required this.movement, this.product, this.location});

  bool get canUndo {
    final now = DateTime.now().toUtc().millisecondsSinceEpoch;
    return (now - movement.createdAtMs) <= 600000; // 10 minutes (600,000 ms)
  }
}

class InTransitTransfer {
  final String transferId;
  final String productId;
  final String productName;
  final String unit;
  final String fromLocationId;
  final String fromLocationName;
  final String toLocationId;
  final String toLocationName;
  final int qtyMilli;
  final int sentAtMs;
  final String? note;

  InTransitTransfer({
    required this.transferId,
    required this.productId,
    required this.productName,
    required this.unit,
    required this.fromLocationId,
    required this.fromLocationName,
    required this.toLocationId,
    required this.toLocationName,
    required this.qtyMilli,
    required this.sentAtMs,
    this.note,
  });
}

class TransferDiscrepancy {
  final String transferId;
  final String productId;
  final String productName;
  final String unit;
  final String fromLocationId;
  final String fromLocationName;
  final String toLocationId;
  final String toLocationName;
  final int sentQtyMilli;
  final int receivedQtyMilli;
  final int diffMilli; // received - sent
  final int sentAtMs;
  final int receivedAtMs;
  final String? reason;

  TransferDiscrepancy({
    required this.transferId,
    required this.productId,
    required this.productName,
    required this.unit,
    required this.fromLocationId,
    required this.fromLocationName,
    required this.toLocationId,
    required this.toLocationName,
    required this.sentQtyMilli,
    required this.receivedQtyMilli,
    required this.diffMilli,
    required this.sentAtMs,
    required this.receivedAtMs,
    this.reason,
  });
}

class LocationStockItem {
  final String locationId;
  final String locationName;
  final String locationKind;
  final int stockMilli;

  LocationStockItem({
    required this.locationId,
    required this.locationName,
    required this.locationKind,
    required this.stockMilli,
  });
}

class StockByLocationReportData {
  final String productId;
  final String productName;
  final String unit;
  final int totalStockMilli;
  final List<LocationStockItem> locations;

  StockByLocationReportData({
    required this.productId,
    required this.productName,
    required this.unit,
    required this.totalStockMilli,
    required this.locations,
  });
}

class TopProductItem {
  final String productId;
  final String productName;
  final String unit;
  final int totalQtyMilli;
  final int totalRevenueCents;

  TopProductItem({
    required this.productId,
    required this.productName,
    required this.unit,
    required this.totalQtyMilli,
    required this.totalRevenueCents,
  });
}

class SalesReportData {
  final int fromMs;
  final int toMs;
  final int totalRevenueCents;
  final int salesCount;
  final int? estimatedProfitCents;
  final int itemsWithCostCount;
  final List<TopProductItem> topByRevenue;
  final List<TopProductItem> topByQuantity;

  SalesReportData({
    required this.fromMs,
    required this.toMs,
    required this.totalRevenueCents,
    required this.salesCount,
    this.estimatedProfitCents,
    required this.itemsWithCostCount,
    required this.topByRevenue,
    required this.topByQuantity,
  });
}

class StockReportData {
  final int totalProductsCount;
  final int lowStockCount;
  final int outOfStockCount;
  final int totalValueCostCents;
  final int totalValueSellCents;

  StockReportData({
    required this.totalProductsCount,
    required this.lowStockCount,
    required this.outOfStockCount,
    required this.totalValueCostCents,
    required this.totalValueSellCents,
  });
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

  Future<List<ProductWithStock>> listProducts({
    String? query,
    bool includeDeleted = false,
    String? locationId,
  }) async {
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
    final locationsEnabled = await getLocationsEnabled();

    final result = <ProductWithStock>[];
    for (final p in prods) {
      final totalStock = await stockOf(p.id);
      Map<String, int> stockMap = const {};
      int inTransit = 0;

      if (locationsEnabled) {
        stockMap = await stockByLocation(p.id);
        inTransit = await inTransitStockOf(p.id);
      }

      final effectiveStock = locationId != null ? (stockMap[locationId] ?? 0) : totalStock;

      result.add(ProductWithStock(
        product: p,
        stockMilli: effectiveStock,
        stockByLocation: stockMap,
        inTransitMilli: inTransit,
      ));
    }
    return result;
  }

  Stream<List<ProductWithStock>> watchProducts({String? query, String? locationId}) {
    return db.select(db.movements).watch().asyncMap((_) => listProducts(query: query, locationId: locationId));
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
    String? locationId,
  }) async {
    assert(qtyMilli > 0, 'Stock in quantity must be positive');
    final locId = locationId ?? await getActiveLocationId();

    return db.recordMovement(
      productId: productId,
      type: 'in',
      qtyMilli: qtyMilli,
      unitPriceCents: unitPriceCents,
      note: note,
      locationId: locId,
    );
  }

  /// Records a point-of-sale customer purchase with multiple line items.
  Future<List<Movement>> recordSale({
    required List<SaleLineItem> items,
    String? note,
    String? locationId,
  }) async {
    if (items.isEmpty) return [];

    final targetLocId = locationId ?? await getActiveLocationId();
    final allowNegative = await getAllowNegativeStock();

    // 1. Stock check: if negative stock not allowed, verify sufficient inventory
    if (!allowNegative) {
      for (final item in items) {
        final currentStock = await stockOf(item.product.id, locationId: targetLocId);
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
          locationId: targetLocId,
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
    String? locationId,
  }) async {
    final targetLocId = locationId ?? await getActiveLocationId();
    final currentStock = await stockOf(productId, locationId: targetLocId);
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
      locationId: targetLocId,
    );
  }

  /// Undoes own last action within 10 minutes via compensating movement(s).
  /// If the action was part of a transfer (instant or two-step), writes compensating movements for both sides.
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

    // Check if the last movement was part of a transfer
    if (lastMovement.transferId != null && lastMovement.transferId!.isNotEmpty) {
      final transferId = lastMovement.transferId!;
      final transferMovements = await (db.select(db.movements)
            ..where((m) => m.transferId.equals(transferId)))
          .get();

      return db.transaction(() async {
        Movement? firstCompensating;
        for (final m in transferMovements) {
          final compQty = -m.qtyMilli;
          final compType = m.type == 'transfer_in' ? 'transfer_out' : (m.type == 'transfer_out' ? 'transfer_in' : 'adjust');
          final comp = await db.recordMovement(
            productId: m.productId,
            type: compType,
            qtyMilli: compQty,
            unitPriceCents: m.unitPriceCents,
            locationId: m.locationId,
            transferId: UuidGenerator.v7(),
            note: 'Undo: compensating movement for ${m.id} (${m.type} of transfer $transferId)',
          );
          firstCompensating ??= comp;
        }
        return firstCompensating!;
      });
    }

    // Compensating movement: signed opposite quantity
    final compensatingQtyMilli = -lastMovement.qtyMilli;

    return db.recordMovement(
      productId: lastMovement.productId,
      type: 'adjust',
      qtyMilli: compensatingQtyMilli,
      unitPriceCents: lastMovement.unitPriceCents,
      locationId: lastMovement.locationId,
      note: 'Undo: compensating movement for ${lastMovement.id} (${lastMovement.type})',
    );
  }

  // ==========================================
  // TRANSFERS
  // ==========================================

  /// Instant transfer between locations. Writes both transfer_out and transfer_in in one atomic transaction.
  Future<({Movement outMovement, Movement inMovement})> transferInstant({
    required String productId,
    required String fromLocationId,
    required String toLocationId,
    required int qtyMilli,
    int unitPriceCents = 0,
    String? note,
  }) async {
    assert(qtyMilli > 0, 'Transfer quantity must be positive');
    assert(fromLocationId != toLocationId, 'Source and destination locations must be different');

    final allowNegative = await getAllowNegativeStock();
    if (!allowNegative) {
      final currentStock = await stockOf(productId, locationId: fromLocationId);
      if (currentStock < qtyMilli) {
        final prod = await getProduct(productId);
        throw InsufficientStockException(
          productId: productId,
          productName: prod?.name ?? 'Product',
          currentStockMilli: currentStock,
          requestedQtyMilli: qtyMilli,
        );
      }
    }

    final transferId = UuidGenerator.v7();
    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;

    final outNote = jsonEncode({
      'to_location_id': toLocationId,
      'user_note': note,
    });
    final inNote = jsonEncode({
      'from_location_id': fromLocationId,
      'user_note': note,
    });

    return db.transaction(() async {
      final outM = await db.recordMovement(
        productId: productId,
        type: 'transfer_out',
        qtyMilli: -qtyMilli.abs(),
        unitPriceCents: unitPriceCents,
        note: outNote,
        locationId: fromLocationId,
        transferId: transferId,
        createdAtMs: nowMs,
      );

      final inM = await db.recordMovement(
        productId: productId,
        type: 'transfer_in',
        qtyMilli: qtyMilli.abs(),
        unitPriceCents: unitPriceCents,
        note: inNote,
        locationId: toLocationId,
        transferId: transferId,
        createdAtMs: nowMs,
      );

      return (outMovement: outM, inMovement: inM);
    });
  }

  /// Two-step transfer dispatch: records transfer_out and marks transfer as in-transit.
  Future<Movement> transferOut({
    required String productId,
    required String fromLocationId,
    required String toLocationId,
    required int qtyMilli,
    int unitPriceCents = 0,
    String? note,
    String? transferId,
  }) async {
    assert(qtyMilli > 0, 'Transfer quantity must be positive');
    assert(fromLocationId != toLocationId, 'Source and destination locations must be different');

    final allowNegative = await getAllowNegativeStock();
    if (!allowNegative) {
      final currentStock = await stockOf(productId, locationId: fromLocationId);
      if (currentStock < qtyMilli) {
        final prod = await getProduct(productId);
        throw InsufficientStockException(
          productId: productId,
          productName: prod?.name ?? 'Product',
          currentStockMilli: currentStock,
          requestedQtyMilli: qtyMilli,
        );
      }
    }

    final txId = transferId ?? UuidGenerator.v7();
    final outNote = jsonEncode({
      'to_location_id': toLocationId,
      'user_note': note,
    });

    return db.recordMovement(
      productId: productId,
      type: 'transfer_out',
      qtyMilli: -qtyMilli.abs(),
      unitPriceCents: unitPriceCents,
      note: outNote,
      locationId: fromLocationId,
      transferId: txId,
    );
  }

  /// Two-step transfer receipt: records transfer_in with the received quantity.
  /// Never auto-adjusts if received != sent; differences appear as discrepancies.
  Future<Movement> transferIn({
    required String transferId,
    required String productId,
    required String toLocationId,
    required int qtyReceivedMilli,
    int unitPriceCents = 0,
    String? note,
  }) async {
    assert(qtyReceivedMilli >= 0, 'Received quantity must be non-negative');

    final existingOut = await (db.select(db.movements)
          ..where((m) => m.transferId.equals(transferId) & m.type.equals('transfer_out')))
        .getSingleOrNull();

    final fromLocId = existingOut?.locationId;
    final inNote = jsonEncode({
      'from_location_id': fromLocId,
      'user_note': note,
    });

    return db.recordMovement(
      productId: productId,
      type: 'transfer_in',
      qtyMilli: qtyReceivedMilli.abs(),
      unitPriceCents: unitPriceCents,
      note: inNote,
      locationId: toLocationId,
      transferId: transferId,
    );
  }

  /// Returns all transfers currently in transit (transfer_out without matching transfer_in).
  Future<List<InTransitTransfer>> getInTransitTransfers({
    String? toLocationId,
    String? fromLocationId,
  }) async {
    // 1. Get all transfer_out movements
    final outMovements = await (db.select(db.movements)
          ..where((m) => m.type.equals('transfer_out') & m.transferId.isNotNull())
          ..orderBy([(m) => OrderingTerm(expression: m.createdAtMs, mode: OrderingMode.desc)]))
        .get();

    // 2. Get all transfer_in transferIds
    final inRows = await (db.selectOnly(db.movements)
          ..addColumns([db.movements.transferId])
          ..where(db.movements.type.equals('transfer_in') & db.movements.transferId.isNotNull()))
        .get();
    final completedTransferIds = inRows.map((r) => r.read(db.movements.transferId)).whereType<String>().toSet();

    final result = <InTransitTransfer>[];
    for (final out in outMovements) {
      final tId = out.transferId!;
      if (completedTransferIds.contains(tId)) continue;

      String? destLocId;
      String? userNote;
      if (out.note != null && out.note!.isNotEmpty) {
        try {
          final decoded = jsonDecode(out.note!);
          if (decoded is Map) {
            destLocId = decoded['to_location_id']?.toString();
            userNote = decoded['user_note']?.toString();
          }
        } catch (_) {}
      }

      if (toLocationId != null && destLocId != toLocationId) continue;
      if (fromLocationId != null && out.locationId != fromLocationId) continue;

      final prod = await getProduct(out.productId);
      final fromLoc = out.locationId != null ? await getLocation(out.locationId!) : null;
      final toLoc = destLocId != null ? await getLocation(destLocId) : null;

      result.add(InTransitTransfer(
        transferId: tId,
        productId: out.productId,
        productName: prod?.name ?? 'Unknown',
        unit: prod?.unit ?? 'pcs',
        fromLocationId: out.locationId ?? '',
        fromLocationName: fromLoc?.name ?? 'Source',
        toLocationId: destLocId ?? '',
        toLocationName: toLoc?.name ?? 'Destination',
        qtyMilli: out.qtyMilli.abs(),
        sentAtMs: out.createdAtMs,
        note: userNote,
      ));
    }
    return result;
  }

  /// Returns completed transfers where received qty != sent qty.
  Future<List<TransferDiscrepancy>> getDiscrepancies() async {
    final inMovements = await (db.select(db.movements)
          ..where((m) => m.type.equals('transfer_in') & m.transferId.isNotNull())
          ..orderBy([(m) => OrderingTerm(expression: m.createdAtMs, mode: OrderingMode.desc)]))
        .get();

    final result = <TransferDiscrepancy>[];
    for (final inM in inMovements) {
      final tId = inM.transferId!;
      final outM = await (db.select(db.movements)
            ..where((m) => m.transferId.equals(tId) & m.type.equals('transfer_out')))
          .getSingleOrNull();

      if (outM == null) continue;

      final sentQty = outM.qtyMilli.abs();
      final recvQty = inM.qtyMilli.abs();

      if (sentQty != recvQty) {
        final prod = await getProduct(inM.productId);
        final fromLoc = outM.locationId != null ? await getLocation(outM.locationId!) : null;
        final toLoc = inM.locationId != null ? await getLocation(inM.locationId!) : null;

        String? reason;
        if (inM.note != null && inM.note!.isNotEmpty) {
          try {
            final parsed = jsonDecode(inM.note!);
            if (parsed is Map && parsed['user_note'] != null) {
              reason = parsed['user_note'].toString();
            }
          } catch (_) {
            reason = inM.note;
          }
        }

        result.add(TransferDiscrepancy(
          transferId: tId,
          productId: inM.productId,
          productName: prod?.name ?? 'Unknown',
          unit: prod?.unit ?? 'pcs',
          fromLocationId: outM.locationId ?? '',
          fromLocationName: fromLoc?.name ?? 'Source',
          toLocationId: inM.locationId ?? '',
          toLocationName: toLoc?.name ?? 'Destination',
          sentQtyMilli: sentQty,
          receivedQtyMilli: recvQty,
          diffMilli: recvQty - sentQty,
          sentAtMs: outM.createdAtMs,
          receivedAtMs: inM.createdAtMs,
          reason: reason,
        ));
      }
    }
    return result;
  }

  /// Watches all transfers currently in transit.
  Stream<List<InTransitTransfer>> watchInTransitTransfers({
    String? toLocationId,
    String? fromLocationId,
  }) {
    return db.select(db.movements).watch().asyncMap((_) => getInTransitTransfers(
          toLocationId: toLocationId,
          fromLocationId: fromLocationId,
        ));
  }

  // ==========================================
  // STOCK CALCULATION & INVARIANTS
  // ==========================================

  /// Authoritative Stock = SUM(movements.qtyMilli), optionally filtered by location
  Future<int> stockOf(String productId, {String? locationId}) async {
    return db.getStock(productId, locationId: locationId);
  }

  Stream<int> watchStockOf(String productId, {String? locationId}) {
    return db.watchStock(productId, locationId: locationId);
  }

  /// Authoritative Stock by location for a product: returns `Map<locationId, stockMilli>`.
  Future<Map<String, int>> stockByLocation(String productId) async {
    final locations = await listLocations();
    final map = <String, int>{};
    for (final loc in locations) {
      final s = await stockOf(productId, locationId: loc.id);
      map[loc.id] = s;
    }
    return map;
  }

  /// Calculates in-transit stock for a product destined for toLocationId (or any destination).
  Future<int> inTransitStockOf(String productId, {String? toLocationId}) async {
    final transfers = await getInTransitTransfers(toLocationId: toLocationId);
    int sum = 0;
    for (final t in transfers) {
      if (t.productId == productId) {
        sum += t.qtyMilli;
      }
    }
    return sum;
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
  // LOCATIONS & MULTI-LOCATION SETTINGS
  // ==========================================

  Future<bool> getLocationsEnabled() async {
    final val = await getSetting('locations_enabled', defaultValue: 'false');
    return val == 'true';
  }

  Future<void> setLocationsEnabled(bool enabled) async {
    await setSetting('locations_enabled', enabled ? 'true' : 'false');
  }

  Future<bool> getTransferRequiresReceipt() async {
    final val = await getSetting('transfer_requires_receipt', defaultValue: 'false');
    return val == 'true';
  }

  Future<void> setTransferRequiresReceipt(bool required) async {
    await setSetting('transfer_requires_receipt', required ? 'true' : 'false');
  }

  Future<bool> getLocationsPromptDismissed() async {
    final val = await getSetting('locations_prompt_dismissed', defaultValue: 'false');
    return val == 'true';
  }

  Future<void> setLocationsPromptDismissed(bool dismissed) async {
    await setSetting('locations_prompt_dismissed', dismissed ? 'true' : 'false');
  }

  Future<String?> getActiveLocationId() async {
    final val = await getSetting('device_location_id');
    if (val != null) {
      final loc = await getLocation(val);
      if (loc != null && loc.isActive) return loc.id;
    }
    final defLoc = await getDefaultLocation();
    return defLoc.id;
  }

  Future<void> setActiveLocationId(String locationId) async {
    await setSetting('device_location_id', locationId);
  }

  Future<Location> getActiveLocation() async {
    final id = await getActiveLocationId();
    if (id != null) {
      final loc = await getLocation(id);
      if (loc != null && loc.isActive) return loc;
    }
    return getDefaultLocation();
  }

  Future<Location> getDefaultLocation() async {
    var loc = await (db.select(db.locations)
          ..where((l) => l.isDefault.equals(true) & l.isActive.equals(true))
          ..limit(1))
        .getSingleOrNull();
    if (loc != null) return loc;

    loc = await (db.select(db.locations)
          ..where((l) => l.isActive.equals(true))
          ..limit(1))
        .getSingleOrNull();
    if (loc != null) return loc;

    return db.createLocation(name: 'Main', kind: 'store', isDefault: true);
  }

  Future<List<Location>> listLocations({bool includeInactive = false}) async {
    var q = db.select(db.locations);
    if (!includeInactive) {
      q = q..where((l) => l.isActive.equals(true));
    }
    return (q..orderBy([(l) => OrderingTerm(expression: l.name, mode: OrderingMode.asc)])).get();
  }

  Future<Location?> getLocation(String id) async {
    return (db.select(db.locations)..where((l) => l.id.equals(id))).getSingleOrNull();
  }

  Future<Location> createLocation({
    String? id,
    required String name,
    String kind = 'store',
    bool isDefault = false,
  }) async {
    return db.createLocation(id: id, name: name, kind: kind, isDefault: isDefault);
  }

  Future<void> updateLocation(Location location) async {
    await db.updateLocation(location);
  }

  Future<void> deleteLocation(String id) async {
    await db.deleteLocation(id);
  }

  /// Setup flow (shared by first-run, Settings, and Home card):
  /// Creates "Store" and "Warehouse", then reassigns current stock.
  Future<void> enableWarehouseAndStore({required String stockPlacement}) async {
    await db.transaction(() async {
      var store = await (db.select(db.locations)
            ..where((l) => l.name.equals('Store') & l.isActive.equals(true))
            ..limit(1))
          .getSingleOrNull();
      store ??= await db.createLocation(name: 'Store', kind: 'store', isDefault: true);

      var warehouse = await (db.select(db.locations)
            ..where((l) => l.name.equals('Warehouse') & l.isActive.equals(true))
            ..limit(1))
          .getSingleOrNull();
      warehouse ??= await db.createLocation(name: 'Warehouse', kind: 'warehouse', isDefault: false);

      final targetLocId = (stockPlacement == 'warehouse') ? warehouse.id : store.id;

      // Reassign all existing movements inside one transaction
      await db.customStatement(
        'UPDATE movements SET location_id = ?;',
        [targetLocId],
      );

      await setLocationsEnabled(true);
      await setLocationsPromptDismissed(true);
      await setActiveLocationId(store.id);
    });
  }

  Future<({bool canDisable, String? reason})> canDisableLocations() async {
    final inTransit = await getInTransitTransfers();
    if (inTransit.isNotEmpty) {
      return (
        canDisable: false,
        reason: 'There are ${inTransit.length} transfer(s) in transit. Receive or cancel them before disabling.',
      );
    }

    final activeLocations = await listLocations();
    if (activeLocations.length <= 1) {
      return (canDisable: true, reason: null);
    }

    final prods = await (db.select(db.products)..where((p) => p.deletedAtMs.isNull())).get();
    final locationsWithStock = <String>{};

    for (final p in prods) {
      for (final loc in activeLocations) {
        final s = await stockOf(p.id, locationId: loc.id);
        if (s != 0) {
          locationsWithStock.add(loc.id);
        }
      }
    }

    if (locationsWithStock.length > 1) {
      return (
        canDisable: false,
        reason: 'Stock sits in ${locationsWithStock.length} different locations. You must transfer all inventory into one location first.',
      );
    }

    return (canDisable: true, reason: null);
  }

  Future<void> disableWarehouseAndStore() async {
    final check = await canDisableLocations();
    if (!check.canDisable) {
      throw StateError(check.reason ?? 'Cannot disable locations');
    }

    final defLoc = await getDefaultLocation();
    await db.transaction(() async {
      await db.customStatement(
        'UPDATE movements SET location_id = ?;',
        [defLoc.id],
      );
      await setLocationsEnabled(false);
      await setActiveLocationId(defLoc.id);
    });
  }

  Future<List<StockByLocationReportData>> getStockByLocationReport() async {
    final prods = await (db.select(db.products)..where((p) => p.deletedAtMs.isNull())).get();
    final locs = await listLocations();
    final result = <StockByLocationReportData>[];

    for (final p in prods) {
      final locItems = <LocationStockItem>[];
      int total = 0;
      for (final l in locs) {
        final s = await stockOf(p.id, locationId: l.id);
        total += s;
        locItems.add(LocationStockItem(
          locationId: l.id,
          locationName: l.name,
          locationKind: l.kind,
          stockMilli: s,
        ));
      }

      result.add(StockByLocationReportData(
        productId: p.id,
        productName: p.name,
        unit: p.unit,
        totalStockMilli: total,
        locations: locItems,
      ));
    }
    return result;
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

  Future<String> getCurrencyLabel() async {
    return (await getSetting('currency_label', defaultValue: 'ETB'))!;
  }

  Future<void> setCurrencyLabel(String label) async {
    await setSetting('currency_label', label.trim().isEmpty ? 'ETB' : label.trim());
  }

  Future<int> getDefaultLowStockThresholdMilli() async {
    final val = await getSetting('default_low_stock_milli', defaultValue: '5000');
    return int.tryParse(val ?? '5000') ?? 5000;
  }

  Future<void> setDefaultLowStockThresholdMilli(int milli) async {
    await setSetting('default_low_stock_milli', milli.toString());
  }

  Future<bool> isNotificationsEnabled() async {
    final val = await getSetting('notifications_enabled', defaultValue: 'true');
    return val == 'true';
  }

  Future<void> setNotificationsEnabled(bool enabled) async {
    await setSetting('notifications_enabled', enabled ? 'true' : 'false');
  }

  Future<bool> isLowStockNotificationsEnabled() async {
    final val = await getSetting('low_stock_notifications', defaultValue: 'true');
    return val == 'true';
  }

  Future<void> setLowStockNotificationsEnabled(bool enabled) async {
    await setSetting('low_stock_notifications', enabled ? 'true' : 'false');
  }

  Future<bool> isDailySummaryNotificationEnabled() async {
    final val = await getSetting('daily_summary_enabled', defaultValue: 'false');
    return val == 'true';
  }

  Future<void> setDailySummaryNotificationEnabled(bool enabled) async {
    await setSetting('daily_summary_enabled', enabled ? 'true' : 'false');
  }

  Future<String> getDailySummaryTime() async {
    return (await getSetting('daily_summary_time', defaultValue: '20:00'))!;
  }

  Future<void> setDailySummaryTime(String time) async {
    await setSetting('daily_summary_time', time);
  }

  Future<int?> getLastWeeklySnapshotMs() async {
    final val = await getSetting('last_weekly_snapshot_ms');
    return val != null ? int.tryParse(val) : null;
  }

  Future<void> setLastWeeklySnapshotMs(int ms) async {
    await setSetting('last_weekly_snapshot_ms', ms.toString());
  }

  // ==========================================
  // REPORTS & SQL AGGREGATES
  // ==========================================

  /// Computes revenue, sales count, top 5 products, and estimated profit via SQL aggregates.
  Future<SalesReportData> getSalesReport({required int fromMs, required int toMs, String? locationId}) async {
    final locClause = locationId != null ? 'AND m.location_id = ?' : '';
    final sql = '''
      SELECT 
        m.product_id,
        p.name AS product_name,
        p.unit AS product_unit,
        p.cost_price_cents,
        SUM(ABS(m.qty_milli)) AS total_qty_milli,
        SUM((ABS(m.qty_milli) * m.unit_price_cents) / 1000) AS total_revenue_cents,
        SUM(
          CASE WHEN p.cost_price_cents > 0 
            THEN (ABS(m.qty_milli) * (m.unit_price_cents - p.cost_price_cents)) / 1000 
            ELSE 0 
          END
        ) AS total_profit_cents,
        SUM(CASE WHEN p.cost_price_cents > 0 THEN 1 ELSE 0 END) AS cost_item_sales_count,
        COUNT(*) AS sale_item_count
      FROM movements m
      JOIN products p ON m.product_id = p.id
      WHERE m.type = 'sale' AND m.created_at_ms >= ? AND m.created_at_ms <= ? $locClause
      GROUP BY m.product_id, p.name, p.unit, p.cost_price_cents
    ''';

    final vars = <Variable<Object>>[Variable.withInt(fromMs), Variable.withInt(toMs)];
    if (locationId != null) {
      vars.add(Variable.withString(locationId));
    }

    final rows = await db.customSelect(
      sql,
      variables: vars,
      readsFrom: {db.movements, db.products},
    ).get();

    int totalRevenueCents = 0;
    int salesCount = 0;
    int itemsWithCostCount = 0;
    int profitSum = 0;

    final topItems = <TopProductItem>[];

    for (final row in rows) {
      final pid = row.read<String>('product_id');
      final name = row.read<String>('product_name');
      final unit = row.read<String>('product_unit');
      final qty = (row.data['total_qty_milli'] as num?)?.toInt() ?? 0;
      final rev = (row.data['total_revenue_cents'] as num?)?.toInt() ?? 0;
      final profit = (row.data['total_profit_cents'] as num?)?.toInt() ?? 0;
      final costItems = (row.data['cost_item_sales_count'] as num?)?.toInt() ?? 0;
      final count = (row.data['sale_item_count'] as num?)?.toInt() ?? 0;

      totalRevenueCents += rev;
      salesCount += count;
      profitSum += profit;
      itemsWithCostCount += costItems;

      topItems.add(TopProductItem(
        productId: pid,
        productName: name,
        unit: unit,
        totalQtyMilli: qty,
        totalRevenueCents: rev,
      ));
    }

    final topByRevenue = List<TopProductItem>.from(topItems)
      ..sort((a, b) => b.totalRevenueCents.compareTo(a.totalRevenueCents));
    final topByQuantity = List<TopProductItem>.from(topItems)
      ..sort((a, b) => b.totalQtyMilli.compareTo(a.totalQtyMilli));

    return SalesReportData(
      fromMs: fromMs,
      toMs: toMs,
      totalRevenueCents: totalRevenueCents,
      salesCount: salesCount,
      estimatedProfitCents: itemsWithCostCount > 0 ? profitSum : null,
      itemsWithCostCount: itemsWithCostCount,
      topByRevenue: topByRevenue.take(5).toList(),
      topByQuantity: topByQuantity.take(5).toList(),
    );
  }

  /// Computes stock valuation at cost and sell price using SQL aggregates.
  Future<StockReportData> getStockReport({String? locationId}) async {
    final locJoin = locationId != null ? 'AND m.location_id = ?' : '';
    final sql = '''
      SELECT 
        p.id,
        p.name,
        p.unit,
        p.cost_price_cents,
        p.sell_price_cents,
        p.low_stock_at_milli,
        COALESCE(SUM(m.qty_milli), 0) AS stock_milli
      FROM products p
      LEFT JOIN movements m ON p.id = m.product_id $locJoin
      WHERE p.deleted_at_ms IS NULL
      GROUP BY p.id, p.name, p.unit, p.cost_price_cents, p.sell_price_cents, p.low_stock_at_milli
    ''';

    final vars = <Variable>[];
    if (locationId != null) {
      vars.add(Variable.withString(locationId));
    }

    final rows = await db.customSelect(
      sql,
      variables: vars,
      readsFrom: {db.products, db.movements},
    ).get();

    int totalProductsCount = rows.length;
    int lowStockCount = 0;
    int outOfStockCount = 0;
    int totalValueCostCents = 0;
    int totalValueSellCents = 0;

    for (final row in rows) {
      final stock = (row.data['stock_milli'] as num?)?.toInt() ?? 0;
      final costPrice = (row.data['cost_price_cents'] as num?)?.toInt() ?? 0;
      final sellPrice = (row.data['sell_price_cents'] as num?)?.toInt() ?? 0;
      final lowLimit = (row.data['low_stock_at_milli'] as num?)?.toInt();

      if (stock > 0) {
        totalValueCostCents += (stock * costPrice) ~/ 1000;
        totalValueSellCents += (stock * sellPrice) ~/ 1000;
      }

      if (stock <= 0) {
        outOfStockCount++;
      }

      if (lowLimit != null && stock <= lowLimit) {
        lowStockCount++;
      }
    }

    return StockReportData(
      totalProductsCount: totalProductsCount,
      lowStockCount: lowStockCount,
      outOfStockCount: outOfStockCount,
      totalValueCostCents: totalValueCostCents,
      totalValueSellCents: totalValueSellCents,
    );
  }

  Future<List<MovementWithProduct>> getSalesMovementsWithProduct({int? fromMs, int? toMs, String? locationId}) async {
    var q = db.select(db.movements)..where((m) => m.type.equals('sale'));
    if (locationId != null) {
      q = q..where((m) => m.locationId.equals(locationId));
    }
    if (fromMs != null) {
      q = q..where((m) => m.createdAtMs.isBiggerOrEqualValue(fromMs));
    }
    if (toMs != null) {
      q = q..where((m) => m.createdAtMs.isSmallerOrEqualValue(toMs));
    }
    q = q..orderBy([(m) => OrderingTerm(expression: m.createdAtMs, mode: OrderingMode.desc)]);
    final movs = await q.get();

    final result = <MovementWithProduct>[];
    for (final m in movs) {
      final p = await getProduct(m.productId);
      final l = m.locationId != null ? await getLocation(m.locationId!) : null;
      result.add(MovementWithProduct(movement: m, product: p, location: l));
    }
    return result;
  }

  Future<List<MovementWithProduct>> getMovementsWithProduct({int? fromMs, int? toMs, String? locationId}) async {
    var q = db.select(db.movements);
    if (locationId != null) {
      q = q..where((m) => m.locationId.equals(locationId));
    }
    if (fromMs != null) {
      q = q..where((m) => m.createdAtMs.isBiggerOrEqualValue(fromMs));
    }
    if (toMs != null) {
      q = q..where((m) => m.createdAtMs.isSmallerOrEqualValue(toMs));
    }
    q = q..orderBy([(m) => OrderingTerm(expression: m.createdAtMs, mode: OrderingMode.desc)]);
    final movs = await q.get();

    final result = <MovementWithProduct>[];
    for (final m in movs) {
      final p = await getProduct(m.productId);
      final l = m.locationId != null ? await getLocation(m.locationId!) : null;
      result.add(MovementWithProduct(movement: m, product: p, location: l));
    }
    return result;
  }
}
