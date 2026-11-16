import 'dart:typed_data';

enum LicenseState {
  active,
  expiring,
  grace,
  expired,
  notYetValid,
  wrongBind,
  invalidSignature,
  unsupportedVersion,
  revoked,
  unlicensed,
}

extension LicenseStateExtension on LicenseState {
  String get wireName {
    switch (this) {
      case LicenseState.active:
        return 'ACTIVE';
      case LicenseState.expiring:
        return 'EXPIRING';
      case LicenseState.grace:
        return 'GRACE';
      case LicenseState.expired:
        return 'EXPIRED';
      case LicenseState.notYetValid:
        return 'NOT_YET_VALID';
      case LicenseState.wrongBind:
        return 'WRONG_BIND';
      case LicenseState.invalidSignature:
        return 'INVALID_SIGNATURE';
      case LicenseState.unsupportedVersion:
        return 'UNSUPPORTED_VERSION';
      case LicenseState.revoked:
        return 'REVOKED';
      case LicenseState.unlicensed:
        return 'UNLICENSED';
    }
  }

  static LicenseState fromWireName(String name) {
    switch (name.toUpperCase()) {
      case 'ACTIVE':
        return LicenseState.active;
      case 'EXPIRING':
        return LicenseState.expiring;
      case 'GRACE':
        return LicenseState.grace;
      case 'EXPIRED':
        return LicenseState.expired;
      case 'NOT_YET_VALID':
        return LicenseState.notYetValid;
      case 'WRONG_BIND':
        return LicenseState.wrongBind;
      case 'INVALID_SIGNATURE':
        return LicenseState.invalidSignature;
      case 'UNSUPPORTED_VERSION':
        return LicenseState.unsupportedVersion;
      case 'REVOKED':
        return LicenseState.revoked;
      case 'UNLICENSED':
      default:
        return LicenseState.unlicensed;
    }
  }

  /// Whether business data writes (sales, stock in, adjustments, transfers, product edits) are allowed.
  bool get allowsWrites {
    switch (this) {
      case LicenseState.active:
      case LicenseState.expiring:
      case LicenseState.grace:
      case LicenseState.unlicensed: // Subject to free limits enforced by LicenseGate
        return true;
      case LicenseState.expired:
      case LicenseState.notYetValid:
      case LicenseState.wrongBind:
      case LicenseState.invalidSignature:
      case LicenseState.unsupportedVersion:
      case LicenseState.revoked:
        return false;
    }
  }
}

enum LicensePlan {
  trial,
  monthly,
  yearly,
  lifetime,
  custom,
}

extension LicensePlanExtension on LicensePlan {
  int get code {
    switch (this) {
      case LicensePlan.trial:
        return 0;
      case LicensePlan.monthly:
        return 1;
      case LicensePlan.yearly:
        return 2;
      case LicensePlan.lifetime:
        return 3;
      case LicensePlan.custom:
        return 4;
    }
  }

  String get displayNameEn {
    switch (this) {
      case LicensePlan.trial:
        return 'Free Trial';
      case LicensePlan.monthly:
        return 'Monthly Subscription';
      case LicensePlan.yearly:
        return 'Yearly Subscription';
      case LicensePlan.lifetime:
        return 'Lifetime License';
      case LicensePlan.custom:
        return 'Custom License';
    }
  }

  String get displayNameAm {
    switch (this) {
      case LicensePlan.trial:
        return 'የሙከራ ጊዜ';
      case LicensePlan.monthly:
        return 'የወር ፈቃድ';
      case LicensePlan.yearly:
        return 'የዓመት ፈቃድ';
      case LicensePlan.lifetime:
        return 'የዕድሜ ልክ ፈቃድ';
      case LicensePlan.custom:
        return 'ልዩ ፈቃድ';
    }
  }

  static LicensePlan fromCode(int code) {
    switch (code) {
      case 0:
        return LicensePlan.trial;
      case 1:
        return LicensePlan.monthly;
      case 2:
        return LicensePlan.yearly;
      case 3:
        return LicensePlan.lifetime;
      case 4:
      default:
        return LicensePlan.custom;
    }
  }
}

enum LicenseType {
  device,
  shop,
}

extension LicenseTypeExtension on LicenseType {
  int get code => this == LicenseType.device ? 1 : 2;

  static LicenseType fromCode(int code) =>
      code == 2 ? LicenseType.shop : LicenseType.device;
}

class LicenseFeatures {
  static const int onlineSync = 0x0001;
  static const int telegramBot = 0x0002;
  static const int teamRoles = 0x0004;
  static const int multiLocation = 0x0008;
  static const int export = 0x0010;

  static const Map<int, String> featureMap = {
    onlineSync: 'online_sync',
    telegramBot: 'telegram_bot',
    teamRoles: 'team_roles',
    multiLocation: 'multi_location',
    export: 'export',
  };

  static List<String> extract(int bitmask) {
    final list = <String>[];
    featureMap.forEach((mask, name) {
      if ((bitmask & mask) == mask) {
        list.add(name);
      }
    });
    return list;
  }
}

class LicensePayload {
  final int ver;
  final int kid;
  final LicenseType type;
  final LicensePlan plan;
  final BigInt licenseId;
  final String licenseIdHex;
  final Uint8List bindBytes;
  final String bindHex;
  final int issuedDay;
  final int nbfDay;
  final int expDay;
  final int graceDays;
  final int seats;
  final int featuresBitmask;
  final List<String> features;
  final int maxMajor;

  const LicensePayload({
    required this.ver,
    required this.kid,
    required this.type,
    required this.plan,
    required this.licenseId,
    required this.licenseIdHex,
    required this.bindBytes,
    required this.bindHex,
    required this.issuedDay,
    required this.nbfDay,
    required this.expDay,
    required this.graceDays,
    required this.seats,
    required this.featuresBitmask,
    required this.features,
    required this.maxMajor,
  });

  bool get isLifetime => expDay == 0xFFFF;
}

class LicenseVerificationResult {
  final LicenseState state;
  final bool isValid;
  final bool isClockSuspect;
  final int effectiveToday;
  final int? daysLeft;
  final LicensePayload? payload;
  final List<String> features;
  final String? reason;

  const LicenseVerificationResult({
    required this.state,
    required this.isValid,
    required this.isClockSuspect,
    required this.effectiveToday,
    this.daysLeft,
    this.payload,
    this.features = const [],
    this.reason,
  });

  bool hasFeature(String featureName) => features.contains(featureName);
}

class FreeLimitsStatus {
  final int currentProducts;
  final int maxProducts;
  final int currentSales;
  final int maxSales;

  const FreeLimitsStatus({
    required this.currentProducts,
    this.maxProducts = 30,
    required this.currentSales,
    this.maxSales = 50,
  });

  bool get isProductLimitReached => currentProducts >= maxProducts;
  bool get isSaleLimitReached => currentSales >= maxSales;
  bool get isExceeded => isProductLimitReached || isSaleLimitReached;
}
