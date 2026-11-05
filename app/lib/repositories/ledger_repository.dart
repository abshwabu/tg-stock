import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:intl/intl.dart';

import '../database/app_database.dart';
import '../utils/phone_validator.dart';
import '../utils/quantity_formatter.dart';
import '../utils/uuid_generator.dart';

class CustomerCreditProfile {
  final String customerId;
  final String customerName;
  final int totalDebtCents;
  final int overdueCents;
  final int openSalesCount;
  final bool hasOverpayment;
  final int overpaymentCents;
  final int netBalanceCents;
  final String? oldestDueDate;
  final int maxDaysOverdue;

  CustomerCreditProfile({
    required this.customerId,
    required this.customerName,
    required this.totalDebtCents,
    required this.overdueCents,
    required this.openSalesCount,
    required this.hasOverpayment,
    required this.overpaymentCents,
    required this.netBalanceCents,
    this.oldestDueDate,
    required this.maxDaysOverdue,
  });
}

class CustomerAllocationResult {
  final Map<String, int> saleAllocations; // saleId -> allocatedRepaymentCents
  final Map<String, OpenSaleBreakdown> salesBreakdown;
  final int totalUnpaidCents;
  final int totalRepaymentsCents;
  final int netBalanceCents;
  final bool hasOverpayment;
  final int overpaymentCents;

  CustomerAllocationResult({
    required this.saleAllocations,
    required this.salesBreakdown,
    required this.totalUnpaidCents,
    required this.totalRepaymentsCents,
    required this.netBalanceCents,
    required this.hasOverpayment,
    required this.overpaymentCents,
  });
}

class OpenSaleBreakdown {
  final Sale sale;
  final int totalCents;
  final int explicitPaidCents;
  final int allocatedRepaymentCents;
  final int remainingBalanceCents;
  final String? dueDate;
  final bool isOverdue;

  OpenSaleBreakdown({
    required this.sale,
    required this.totalCents,
    required this.explicitPaidCents,
    required this.allocatedRepaymentCents,
    required this.remainingBalanceCents,
    this.dueDate,
    this.isOverdue = false,
  });
}

class OverdueSaleItem {
  final Sale sale;
  final Customer? customer;
  final String dueDate;
  final int daysOverdue;
  final int totalCents;
  final int remainingBalanceCents;

  OverdueSaleItem({
    required this.sale,
    this.customer,
    required this.dueDate,
    required this.daysOverdue,
    required this.totalCents,
    required this.remainingBalanceCents,
  });
}

class SaleWithDetails {
  final Sale sale;
  final Customer? customer;
  final List<MovementWithProduct> items;
  final List<Payment> payments;
  final int remainingBalanceCents;
  final String statusBadge; // 'Paid', 'Partial', 'Dube', 'Voided'
  final String primaryMethod; // 'Cash', 'Bank', 'Wallet', 'Dube', 'Unspecified'

  SaleWithDetails({
    required this.sale,
    this.customer,
    required this.items,
    required this.payments,
    required this.remainingBalanceCents,
    required this.statusBadge,
    required this.primaryMethod,
  });

  bool get canUndo {
    if (sale.voidedAtMs != null) return false;
    final now = DateTime.now().toUtc().millisecondsSinceEpoch;
    return (now - sale.createdAtMs) <= 600000;
  }
}

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

  int get totalCents => ((qtyMilli.abs() * unitPriceCents) / 1000).round();
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

class SalesByKindData {
  final String kind;
  final int totalRevenueCents;
  final int totalQtyMilli;
  final int salesCount;

  SalesByKindData({
    required this.kind,
    required this.totalRevenueCents,
    required this.totalQtyMilli,
    required this.salesCount,
  });
}

class MaterialUsedReportItem {
  final String materialProductId;
  final String productName;
  final String unit;
  final int totalConsumedMilli;
  final int costPriceCents;
  final int totalCostCents;

  MaterialUsedReportItem({
    required this.materialProductId,
    required this.productName,
    required this.unit,
    required this.totalConsumedMilli,
    required this.costPriceCents,
    required this.totalCostCents,
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
    String kind = 'stockable',
    int costPriceCents = 0,
    int sellPriceCents = 0,
    bool askPrice = false,
    int? lowStockAtMilli,
    int? openingStockMilli,
    List<Map<String, dynamic>>? materials,
  }) async {
    if (kind != 'stockable' && openingStockMilli != null && openingStockMilli > 0) {
      throw StateError('Opening stock is not allowed for non-stockable items.');
    }

    final prod = await db.createProduct(
      id: id,
      name: name,
      sku: sku,
      unit: unit,
      kind: kind,
      costPriceCents: costPriceCents,
      sellPriceCents: sellPriceCents,
      askPrice: askPrice,
      lowStockAtMilli: lowStockAtMilli,
      materials: materials,
    );

    if (kind == 'stockable' && openingStockMilli != null && openingStockMilli > 0) {
      await recordStockIn(
        productId: prod.id,
        qtyMilli: openingStockMilli,
        unitPriceCents: costPriceCents,
        note: 'Opening stock',
      );
    }

    return prod;
  }

  Future<void> updateProduct(Product product, {List<Map<String, dynamic>>? materials}) async {
    final existing = await getProduct(product.id);
    if (existing != null) {
      await validateProductKindChange(existing, product.kind);
    }

    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;
    final kindChanged = existing != null && existing.kind != product.kind;
    final updated = kindChanged
        ? product.copyWith(kindChangedAtMs: Value(nowMs))
        : product;

    await db.updateProduct(updated, materials: materials);
  }

  Future<void> deleteProduct(String id) async {
    if (await isUsedAsMaterial(id)) {
      final p = await getProduct(id);
      throw StateError(
        'Cannot delete product "${p?.name ?? id}" because it is used as a raw material in recipes.',
      );
    }
    await db.deleteProduct(id);
  }

  Future<Product?> getProduct(String id) async {
    return (db.select(db.products)..where((p) => p.id.equals(id))).getSingleOrNull();
  }

  Future<List<ProductMaterial>> getProductMaterials(String productId) async {
    return (db.select(db.productMaterials)..where((pm) => pm.productId.equals(productId))).get();
  }

  Future<void> setProductMaterials(String productId, List<Map<String, dynamic>> materials) async {
    await db.transaction(() async {
      await (db.delete(db.productMaterials)..where((pm) => pm.productId.equals(productId))).go();
      for (final m in materials) {
        final matProdId = m['product_id'] as String;
        final qMilli = m['qty_milli'] is int
            ? m['qty_milli'] as int
            : QuantityFormatter.parseQty(m['qty']?.toString() ?? '0');
        await db.into(db.productMaterials).insert(
          ProductMaterialsCompanion.insert(
            productId: productId,
            materialProductId: matProdId,
            qtyMilli: qMilli,
          ),
          mode: InsertMode.insertOrReplace,
        );
      }
    });
  }

  Future<bool> isUsedAsMaterial(String productId) async {
    final list = await (db.select(db.productMaterials)..where((pm) => pm.materialProductId.equals(productId))..limit(1)).get();
    return list.isNotEmpty;
  }

  Future<List<Product>> getProductsUsingMaterial(String materialProductId) async {
    final mats = await (db.select(db.productMaterials)..where((pm) => pm.materialProductId.equals(materialProductId))).get();
    final prods = <Product>[];
    for (final m in mats) {
      final p = await getProduct(m.productId);
      if (p != null) prods.add(p);
    }
    return prods;
  }

  Future<void> validateProductKindChange(Product existing, String newKind) async {
    if (existing.kind == newKind) return;

    if (existing.kind == 'stockable' && newKind != 'stockable') {
      final stock = await stockOf(existing.id);
      if (stock != 0) {
        throw StateError(
          'Cannot change kind from stockable when stock is not zero (current: ${QuantityFormatter.formatDisplay(stock)}). Adjust stock to zero first.',
        );
      }
    }

    if (await isUsedAsMaterial(existing.id)) {
      throw StateError(
        'Cannot change kind of product "${existing.name}" because it is used as a raw material in recipes.',
      );
    }
  }

  Future<List<ProductWithStock>> listProducts({
    String? query,
    bool includeDeleted = false,
    String? locationId,
    String? kindFilter, // 'all', 'product', 'service', 'consumable', 'material'
  }) async {
    var q = db.select(db.products);
    if (!includeDeleted) {
      q = q..where((p) => p.deletedAtMs.isNull());
    }

    if (kindFilter != null && kindFilter != 'all') {
      if (kindFilter == 'product' || kindFilter == 'stockable') {
        q = q..where((p) => p.kind.equals('stockable'));
      } else if (kindFilter == 'service') {
        q = q..where((p) => p.kind.equals('service'));
      } else if (kindFilter == 'consumable') {
        q = q..where((p) => p.kind.equals('consumable'));
      } else if (kindFilter == 'material') {
        final matRows = await (db.selectOnly(db.productMaterials, distinct: true)
              ..addColumns([db.productMaterials.materialProductId]))
            .get();
        final matIds = matRows.map((row) => row.read(db.productMaterials.materialProductId)!).toSet();
        q = q..where((p) => p.id.isIn(matIds) & p.kind.equals('stockable'));
      }
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
      final totalStock = p.kind == 'stockable' ? await stockOf(p.id) : 0;
      Map<String, int> stockMap = const {};
      int inTransit = 0;

      if (locationsEnabled && p.kind == 'stockable') {
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

  Stream<List<ProductWithStock>> watchProducts({String? query, String? locationId, String? kindFilter}) {
    return db.select(db.movements).watch().asyncMap((_) => listProducts(query: query, locationId: locationId, kindFilter: kindFilter));
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
    final prod = await getProduct(productId);
    if (prod != null && prod.kind != 'stockable') {
      throw StateError('not_stockable');
    }

    final locId = locationId ?? await getActiveLocationId();

    return db.recordMovement(
      productId: productId,
      type: 'in',
      qtyMilli: qtyMilli,
      unitPriceCents: unitPriceCents,
      tracksStock: true,
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
    final blockWhenShort = await getBlockSaleWhenMaterialShort();

    // 1. Stock check: stockables check location inventory; consumables check raw materials
    for (final item in items) {
      if (item.product.kind == 'stockable') {
        if (!allowNegative) {
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
      } else if (item.product.kind == 'consumable') {
        final mats = await getProductMaterials(item.product.id);
        for (final mat in mats) {
          final requiredQty = ((mat.qtyMilli * item.qtyMilli) / 1000.0).round();
          final matStock = await stockOf(mat.materialProductId, locationId: targetLocId);
          if (blockWhenShort && matStock < requiredQty) {
            final matProd = await getProduct(mat.materialProductId);
            throw InsufficientStockException(
              productId: mat.materialProductId,
              productName: matProd?.name ?? 'Raw Material',
              currentStockMilli: matStock,
              requestedQtyMilli: requiredQty,
            );
          }
        }
      }
    }

    // 2. Ingest movements within a single atomic database transaction
    final movements = <Movement>[];
    await db.transaction(() async {
      for (final item in items) {
        final isStockable = item.product.kind == 'stockable';
        final mov = await db.recordMovement(
          productId: item.product.id,
          type: 'sale',
          qtyMilli: -item.qtyMilli.abs(), // Strictly negative for sale
          unitPriceCents: item.unitPriceCents,
          tracksStock: isStockable,
          note: note ?? 'Sale',
          locationId: targetLocId,
        );
        movements.add(mov);

        if (item.product.kind == 'consumable') {
          final mats = await getProductMaterials(item.product.id);
          for (final mat in mats) {
            final consumedQty = ((mat.qtyMilli * item.qtyMilli) / 1000.0).round();
            final consumeMov = await db.recordMovement(
              productId: mat.materialProductId,
              type: 'consume',
              qtyMilli: -consumedQty.abs(),
              unitPriceCents: 0,
              tracksStock: true,
              sourceMovementId: mov.id,
              note: 'Consumed for ${item.product.name}',
              locationId: targetLocId,
            );
            movements.add(consumeMov);
          }
        }
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
    final prod = await getProduct(productId);
    if (prod != null && prod.kind != 'stockable') {
      throw StateError('not_stockable');
    }

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

    return db.recordMovement(
      productId: productId,
      type: 'adjust',
      qtyMilli: diffMilli,
      unitPriceCents: prod?.costPriceCents ?? 0,
      tracksStock: true,
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

    final prod = await getProduct(productId);
    if (prod != null && prod.kind != 'stockable') {
      throw StateError('not_stockable');
    }

    final allowNegative = await getAllowNegativeStock();
    if (!allowNegative) {
      final currentStock = await stockOf(productId, locationId: fromLocationId);
      if (currentStock < qtyMilli) {
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
        tracksStock: true,
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
        tracksStock: true,
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

    final prod = await getProduct(productId);
    if (prod != null && prod.kind != 'stockable') {
      throw StateError('not_stockable');
    }

    final allowNegative = await getAllowNegativeStock();
    if (!allowNegative) {
      final currentStock = await stockOf(productId, locationId: fromLocationId);
      if (currentStock < qtyMilli) {
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
      tracksStock: true,
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
    final prod = await getProduct(productId);
    if (prod != null && prod.kind != 'stockable') return 0;

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
    final prods = await (db.select(db.products)..where((p) => p.deletedAtMs.isNull() & p.kind.equals('stockable'))).get();
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
    final prods = await (db.select(db.products)..where((p) => p.deletedAtMs.isNull() & p.kind.equals('stockable'))).get();
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

  Future<bool> getBlockSaleWhenMaterialShort() async {
    final val = await getSetting('block_sale_when_material_short', defaultValue: 'false');
    return val == 'true' || val == '1';
  }

  Future<void> setBlockSaleWhenMaterialShort(bool block) async {
    await setSetting('block_sale_when_material_short', block ? 'true' : 'false');
  }

  Future<String> getThemeMode() async {
    return (await getSetting('theme_mode', defaultValue: 'system'))!;
  }

  Future<void> setThemeMode(String mode) async {
    await setSetting('theme_mode', mode);
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
        COALESCE(p.kind, 'stockable') AS product_kind,
        p.cost_price_cents,
        SUM(ABS(m.qty_milli)) AS total_qty_milli,
        SUM((ABS(m.qty_milli) * m.unit_price_cents) / 1000) AS total_revenue_cents,
        COUNT(*) AS sale_item_count
      FROM movements m
      JOIN products p ON m.product_id = p.id
      WHERE m.type = 'sale' AND m.created_at_ms >= ? AND m.created_at_ms <= ? $locClause
      GROUP BY m.product_id, p.name, p.unit, p.kind, p.cost_price_cents
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
      final kind = row.read<String>('product_kind');
      final costPrice = (row.data['cost_price_cents'] as num?)?.toInt() ?? 0;
      final qty = (row.data['total_qty_milli'] as num?)?.toInt() ?? 0;
      final rev = (row.data['total_revenue_cents'] as num?)?.toInt() ?? 0;
      final count = (row.data['sale_item_count'] as num?)?.toInt() ?? 0;

      totalRevenueCents += rev;
      salesCount += count;

      if (kind == 'stockable') {
        if (costPrice > 0) {
          itemsWithCostCount++;
          profitSum += rev - ((qty * costPrice) / 1000).round();
        }
      } else if (kind == 'service') {
        itemsWithCostCount++;
        profitSum += rev - ((qty * costPrice) / 1000).round();
      }

      topItems.add(TopProductItem(
        productId: pid,
        productName: name,
        unit: unit,
        totalQtyMilli: qty,
        totalRevenueCents: rev,
      ));
    }

    // Consumable profit: consumable revenue minus materials consumed * current material cost
    final consumeSql = '''
      SELECT 
        m.product_id,
        p.cost_price_cents,
        SUM(ABS(m.qty_milli)) AS consumed_qty_milli
      FROM movements m
      JOIN products p ON m.product_id = p.id
      WHERE m.type = 'consume' AND m.created_at_ms >= ? AND m.created_at_ms <= ? $locClause
      GROUP BY m.product_id, p.cost_price_cents
    ''';
    final consumeRows = await db.customSelect(
      consumeSql,
      variables: vars,
      readsFrom: {db.movements, db.products},
    ).get();

    int totalConsumedMaterialCost = 0;
    for (final cr in consumeRows) {
      final cCost = (cr.data['cost_price_cents'] as num?)?.toInt() ?? 0;
      final cQty = (cr.data['consumed_qty_milli'] as num?)?.toInt() ?? 0;
      totalConsumedMaterialCost += ((cQty * cCost) / 1000).round();
    }

    int consumableRev = 0;
    bool hasConsumableSales = false;
    for (final row in rows) {
      final kind = row.read<String>('product_kind');
      if (kind == 'consumable') {
        hasConsumableSales = true;
        consumableRev += (row.data['total_revenue_cents'] as num?)?.toInt() ?? 0;
      }
    }
    if (hasConsumableSales) {
      itemsWithCostCount++;
      profitSum += (consumableRev - totalConsumedMaterialCost);
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

  /// Returns sales breakdown grouped by item kind: stockable, service, consumable.
  Future<Map<String, SalesByKindData>> getSalesByKind({required int fromMs, required int toMs, String? locationId}) async {
    final locClause = locationId != null ? 'AND m.location_id = ?' : '';
    final sql = '''
      SELECT 
        COALESCE(p.kind, 'stockable') AS kind,
        SUM(ABS(m.qty_milli)) AS total_qty_milli,
        SUM((ABS(m.qty_milli) * m.unit_price_cents) / 1000) AS total_revenue_cents,
        COUNT(*) AS sales_count
      FROM movements m
      JOIN products p ON m.product_id = p.id
      WHERE m.type = 'sale' AND m.created_at_ms >= ? AND m.created_at_ms <= ? $locClause
      GROUP BY COALESCE(p.kind, 'stockable')
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

    final result = {
      'stockable': SalesByKindData(kind: 'stockable', totalRevenueCents: 0, totalQtyMilli: 0, salesCount: 0),
      'service': SalesByKindData(kind: 'service', totalRevenueCents: 0, totalQtyMilli: 0, salesCount: 0),
      'consumable': SalesByKindData(kind: 'consumable', totalRevenueCents: 0, totalQtyMilli: 0, salesCount: 0),
    };

    for (final row in rows) {
      final kind = row.read<String>('kind');
      final qty = (row.data['total_qty_milli'] as num?)?.toInt() ?? 0;
      final rev = (row.data['total_revenue_cents'] as num?)?.toInt() ?? 0;
      final count = (row.data['sales_count'] as num?)?.toInt() ?? 0;

      result[kind] = SalesByKindData(
        kind: kind,
        totalRevenueCents: rev,
        totalQtyMilli: qty,
        salesCount: count,
      );
    }

    return result;
  }

  /// Returns materials consumed by consumable sales in the period.
  Future<List<MaterialUsedReportItem>> getMaterialsUsed({required int fromMs, required int toMs, String? locationId}) async {
    final locClause = locationId != null ? 'AND m.location_id = ?' : '';
    final sql = '''
      SELECT 
        m.product_id,
        p.name AS product_name,
        p.unit AS product_unit,
        p.cost_price_cents,
        SUM(ABS(m.qty_milli)) AS total_consumed_milli
      FROM movements m
      JOIN products p ON m.product_id = p.id
      WHERE m.type = 'consume' AND m.created_at_ms >= ? AND m.created_at_ms <= ? $locClause
      GROUP BY m.product_id, p.name, p.unit, p.cost_price_cents
      ORDER BY SUM(ABS(m.qty_milli)) DESC
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

    final result = <MaterialUsedReportItem>[];
    for (final row in rows) {
      final pid = row.read<String>('product_id');
      final name = row.read<String>('product_name');
      final unit = row.read<String>('product_unit');
      final cost = (row.data['cost_price_cents'] as num?)?.toInt() ?? 0;
      final consumed = (row.data['total_consumed_milli'] as num?)?.toInt() ?? 0;
      final totalCost = ((consumed * cost) / 1000).round();

      result.add(MaterialUsedReportItem(
        materialProductId: pid,
        productName: name,
        unit: unit,
        totalConsumedMilli: consumed,
        costPriceCents: cost,
        totalCostCents: totalCost,
      ));
    }
    return result;
  }

  /// Computes stock valuation at cost and sell price using SQL aggregates (strictly stockable products).
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
        COALESCE(SUM(CASE WHEN m.tracks_stock = 1 THEN m.qty_milli ELSE 0 END), 0) AS stock_milli
      FROM products p
      LEFT JOIN movements m ON p.id = m.product_id $locJoin
      WHERE p.deleted_at_ms IS NULL AND p.kind = 'stockable'
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

  // ==========================================
  // PAYMENT METHODS & SETTINGS
  // ==========================================

  Future<List<String>> getPaymentMethodsEnabled() async {
    final entry = await (db.select(db.appSettings)..where((s) => s.key.equals('payment_methods_enabled'))).getSingleOrNull();
    if (entry == null || entry.value.isEmpty) {
      return ['cash', 'bank', 'wallet', 'dube'];
    }
    try {
      final list = jsonDecode(entry.value);
      if (list is List) {
        return list.map((e) => e.toString()).toList();
      }
    } catch (_) {}
    return ['cash', 'bank', 'wallet', 'dube'];
  }

  Future<void> setPaymentMethodsEnabled(List<String> methods) async {
    await (db.into(db.appSettings)).insert(
      AppSetting(key: 'payment_methods_enabled', value: jsonEncode(methods)),
      mode: InsertMode.insertOrReplace,
    );
  }

  Future<int> getDubeDefaultDays() async {
    final entry = await (db.select(db.appSettings)..where((s) => s.key.equals('dube_default_days'))).getSingleOrNull();
    return entry != null ? (int.tryParse(entry.value) ?? 14) : 14;
  }

  Future<void> setDubeDefaultDays(int days) async {
    await (db.into(db.appSettings)).insert(
      AppSetting(key: 'dube_default_days', value: days.toString()),
      mode: InsertMode.insertOrReplace,
    );
  }

  Future<bool> getRequirePaymentReference() async {
    final entry = await (db.select(db.appSettings)..where((s) => s.key.equals('require_payment_reference'))).getSingleOrNull();
    return entry?.value == 'true';
  }

  Future<void> setRequirePaymentReference(bool required) async {
    await (db.into(db.appSettings)).insert(
      AppSetting(key: 'require_payment_reference', value: required ? 'true' : 'false'),
      mode: InsertMode.insertOrReplace,
    );
  }

  Future<String> getDubeReminderTime() async {
    final entry = await (db.select(db.appSettings)..where((s) => s.key.equals('dube_reminder_time'))).getSingleOrNull();
    return entry?.value ?? '09:00';
  }

  Future<void> setDubeReminderTime(String time) async {
    await (db.into(db.appSettings)).insert(
      AppSetting(key: 'dube_reminder_time', value: time),
      mode: InsertMode.insertOrReplace,
    );
  }

  // ==========================================
  // PAYMENT PROVIDERS CATALOG
  // ==========================================

  Future<List<PaymentProvider>> listPaymentProviders({String? kind, bool? enabledOnly}) async {
    var q = db.select(db.paymentProviders);
    if (kind != null) {
      q = q..where((p) => p.kind.equals(kind));
    }
    if (enabledOnly == true) {
      q = q..where((p) => p.enabled.equals(true));
    }
    q = q..orderBy([
      (p) => OrderingTerm(expression: p.favorite, mode: OrderingMode.desc),
      (p) => OrderingTerm(expression: p.providerKey, mode: OrderingMode.asc),
    ]);
    return q.get();
  }

  Future<PaymentProvider> upsertPaymentProvider({
    required String providerKey,
    required String kind,
    String? name,
    bool enabled = true,
    bool favorite = false,
  }) async {
    return db.upsertPaymentProvider(
      providerKey: providerKey,
      kind: kind,
      name: name,
      enabled: enabled,
      favorite: favorite,
    );
  }

  Future<void> setPaymentProviderFavorite(String providerKey, bool favorite) async {
    final existing = await (db.select(db.paymentProviders)..where((p) => p.providerKey.equals(providerKey))).getSingleOrNull();
    if (existing == null) return;
    await upsertPaymentProvider(
      providerKey: existing.providerKey,
      kind: existing.kind,
      name: existing.name,
      enabled: existing.enabled,
      favorite: favorite,
    );
  }

  Future<void> setPaymentProviderEnabled(String providerKey, bool enabled) async {
    final existing = await (db.select(db.paymentProviders)..where((p) => p.providerKey.equals(providerKey))).getSingleOrNull();
    if (existing == null) return;
    await upsertPaymentProvider(
      providerKey: existing.providerKey,
      kind: existing.kind,
      name: existing.name,
      enabled: enabled,
      favorite: existing.favorite,
    );
  }

  // ==========================================
  // CUSTOMERS (Mutable Metadata)
  // ==========================================

  Future<Customer> upsertCustomer({
    String? id,
    required String name,
    String? phone,
    String? note,
  }) async {
    final normPhone = (phone != null && phone.trim().isNotEmpty)
        ? PhoneValidator.normalize(phone.trim())
        : null;

    return db.upsertCustomer(
      id: id,
      name: name.trim(),
      phone: normPhone,
      note: note?.trim(),
    );
  }

  Future<Customer?> getCustomer(String id) async {
    return (db.select(db.customers)..where((c) => c.id.equals(id))).getSingleOrNull();
  }

  Future<Customer?> findCustomerByPhone(String rawPhone) async {
    final norm = PhoneValidator.normalize(rawPhone);
    if (norm == null) return null;
    return (db.select(db.customers)
          ..where((c) => c.phone.equals(norm) & c.deletedAtMs.isNull())
          ..limit(1))
        .getSingleOrNull();
  }

  Future<List<Customer>> listCustomers({String? search, bool includeDeleted = false}) async {
    var q = db.select(db.customers);
    if (!includeDeleted) {
      q = q..where((c) => c.deletedAtMs.isNull());
    }
    if (search != null && search.trim().isNotEmpty) {
      final term = '%${search.trim().toLowerCase()}%';
      q = q..where((c) => c.name.lower().like(term) | c.phone.like(term));
    }
    q = q..orderBy([(c) => OrderingTerm(expression: c.name, mode: OrderingMode.asc)]);
    return q.get();
  }

  Future<void> deleteCustomer(String id) async {
    final balance = await customerBalance(id);
    if (balance != 0) {
      throw StateError('Cannot delete customer with open balance ($balance cents).');
    }
    await db.deleteCustomer(id);
  }

  // ==========================================
  // SALES WITH PAYMENT & DUBE INVARIANT
  // ==========================================

  Future<Sale> recordSaleWithPayment({
    required List<SaleLineItem> items,
    String? note,
    String? locationId,
    String? customerId,
    String? dueDate,
    required String paymentMethod, // 'cash', 'bank', 'wallet', 'dube'
    String? providerKey,
    int? paidAmountCents,
    String? paidMethod,
    String? paidProviderKey,
    String? reference,
    int? createdAtMs,
  }) async {
    if (items.isEmpty) {
      throw ArgumentError('Cart is empty.');
    }

    final targetLocId = locationId ?? await getActiveLocationId();
    final allowNegative = await getAllowNegativeStock();
    final blockWhenShort = await getBlockSaleWhenMaterialShort();

    // 1. Stock check: stockables check location inventory; consumables check raw materials
    for (final item in items) {
      if (item.product.kind == 'stockable') {
        if (!allowNegative) {
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
      } else if (item.product.kind == 'consumable') {
        final mats = await getProductMaterials(item.product.id);
        for (final mat in mats) {
          final requiredQty = ((mat.qtyMilli * item.qtyMilli) / 1000.0).round();
          final matStock = await stockOf(mat.materialProductId, locationId: targetLocId);
          if (blockWhenShort && matStock < requiredQty) {
            final matProd = await getProduct(mat.materialProductId);
            throw InsufficientStockException(
              productId: mat.materialProductId,
              productName: matProd?.name ?? 'Raw Material',
              currentStockMilli: matStock,
              requestedQtyMilli: requiredQty,
            );
          }
        }
      }
    }

    // 2. Recompute total exactly
    int totalCents = 0;
    for (final item in items) {
      totalCents += item.totalCents;
    }

    final nowMs = createdAtMs ?? DateTime.now().toUtc().millisecondsSinceEpoch;

    // 3. Validate Dube constraints
    final isDube = paymentMethod == 'dube';
    final upfrontPaid = isDube ? (paidAmountCents ?? 0) : totalCents;

    if (isDube) {
      if (customerId == null || customerId.trim().isEmpty) {
        throw ArgumentError('Customer is required for Dube sale.');
      }
      if (dueDate == null || dueDate.trim().isEmpty) {
        throw ArgumentError('Due date is required for Dube sale.');
      }
      final todayStr = DateFormat('yyyy-MM-dd').format(DateTime.fromMillisecondsSinceEpoch(nowMs));
      if (dueDate.compareTo(todayStr) < 0) {
        throw ArgumentError('Due date cannot be before the sale date.');
      }
    }

    return db.transaction(() async {
      // A. Create sale header
      final sale = await db.createSale(
        locationId: targetLocId,
        customerId: customerId,
        totalCents: totalCents,
        dueDate: isDube ? dueDate : null,
        note: note,
        legacy: false,
        createdAtMs: nowMs,
      );

      // B. Record movements referencing sale.id
      for (final item in items) {
        final isStockable = item.product.kind == 'stockable';
        final saleMov = await db.recordMovement(
          productId: item.product.id,
          type: 'sale',
          qtyMilli: -item.qtyMilli.abs(),
          unitPriceCents: item.unitPriceCents,
          tracksStock: isStockable,
          note: note ?? 'Sale',
          locationId: targetLocId,
          saleId: sale.id,
          createdAtMs: nowMs,
        );

        if (item.product.kind == 'consumable') {
          final mats = await getProductMaterials(item.product.id);
          for (final mat in mats) {
            final consumedQty = ((mat.qtyMilli * item.qtyMilli) / 1000.0).round();
            await db.recordMovement(
              productId: mat.materialProductId,
              type: 'consume',
              qtyMilli: -consumedQty.abs(),
              unitPriceCents: 0,
              tracksStock: true,
              sourceMovementId: saleMov.id,
              note: 'Consumed for ${item.product.name}',
              locationId: targetLocId,
              saleId: sale.id,
              createdAtMs: nowMs,
            );
          }
        }
      }

      // C. Record payment(s)
      if (!isDube) {
        await db.recordPayment(
          saleId: sale.id,
          customerId: customerId,
          locationId: targetLocId,
          method: paymentMethod,
          providerKey: providerKey,
          amountCents: totalCents,
          reference: reference,
          receivedAtMs: nowMs,
        );
      } else if (upfrontPaid > 0) {
        await db.recordPayment(
          saleId: sale.id,
          customerId: customerId,
          locationId: targetLocId,
          method: paidMethod ?? 'cash',
          providerKey: paidProviderKey,
          amountCents: upfrontPaid,
          reference: reference,
          receivedAtMs: nowMs,
        );
      }

      return sale;
    });
  }

  // ==========================================
  // DUBE FIFO BALANCE & ALLOCATION ENGINE
  // ==========================================

  Future<CustomerAllocationResult> computeCustomerAllocations(String customerId) async {
    // 1. Fetch unvoided sales for customer in chronological FIFO order
    final sales = await (db.select(db.sales)
          ..where((s) => s.customerId.equals(customerId) & s.voidedAtMs.isNull())
          ..orderBy([
            (s) => OrderingTerm(expression: s.createdAtMs, mode: OrderingMode.asc),
            (s) => OrderingTerm(expression: s.id, mode: OrderingMode.asc),
          ]))
        .get();

    // 2. Fetch all repayments for customer (payments with customer_id but NO sale_id)
    final repayments = await (db.select(db.payments)
          ..where((p) => p.customerId.equals(customerId) & p.saleId.isNull()))
        .get();

    final totalRepayments = repayments.fold<int>(0, (sum, p) => sum + p.amountCents);
    int availableRepayments = totalRepayments > 0 ? totalRepayments : 0;

    final saleAllocations = <String, int>{};
    final salesBreakdown = <String, OpenSaleBreakdown>{};
    int totalInitialDebt = 0;

    for (final s in sales) {
      final explicitPayments = await (db.select(db.payments)
            ..where((p) => p.saleId.equals(s.id)))
          .get();
      final explicitPaid = explicitPayments.fold<int>(0, (sum, p) => sum + p.amountCents);

      final initialRemaining = s.legacy ? 0 : (s.totalCents - explicitPaid > 0 ? s.totalCents - explicitPaid : 0);
      totalInitialDebt += initialRemaining;

      final allocated = availableRepayments < initialRemaining ? availableRepayments : initialRemaining;
      availableRepayments -= allocated;
      final remaining = initialRemaining - allocated;

      saleAllocations[s.id] = allocated;
      salesBreakdown[s.id] = OpenSaleBreakdown(
        sale: s,
        totalCents: s.totalCents,
        explicitPaidCents: explicitPaid,
        allocatedRepaymentCents: allocated,
        remainingBalanceCents: remaining,
        dueDate: s.dueDate,
      );
    }

    final overpaymentCents = availableRepayments;
    final hasOverpayment = overpaymentCents > 0;
    final netBalance = totalInitialDebt - totalRepayments;

    return CustomerAllocationResult(
      saleAllocations: saleAllocations,
      salesBreakdown: salesBreakdown,
      totalUnpaidCents: totalInitialDebt,
      totalRepaymentsCents: totalRepayments,
      netBalanceCents: netBalance,
      hasOverpayment: hasOverpayment,
      overpaymentCents: overpaymentCents,
    );
  }

  Future<int> saleBalance(String saleId) async {
    final sale = await (db.select(db.sales)..where((s) => s.id.equals(saleId))).getSingleOrNull();
    if (sale == null || sale.voidedAtMs != null || sale.legacy) {
      return 0;
    }

    final explicitPayments = await (db.select(db.payments)..where((p) => p.saleId.equals(sale.id))).get();
    final explicitPaid = explicitPayments.fold<int>(0, (sum, p) => sum + p.amountCents);

    final initialRemaining = (sale.totalCents - explicitPaid) > 0 ? (sale.totalCents - explicitPaid) : 0;
    if (initialRemaining == 0 || sale.customerId == null || sale.customerId!.isEmpty) {
      return initialRemaining;
    }

    final allocations = await computeCustomerAllocations(sale.customerId!);
    final allocated = allocations.saleAllocations[sale.id] ?? 0;

    final rem = initialRemaining - allocated;
    return rem > 0 ? rem : 0;
  }

  Future<int> customerBalance(String customerId) async {
    final allocations = await computeCustomerAllocations(customerId);
    return allocations.netBalanceCents;
  }

  Future<CustomerCreditProfile> customerCredit(String customerId, {String? asOfDate}) async {
    final customer = await getCustomer(customerId);
    if (customer == null) {
      throw ArgumentError('Customer not found: $customerId');
    }

    final today = asOfDate ?? DateFormat('yyyy-MM-dd').format(DateTime.now());
    final allocations = await computeCustomerAllocations(customerId);

    int totalDebt = 0;
    int overdueCents = 0;
    int openCount = 0;
    String? oldestDueDate;
    int maxDaysOverdue = 0;

    final todayDt = DateTime.parse(today);

    for (final item in allocations.salesBreakdown.values) {
      final rem = item.remainingBalanceCents;
      if (rem > 0) {
        totalDebt += rem;
        openCount++;
        final dueDate = item.sale.dueDate;
        if (dueDate != null && dueDate.isNotEmpty) {
          if (oldestDueDate == null || dueDate.compareTo(oldestDueDate) < 0) {
            oldestDueDate = dueDate;
          }
          if (dueDate.compareTo(today) < 0) {
            overdueCents += rem;
            try {
              final dueDt = DateTime.parse(dueDate);
              final days = todayDt.difference(dueDt).inDays;
              if (days > maxDaysOverdue) {
                maxDaysOverdue = days;
              }
            } catch (_) {}
          }
        }
      }
    }

    return CustomerCreditProfile(
      customerId: customer.id,
      customerName: customer.name,
      totalDebtCents: totalDebt,
      overdueCents: overdueCents,
      openSalesCount: openCount,
      hasOverpayment: allocations.hasOverpayment,
      overpaymentCents: allocations.overpaymentCents,
      netBalanceCents: allocations.netBalanceCents,
      oldestDueDate: oldestDueDate,
      maxDaysOverdue: maxDaysOverdue,
    );
  }

  Future<List<OpenSaleBreakdown>> openSales(String customerId, {String? asOfDate}) async {
    final today = asOfDate ?? DateFormat('yyyy-MM-dd').format(DateTime.now());
    final allocations = await computeCustomerAllocations(customerId);

    final list = <OpenSaleBreakdown>[];
    for (final item in allocations.salesBreakdown.values) {
      if (item.remainingBalanceCents > 0) {
        final isOverdue = item.sale.dueDate != null && item.sale.dueDate!.isNotEmpty && item.sale.dueDate!.compareTo(today) < 0;
        list.add(OpenSaleBreakdown(
          sale: item.sale,
          totalCents: item.totalCents,
          explicitPaidCents: item.explicitPaidCents,
          allocatedRepaymentCents: item.allocatedRepaymentCents,
          remainingBalanceCents: item.remainingBalanceCents,
          dueDate: item.sale.dueDate,
          isOverdue: isOverdue,
        ));
      }
    }
    return list;
  }

  Future<List<OverdueSaleItem>> overdueSales({String? asOfDate}) async {
    final today = asOfDate ?? DateFormat('yyyy-MM-dd').format(DateTime.now());
    final todayDt = DateTime.parse(today);

    final candidateSales = await (db.select(db.sales)
          ..where((s) => s.voidedAtMs.isNull() & s.legacy.equals(false) & s.dueDate.isNotNull() & s.dueDate.isSmallerThanValue(today))
          ..orderBy([(s) => OrderingTerm(expression: s.dueDate, mode: OrderingMode.asc)]))
        .get();

    final result = <OverdueSaleItem>[];

    for (final s in candidateSales) {
      final bal = await saleBalance(s.id);
      if (bal > 0) {
        Customer? cust;
        if (s.customerId != null) {
          cust = await getCustomer(s.customerId!);
        }
        int days = 0;
        try {
          final dueDt = DateTime.parse(s.dueDate!);
          days = todayDt.difference(dueDt).inDays;
        } catch (_) {}

        result.add(OverdueSaleItem(
          sale: s,
          customer: cust,
          dueDate: s.dueDate!,
          daysOverdue: days > 0 ? days : 0,
          totalCents: s.totalCents,
          remainingBalanceCents: bal,
        ));
      }
    }

    result.sort((a, b) => b.daysOverdue.compareTo(a.daysOverdue));
    return result;
  }

  Future<Payment> receiveCustomerPayment({
    required String customerId,
    required int amountCents,
    required String method,
    String? providerKey,
    String? reference,
    String? locationId,
  }) async {
    if (amountCents <= 0) {
      throw ArgumentError('Payment amount must be greater than zero.');
    }
    final targetLocId = locationId ?? await getActiveLocationId();

    return db.recordPayment(
      customerId: customerId,
      saleId: null, // repayment
      locationId: targetLocId,
      method: method,
      providerKey: providerKey,
      amountCents: amountCents,
      reference: reference,
    );
  }

  Future<void> extendSaleDueDate(String saleId, String newDueDate) async {
    await db.updateSaleDueDate(saleId: saleId, dueDate: newDueDate);
  }

  // ==========================================
  // SALE VOID & UNDO
  // ==========================================

  Future<void> undoSale(String saleId) async {
    final sale = await (db.select(db.sales)..where((s) => s.id.equals(saleId))).getSingleOrNull();
    if (sale == null) {
      throw ArgumentError('Sale not found: $saleId');
    }
    if (sale.voidedAtMs != null) {
      throw StateError('Sale is already voided.');
    }

    final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;
    final elapsedMs = nowMs - sale.createdAtMs;
    const tenMinutesMs = 10 * 60 * 1000;

    if (elapsedMs > tenMinutesMs) {
      throw UndoExpiredException(
        'Transaction was performed ${(elapsedMs / 60000).toStringAsFixed(1)} minutes ago. '
        'Sales can only be undone within 10 minutes.',
      );
    }

    final salePayments = await (db.select(db.payments)..where((p) => p.saleId.equals(sale.id))).get();

    return db.transaction(() async {
      // 1. Mark sale voided
      await db.markSaleVoided(saleId: sale.id, voidedAtMs: nowMs);

      // 2. Reverse sale payments
      for (final p in salePayments) {
        if (p.amountCents > 0) {
          await db.recordPayment(
            saleId: sale.id,
            customerId: p.customerId,
            locationId: p.locationId,
            method: p.method,
            providerKey: p.providerKey,
            amountCents: -p.amountCents,
            reference: 'Reversal for [${p.id}]',
            receivedAtMs: nowMs,
          );
        }
      }

      // 3. Reverse sale movements with compensating adjust
      final saleMovements = await (db.select(db.movements)..where((m) => m.saleId.equals(sale.id) & m.type.equals('sale'))).get();
      for (final m in saleMovements) {
        await db.recordMovement(
          productId: m.productId,
          type: 'adjust',
          qtyMilli: m.qtyMilli.abs(), // Positive restores stock if tracksStock
          unitPriceCents: m.unitPriceCents,
          tracksStock: m.tracksStock,
          sourceMovementId: m.id,
          note: 'Compensating undo for [${m.id}] (void sale ${sale.id})',
          locationId: m.locationId,
          saleId: sale.id,
          createdAtMs: nowMs,
        );
      }

      // 4. Reverse consume movements with compensating adjust
      final consumeMovements = await (db.select(db.movements)..where((m) => m.saleId.equals(sale.id) & m.type.equals('consume'))).get();
      for (final m in consumeMovements) {
        await db.recordMovement(
          productId: m.productId,
          type: 'adjust',
          qtyMilli: m.qtyMilli.abs(), // Positive restores consumed raw material stock
          unitPriceCents: m.unitPriceCents,
          tracksStock: true,
          sourceMovementId: m.id,
          note: 'Compensating undo for consume [${m.id}] (void sale ${sale.id})',
          locationId: m.locationId,
          saleId: sale.id,
          createdAtMs: nowMs,
        );
      }
    });
  }

  // ==========================================
  // DASHBOARD & REPORTS METRICS
  // ==========================================

  Future<Map<String, int>> getReceivedTodayByMethod() async {
    final now = DateTime.now();
    final startTodayMs = DateTime(now.year, now.month, now.day).toUtc().millisecondsSinceEpoch;
    final endTodayMs = DateTime(now.year, now.month, now.day, 23, 59, 59, 999).toUtc().millisecondsSinceEpoch;

    final paymentsToday = await (db.select(db.payments)
          ..where((p) => p.receivedAtMs.isBiggerOrEqualValue(startTodayMs) & p.receivedAtMs.isSmallerOrEqualValue(endTodayMs)))
        .get();

    final result = {'cash': 0, 'bank': 0, 'wallet': 0};
    for (final p in paymentsToday) {
      final m = p.method.toLowerCase();
      result[m] = (result[m] ?? 0) + p.amountCents;
    }
    return result;
  }

  Future<Map<String, int>> getReceivedTodayByProvider() async {
    final now = DateTime.now();
    final startTodayMs = DateTime(now.year, now.month, now.day).toUtc().millisecondsSinceEpoch;
    final endTodayMs = DateTime(now.year, now.month, now.day, 23, 59, 59, 999).toUtc().millisecondsSinceEpoch;

    final paymentsToday = await (db.select(db.payments)
          ..where((p) => p.receivedAtMs.isBiggerOrEqualValue(startTodayMs) & p.receivedAtMs.isSmallerOrEqualValue(endTodayMs)))
        .get();

    final result = <String, int>{};
    for (final p in paymentsToday) {
      if (p.providerKey != null && p.providerKey!.isNotEmpty) {
        result[p.providerKey!] = (result[p.providerKey!] ?? 0) + p.amountCents;
      }
    }
    return result;
  }

  Future<int> getCashExpectedInDrawer() async {
    final now = DateTime.now();
    final startTodayMs = DateTime(now.year, now.month, now.day).toUtc().millisecondsSinceEpoch;
    final endTodayMs = DateTime(now.year, now.month, now.day, 23, 59, 59, 999).toUtc().millisecondsSinceEpoch;

    final cashPayments = await (db.select(db.payments)
          ..where((p) => p.method.equals('cash') & p.receivedAtMs.isBiggerOrEqualValue(startTodayMs) & p.receivedAtMs.isSmallerOrEqualValue(endTodayMs)))
        .get();

    return cashPayments.fold<int>(0, (sum, p) => sum + p.amountCents);
  }

  Future<Map<String, int>> getRevenueVsReceivedVsNewDubeToday() async {
    final now = DateTime.now();
    final startTodayMs = DateTime(now.year, now.month, now.day).toUtc().millisecondsSinceEpoch;
    final endTodayMs = DateTime(now.year, now.month, now.day, 23, 59, 59, 999).toUtc().millisecondsSinceEpoch;

    final salesToday = await (db.select(db.sales)
          ..where((s) => s.voidedAtMs.isNull() & s.legacy.equals(false) & s.createdAtMs.isBiggerOrEqualValue(startTodayMs) & s.createdAtMs.isSmallerOrEqualValue(endTodayMs)))
        .get();

    int revenueCents = 0;
    int newDubeCents = 0;

    for (final s in salesToday) {
      revenueCents += s.totalCents;
      final salePayments = await (db.select(db.payments)..where((p) => p.saleId.equals(s.id))).get();
      final explicitPaid = salePayments.fold<int>(0, (sum, p) => sum + p.amountCents);
      final unpaid = s.totalCents - explicitPaid;
      if (unpaid > 0) {
        newDubeCents += unpaid;
      }
    }

    final paymentsToday = await (db.select(db.payments)
          ..where((p) => p.receivedAtMs.isBiggerOrEqualValue(startTodayMs) & p.receivedAtMs.isSmallerOrEqualValue(endTodayMs)))
        .get();
    final moneyReceivedCents = paymentsToday.fold<int>(0, (sum, p) => sum + p.amountCents);

    return {
      'revenue_cents': revenueCents,
      'money_received_cents': moneyReceivedCents,
      'new_dube_cents': newDubeCents,
    };
  }

  Future<int> getOutstandingDubeTotal() async {
    final activeCustomers = await listCustomers();
    int total = 0;
    for (final c in activeCustomers) {
      final bal = await customerBalance(c.id);
      if (bal > 0) {
        total += bal;
      }
    }
    return total;
  }

  Future<int> getOverdueCount() async {
    final overdue = await overdueSales();
    return overdue.length;
  }

  Stream<int> watchOutstandingDubeTotal() {
    return db.select(db.payments).watch().asyncMap((_) => getOutstandingDubeTotal());
  }

  Stream<int> watchOverdueCount() {
    return db.select(db.sales).watch().asyncMap((_) => getOverdueCount());
  }

  Future<List<SaleWithDetails>> listSalesWithDetails({int? limit, int? fromMs, int? toMs, String? locationId}) async {
    var q = db.select(db.sales);
    if (locationId != null) {
      q = q..where((s) => s.locationId.equals(locationId));
    }
    if (fromMs != null) {
      q = q..where((s) => s.createdAtMs.isBiggerOrEqualValue(fromMs));
    }
    if (toMs != null) {
      q = q..where((s) => s.createdAtMs.isSmallerOrEqualValue(toMs));
    }
    q = q..orderBy([(s) => OrderingTerm(expression: s.createdAtMs, mode: OrderingMode.desc)]);
    if (limit != null) {
      q = q..limit(limit);
    }

    final sales = await q.get();
    final result = <SaleWithDetails>[];

    for (final s in sales) {
      Customer? cust;
      if (s.customerId != null) {
        cust = await getCustomer(s.customerId!);
      }
      final payments = await (db.select(db.payments)..where((p) => p.saleId.equals(s.id))).get();
      final movements = await (db.select(db.movements)..where((m) => m.saleId.equals(s.id))).get();

      final movWithProds = <MovementWithProduct>[];
      for (final m in movements) {
        final p = await getProduct(m.productId);
        final loc = m.locationId != null ? await getLocation(m.locationId!) : null;
        movWithProds.add(MovementWithProduct(movement: m, product: p, location: loc));
      }

      final bal = await saleBalance(s.id);

      String statusBadge;
      String primaryMethod = 'Cash';

      if (s.voidedAtMs != null) {
        statusBadge = 'Voided';
      } else if (s.legacy) {
        statusBadge = 'Paid';
        primaryMethod = 'Unspecified';
      } else if (bal == 0) {
        statusBadge = 'Paid';
        if (payments.isNotEmpty) {
          primaryMethod = payments.first.method;
        }
      } else {
        final explicitPaid = payments.fold<int>(0, (sum, p) => sum + p.amountCents);
        if (explicitPaid > 0) {
          statusBadge = 'Partial';
          primaryMethod = 'Dube';
        } else {
          statusBadge = 'Dube';
          primaryMethod = 'Dube';
        }
      }

      result.add(SaleWithDetails(
        sale: s,
        customer: cust,
        items: movWithProds,
        payments: payments,
        remainingBalanceCents: bal,
        statusBadge: statusBadge,
        primaryMethod: primaryMethod,
      ));
    }

    return result;
  }
}
