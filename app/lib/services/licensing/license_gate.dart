import 'package:flutter/foundation.dart';

import '../../database/app_database.dart';
import 'clock_service.dart';
import 'install_id_service.dart';
import 'license_models.dart';
import 'license_storage.dart';
import 'license_verifier.dart';

class LicenseException implements Exception {
  final String message;
  final LicenseState? state;

  LicenseException(this.message, {this.state});

  @override
  String toString() => message;
}

class LicenseReadOnlyException extends LicenseException {
  LicenseReadOnlyException(super.message, {super.state = LicenseState.expired});
}

class LicenseLimitException extends LicenseException {
  LicenseLimitException(super.message, {super.state = LicenseState.unlicensed});
}

class LicenseGate {
  final LicenseStorage _storage;
  final ClockService _clockService;
  final InstallIdService _installIdService;
  final AppDatabase _db;

  // Configurable free limits for unlicensed mode
  static const int defaultMaxFreeProducts = 30;
  static const int defaultMaxFreeSales = 50;

  final ValueNotifier<LicenseVerificationResult> currentResultNotifier;

  LicenseGate({
    required LicenseStorage storage,
    required ClockService clockService,
    required InstallIdService installIdService,
    required AppDatabase db,
  })  : _storage = storage,
        _clockService = clockService,
        _installIdService = installIdService,
        _db = db,
        currentResultNotifier = ValueNotifier(
          const LicenseVerificationResult(
            state: LicenseState.unlicensed,
            isValid: false,
            isClockSuspect: false,
            effectiveToday: 0,
          ),
        );

  LicenseVerificationResult get currentResult => currentResultNotifier.value;
  LicenseState get state => currentResult.state;
  bool get isClockSuspect => _clockService.isClockSuspect;
  bool get isReadOnly => !state.allowsWrites;

  /// Re-evaluate license state from storage and device environment.
  Future<LicenseVerificationResult> reevaluate({String? tokenOverride}) async {
    await _clockService.advanceFromClock();

    final token = tokenOverride ?? await _storage.getLicenseToken();
    if (token == null || token.trim().isEmpty) {
      final res = LicenseVerificationResult(
        state: LicenseState.unlicensed,
        isValid: false,
        isClockSuspect: _clockService.isClockSuspect,
        effectiveToday: _clockService.effectiveToday,
        reason: 'No license token activated',
      );
      currentResultNotifier.value = res;
      return res;
    }

    // Determine expected bind:
    // If shop UUID exists (paired device), try shop-bound first.
    // If device token, bind against installId.
    final pairedShopUuid = await _storage.getPairedShopUuid();
    final deviceBind = await _installIdService.getBindBytes();
    final revocations = await _storage.getRevocationList();

    LicenseVerificationResult result;

    // Check if token specifies shop-bound or device-bound by inspecting first bytes
    // Or verify against device bind first, then shop bind if paired.
    result = await LicenseVerifier.verify(
      tokenString: token,
      expectedType: 1, // device
      expectedBind: deviceBind,
      today: _clockService.today,
      highWater: _clockService.highWater,
      revocationList: revocations,
    );

    // If device bind failed and we have a paired shop UUID, verify as shop-bound
    if (result.state == LicenseState.wrongBind && pairedShopUuid != null && pairedShopUuid.isNotEmpty) {
      final shopBind = await InstallIdService.computeShopBindBytes(pairedShopUuid);
      final shopResult = await LicenseVerifier.verify(
        tokenString: token,
        expectedType: 2, // shop
        expectedBind: shopBind,
        today: _clockService.today,
        highWater: _clockService.highWater,
        revocationList: revocations,
      );
      if (shopResult.isValid ||
          shopResult.state == LicenseState.expired ||
          shopResult.state == LicenseState.grace ||
          shopResult.state == LicenseState.expiring) {
        result = shopResult;
      }
    }

    if (result.isValid && result.payload != null) {
      await _clockService.advanceFromTokenIssuedDay(result.payload!.issuedDay);
    }

    currentResultNotifier.value = result;
    return result;
  }

  /// Activate a new license token string.
  Future<LicenseVerificationResult> activateToken(String tokenString, {String? shopUuid}) async {
    if (shopUuid != null && shopUuid.trim().isNotEmpty) {
      await _storage.savePairedShopUuid(shopUuid.trim());
    }
    final result = await reevaluate(tokenOverride: tokenString);
    if (result.isValid || result.state == LicenseState.grace || result.state == LicenseState.expiring) {
      await _storage.saveLicenseToken(tokenString.trim());
    }
    return result;
  }

  /// Check whether an optional feature is permitted under the active license.
  bool canUseFeature(String featureName) {
    if (state == LicenseState.active || state == LicenseState.expiring || state == LicenseState.grace) {
      return currentResult.hasFeature(featureName);
    }
    // Unlicensed mode: basic offline inventory operations
    if (featureName == 'online_sync' || featureName == 'telegram_bot' || featureName == 'multi_location') {
      return false;
    }
    return true; // Export / basic features allowed offline
  }

  /// Get current count of active products and total sales for free limit checking.
  Future<FreeLimitsStatus> getFreeLimitsStatus() async {
    int productCount = 0;
    int saleCount = 0;
    try {
      final pList = await _db.select(_db.products).get();
      productCount = pList.where((p) => p.isActive && p.deletedAtMs == null).length;
    } catch (_) {}

    try {
      final sList = await _db.select(_db.sales).get();
      final allMovements = await _db.select(_db.movements).get();
      final standaloneSales = allMovements.where((m) => m.type == 'sale' && m.saleId == null).length;
      saleCount = sList.length + standaloneSales;
    } catch (_) {}

    return FreeLimitsStatus(
      currentProducts: productCount,
      maxProducts: defaultMaxFreeProducts,
      currentSales: saleCount,
      maxSales: defaultMaxFreeSales,
    );
  }

  /// Assert that a product may be created or edited.
  Future<void> assertCanWriteProduct({bool isNew = true}) async {
    _assertNotExpired('Product management blocked in Read-Only mode');

    if (state == LicenseState.unlicensed && isNew) {
      final limits = await getFreeLimitsStatus();
      if (limits.isProductLimitReached) {
        throw LicenseLimitException(
          'Free starter limit of ${limits.maxProducts} products reached. Please activate a license to add more products.',
        );
      }
    }
  }

  /// Assert that a checkout / sale may be recorded.
  Future<void> assertCanWriteSale() async {
    _assertNotExpired('Sale completion blocked in Read-Only mode');

    if (state == LicenseState.unlicensed) {
      final limits = await getFreeLimitsStatus();
      if (limits.isSaleLimitReached) {
        throw LicenseLimitException(
          'Free starter limit of ${limits.maxSales} sales reached. Please activate a license to record more sales.',
        );
      }
    }
  }

  /// Assert that inventory stock mutations (stock-in, adjustment, transfer) may occur.
  void assertCanMutateStock() {
    _assertNotExpired('Stock adjustments and inventory mutations are blocked in Read-Only mode');
  }

  /// Assert that customer credit repayments may be recorded.
  void assertCanRecordPayment() {
    _assertNotExpired('Customer payment recordings are blocked in Read-Only mode');
  }

  void _assertNotExpired(String baseMessage) {
    if (state == LicenseState.expired) {
      throw LicenseReadOnlyException('$baseMessage. Terminal is operating in Read-Only mode. Please renew your license.');
    }
    if (state == LicenseState.revoked) {
      throw LicenseReadOnlyException('$baseMessage. License has been administratively revoked.');
    }
    if (state == LicenseState.notYetValid) {
      throw LicenseReadOnlyException('$baseMessage. License is not yet valid. Check device date.');
    }
    if (state == LicenseState.unsupportedVersion) {
      throw LicenseReadOnlyException('$baseMessage. App update required to use this license.');
    }
    if (state == LicenseState.wrongBind || state == LicenseState.invalidSignature) {
      throw LicenseReadOnlyException('$baseMessage. Invalid or unauthenticated license.');
    }
  }
}
