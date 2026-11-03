import 'dart:async';
import 'dart:convert';
import 'dart:math';

import 'package:drift/drift.dart';
import 'package:flutter/foundation.dart';

import '../database/app_database.dart';
import '../utils/quantity_formatter.dart';
import 'api_client.dart';
import 'auth_storage.dart';

enum SyncStatus {
  offline,
  syncing,
  idle, // Up to date
  error,
  unauthorized, // 401: Token revoked, re-pairing needed
}

class SyncEngineState {
  final SyncStatus status;
  final DateTime? lastSyncedAt;
  final int pendingCount;
  final String? errorMessage;
  final String? progressText;
  final bool hasNegativeStock;

  const SyncEngineState({
    required this.status,
    this.lastSyncedAt,
    this.pendingCount = 0,
    this.errorMessage,
    this.progressText,
    this.hasNegativeStock = false,
  });

  SyncEngineState copyWith({
    SyncStatus? status,
    DateTime? lastSyncedAt,
    int? pendingCount,
    String? errorMessage,
    String? progressText,
    bool? hasNegativeStock,
  }) {
    return SyncEngineState(
      status: status ?? this.status,
      lastSyncedAt: lastSyncedAt ?? this.lastSyncedAt,
      pendingCount: pendingCount ?? this.pendingCount,
      errorMessage: errorMessage ?? this.errorMessage,
      progressText: progressText ?? this.progressText,
      hasNegativeStock: hasNegativeStock ?? this.hasNegativeStock,
    );
  }
}

class SyncEngine {
  final AppDatabase db;
  final ApiClient api;
  final AuthStorage storage;

  final ValueNotifier<SyncEngineState> stateNotifier;

  Timer? _debounceTimer;
  Timer? _periodicTimer;
  bool _isSyncing = false;
  int _consecutiveFailures = 0;
  DateTime? _backoffUntil;

  static const int maxBatchSize = 200;
  static const int serverPageSize = 300;

  SyncEngine({
    required this.db,
    required this.api,
    required this.storage,
  }) : stateNotifier = ValueNotifier(const SyncEngineState(status: SyncStatus.offline)) {
    _init();
  }

  SyncEngineState get state => stateNotifier.value;

  Future<void> _init() async {
    final isOnline = await storage.isOnlineEnabled();
    final token = await storage.getDeviceToken();
    final lastSynced = await db.getLastSyncedAt();
    final pending = await db.countPendingOutbox();
    final negStock = await db.hasNegativeStock();

    stateNotifier.value = SyncEngineState(
      status: (isOnline && token != null) ? SyncStatus.idle : SyncStatus.offline,
      lastSyncedAt: lastSynced,
      pendingCount: pending,
      hasNegativeStock: negStock,
    );

    if (isOnline && token != null) {
      startPeriodicSync();
    }
  }

  void startPeriodicSync() {
    _periodicTimer?.cancel();
    _periodicTimer = Timer.periodic(const Duration(seconds: 60), (_) {
      triggerSync(reason: 'periodic');
    });
  }

  void stopPeriodicSync() {
    _periodicTimer?.cancel();
    _periodicTimer = null;
  }

  /// Debounced sync trigger invoked after local database mutations.
  void notifyLocalMutation() {
    _debounceTimer?.cancel();
    _debounceTimer = Timer(const Duration(seconds: 2), () {
      triggerSync(reason: 'local_mutation');
    });
    _updatePendingAndStockState();
  }

  /// App lifecycle trigger (on app resume).
  void onAppResume() {
    triggerSync(reason: 'app_resume');
  }

  /// Manual "Sync now" button trigger.
  Future<void> syncNow() async {
    _backoffUntil = null; // Clear backoff for manual user request
    await triggerSync(reason: 'manual_user');
  }

  /// Core sync execution loop with full idempotency, batching, and error handling.
  Future<void> triggerSync({String reason = 'unknown'}) async {
    if (_isSyncing) return;

    final isOnline = await storage.isOnlineEnabled();
    final token = await storage.getDeviceToken();
    if (!isOnline || token == null) {
      stateNotifier.value = state.copyWith(status: SyncStatus.offline);
      return;
    }

    // Check exponential backoff timer
    if (_backoffUntil != null && DateTime.now().isBefore(_backoffUntil!)) {
      return;
    }

    _isSyncing = true;
    stateNotifier.value = state.copyWith(
      status: SyncStatus.syncing,
      errorMessage: null,
      progressText: 'Syncing with server...',
    );

    try {
      final serverUrl = await storage.getServerUrl();
      bool hasMoreServerChanges = true;
      bool hasMoreLocalChanges = true;

      while (hasMoreServerChanges || hasMoreLocalChanges) {
        // 1. Fetch next batch of uncommitted outbox changes (up to 200)
        final outboxBatch = await db.getPendingOutboxBatch(limit: maxBatchSize);
        hasMoreLocalChanges = outboxBatch.length >= maxBatchSize;

        final wireChanges = <Map<String, dynamic>>[];
        final outboxIdMap = <String, String>{}; // entityId -> outboxId

        for (final item in outboxBatch) {
          final data = jsonDecode(item.payloadJson) as Map<String, dynamic>;
          wireChanges.add({
            'id': item.entityId,
            'entity': item.entity,
            'op': item.op,
            'data': data,
            'client_ts': item.clientTs,
          });
          outboxIdMap[item.entityId] = item.id;
        }

        // 2. Fetch current high-water mark cursor
        final currentCursor = await db.getServerCursor();

        // 3. Dispatch POST /v1/sync
        final response = await api.sync(
          serverUrl: serverUrl,
          deviceToken: token,
          cursor: currentCursor,
          changes: wireChanges,
        );

        // 4. Ingest accepted / rejected in a single local transaction
        await db.transaction(() async {
          // A. Mark accepted outbox entries
          final acceptedOutboxIds = <String>[];
          for (final entityId in response.accepted) {
            final outboxId = outboxIdMap[entityId];
            if (outboxId != null) {
              acceptedOutboxIds.add(outboxId);
            }
          }
          await db.markOutboxAccepted(acceptedOutboxIds);

          // B. Record rejected entries with server error
          final rejectedOutboxList = <Map<String, String>>[];
          for (final r in response.rejected) {
            final entityId = r['id'];
            final reason = r['reason'] ?? 'Rejected';
            final outboxId = outboxIdMap[entityId];
            if (outboxId != null) {
              rejectedOutboxList.add({'id': outboxId, 'reason': reason});
            }
          }
          await db.markOutboxRejected(rejectedOutboxList);

          // C. Apply incoming server changes from other devices/bot
          for (final change in response.changes) {
            await _applyIncomingChange(change);
          }

          // D. Store updated cursor and last sync timestamp
          await db.setServerCursor(response.cursor);
          await db.setLastSyncedAt(DateTime.now());
        });

        // Check if server has more pages (page size 300)
        hasMoreServerChanges = response.changes.length >= serverPageSize;

        final pendingCount = await db.countPendingOutbox();
        final negStock = await db.hasNegativeStock();

        stateNotifier.value = state.copyWith(
          pendingCount: pendingCount,
          hasNegativeStock: negStock,
          lastSyncedAt: DateTime.now(),
        );
      }

      // Sync successful: reset backoff
      _consecutiveFailures = 0;
      _backoffUntil = null;

      final finalPending = await db.countPendingOutbox();
      final finalNegStock = await db.hasNegativeStock();

      stateNotifier.value = state.copyWith(
        status: SyncStatus.idle,
        pendingCount: finalPending,
        hasNegativeStock: finalNegStock,
        progressText: null,
      );
    } on UnauthorizedException {
      // 401: Token revoked or invalid
      _consecutiveFailures = 0;
      _backoffUntil = null;
      stopPeriodicSync();
      stateNotifier.value = state.copyWith(
        status: SyncStatus.unauthorized,
        errorMessage: 'Device token revoked or unauthorized. Please re-pair your device.',
        progressText: null,
      );
    } on RateLimitedException catch (e) {
      _backoffUntil = DateTime.now().add(Duration(seconds: e.retryAfterSeconds));
      stateNotifier.value = state.copyWith(
        status: SyncStatus.error,
        errorMessage: 'Rate limited. Pausing for ${e.retryAfterSeconds}s.',
        progressText: null,
      );
    } catch (e) {
      _applyExponentialBackoff();
      stateNotifier.value = state.copyWith(
        status: SyncStatus.error,
        errorMessage: 'Sync failed: $e',
        progressText: null,
      );
    } finally {
      _isSyncing = false;
      await _updatePendingAndStockState();
    }
  }

  /// First sync flow: seeds all existing offline history into the outbox
  /// and runs sync with interactive progress tracking.
  Future<void> runInitialHistorySync({void Function(int current, int total)? onProgress}) async {
    stateNotifier.value = state.copyWith(
      status: SyncStatus.syncing,
      progressText: 'Preparing offline data for cloud sync...',
    );

    // 1. Seed existing offline products and movements to outbox
    await db.seedOutboxFromHistory();
    final total = await db.countPendingOutbox();

    onProgress?.call(0, total);

    stateNotifier.value = state.copyWith(
      pendingCount: total,
      progressText: 'Uploading history: 0 of $total records...',
    );

    // 2. Run sync loop until outbox is empty
    await triggerSync(reason: 'initial_history_sync');

    final remaining = await db.countPendingOutbox();
    onProgress?.call(total - remaining, total);

    stateNotifier.value = state.copyWith(
      status: SyncStatus.idle,
      pendingCount: remaining,
      progressText: null,
    );
  }

  /// Applies a single incoming change from the server.
  /// Movements: insert or ignore (append-only ledger).
  /// Products: Last-Write-Wins (LWW) by updated_at.
  Future<void> _applyIncomingChange(Map<String, dynamic> change) async {
    final entity = change['entity'] as String?;
    final op = change['op'] as String? ?? 'upsert';
    final data = change['data'] as Map<String, dynamic>?;

    if (data == null) return;

    if (entity == 'movement') {
      final movId = (data['id'] ?? change['id']) as String;
      final prodId = data['product_id'] as String;
      final type = data['type'] as String? ?? 'in';
      final qtyStr = data['qty']?.toString() ?? '0.000';
      final qtyMilli = QuantityFormatter.parseQty(qtyStr);
      final unitPrice = data['unit_price_cents'] is int
          ? data['unit_price_cents'] as int
          : int.tryParse(data['unit_price_cents']?.toString() ?? '0') ?? 0;
      final note = data['note'] as String?;
      final createdAt = data['created_at'] is int
          ? data['created_at'] as int
          : int.tryParse(data['created_at']?.toString() ?? '0') ?? DateTime.now().millisecondsSinceEpoch;
      final locId = data['location_id'] as String?;
      final transferId = data['transfer_id'] as String?;

      final movement = Movement(
        id: movId,
        productId: prodId,
        type: type,
        qtyMilli: qtyMilli,
        unitPriceCents: unitPrice,
        note: note,
        createdAtMs: createdAt,
        locationId: locId,
        transferId: transferId,
      );

      // Append-only: insert or ignore duplicate IDs
      await db.into(db.movements).insert(movement, mode: InsertMode.insertOrIgnore);
      return;
    }

    if (entity == 'location') {
      final locId = (data['id'] ?? change['id']) as String;
      final incomingUpdatedAt = data['updated_at'] is int
          ? data['updated_at'] as int
          : int.tryParse(data['updated_at']?.toString() ?? '0') ?? 0;

      final existing = await (db.select(db.locations)..where((l) => l.id.equals(locId))).getSingleOrNull();

      if (existing != null && existing.updatedAtMs > incomingUpdatedAt) {
        return;
      }

      final isDelete = op == 'delete';
      final name = data['name']?.toString() ?? (existing?.name ?? 'Location');
      final kind = data['kind']?.toString() ?? (existing?.kind ?? 'store');
      final isDefault = data['is_default'] == true || data['is_default'] == 1;
      final isActive = isDelete ? false : (data['is_active'] == true || data['is_active'] == 1);

      final location = Location(
        id: locId,
        name: name,
        kind: kind,
        isDefault: isDefault,
        isActive: isActive,
        updatedAtMs: incomingUpdatedAt,
      );

      await db.into(db.locations).insert(location, mode: InsertMode.insertOrReplace);
      return;
    }

    if (entity == 'product') {
      final prodId = (data['id'] ?? change['id']) as String;
      final incomingUpdatedAt = data['updated_at'] is int
          ? data['updated_at'] as int
          : int.tryParse(data['updated_at']?.toString() ?? '0') ?? 0;

      final existing = await (db.select(db.products)..where((p) => p.id.equals(prodId))).getSingleOrNull();

      // Last-Write-Wins: only overwrite if incoming updated_at is newer or equal
      if (existing != null && existing.updatedAtMs > incomingUpdatedAt) {
        // Local is strictly newer: retain local version
        return;
      }

      final isDelete = op == 'delete' || data['deleted_at'] != null;
      final name = data['name']?.toString() ?? (existing?.name ?? 'Product');
      final sku = data['sku']?.toString();
      final unit = data['unit']?.toString() ?? 'pcs';
      final costPrice = data['cost_price_cents'] is int
          ? data['cost_price_cents'] as int
          : int.tryParse(data['cost_price_cents']?.toString() ?? '0') ?? 0;
      final sellPrice = data['sell_price_cents'] is int
          ? data['sell_price_cents'] as int
          : int.tryParse(data['sell_price_cents']?.toString() ?? '0') ?? 0;
      final lowStockStr = data['low_stock_at']?.toString();
      final lowStockMilli = lowStockStr != null ? QuantityFormatter.parseQty(lowStockStr) : null;
      final isActive = isDelete ? false : (data['is_active'] == true || data['is_active'] == 1);

      final product = Product(
        id: prodId,
        name: name,
        sku: sku,
        unit: unit,
        costPriceCents: costPrice,
        sellPriceCents: sellPrice,
        lowStockAtMilli: lowStockMilli,
        isActive: isActive,
        updatedAtMs: incomingUpdatedAt,
        deletedAtMs: isDelete ? incomingUpdatedAt : null,
      );

      await db.into(db.products).insert(product, mode: InsertMode.insertOrReplace);
    }
  }

  /// Calculates exponential backoff with random jitter.
  void _applyExponentialBackoff() {
    _consecutiveFailures++;
    final baseSeconds = min(60, pow(2, min(_consecutiveFailures, 6)).toInt());
    // Jitter: ±25%
    final randomJitter = (Random().nextDouble() * 0.5) - 0.25;
    final delaySeconds = max(2, (baseSeconds * (1 + randomJitter)).round());

    _backoffUntil = DateTime.now().add(Duration(seconds: delaySeconds));
  }

  Future<void> _updatePendingAndStockState() async {
    final pending = await db.countPendingOutbox();
    final negStock = await db.hasNegativeStock();
    stateNotifier.value = state.copyWith(
      pendingCount: pending,
      hasNegativeStock: negStock,
    );
  }

  void dispose() {
    _debounceTimer?.cancel();
    _periodicTimer?.cancel();
  }
}
