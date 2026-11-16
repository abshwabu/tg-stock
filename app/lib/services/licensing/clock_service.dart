import 'dart:math';

import '../../database/app_database.dart';
import 'date_epoch_service.dart';
import 'license_storage.dart';

class ClockService {
  final LicenseStorage _storage;
  final AppDatabase _db;

  static const String _dbSettingKey = 'license_high_water_day';

  int _highWater = 0;
  bool _initialized = false;

  ClockService({
    required LicenseStorage storage,
    required AppDatabase db,
  })  : _storage = storage,
        _db = db;

  int get highWater => _highWater;
  int get today => DateEpochService.today();

  /// Effective today is monotonically bounded by high-water mark.
  int get effectiveToday => max(today, _highWater);

  /// Clock is suspect if system clock was rolled back more than 1 day behind high-water mark.
  bool get isClockSuspect => today < (_highWater - 1);

  /// Initialize and calibrate high-water mark from storage, DB, and system clock.
  Future<void> init() async {
    final storageHw = await _storage.getHighWaterDay() ?? 0;
    int dbHw = 0;
    try {
      final entry = await (_db.select(_db.appSettings)..where((s) => s.key.equals(_dbSettingKey))).getSingleOrNull();
      if (entry != null) {
        dbHw = int.tryParse(entry.value) ?? 0;
      }
    } catch (_) {}

    _highWater = max(storageHw, dbHw);

    // Monotonically advance from current system clock if ahead
    final currentToday = DateEpochService.today();
    if (currentToday > _highWater) {
      await _persist(currentToday);
    }

    _initialized = true;
  }

  /// Advance high-water mark from the device system clock.
  Future<void> advanceFromClock() async {
    if (!_initialized) await init();
    final currentToday = DateEpochService.today();
    if (currentToday > _highWater) {
      await _persist(currentToday);
    }
  }

  /// Advance high-water mark from a business transaction/movement timestamp.
  Future<void> advanceFromMovementTimestamp(DateTime timestamp) async {
    if (!_initialized) await init();
    final mDay = DateEpochService.dateToDay(timestamp);
    if (mDay > _highWater) {
      await _persist(mDay);
    }
  }

  /// Advance high-water mark from an activated token's issuedDay.
  Future<void> advanceFromTokenIssuedDay(int issuedDay) async {
    if (!_initialized) await init();
    if (issuedDay > _highWater) {
      await _persist(issuedDay);
    }
  }

  /// Calibrate high-water mark using authenticated server time.
  /// ONLY authenticated server time is cryptographically trusted to lower high-water mark.
  Future<void> calibrateFromServerTime(DateTime serverTime) async {
    if (!_initialized) await init();
    final serverDay = DateEpochService.dateToDay(serverTime);
    await _persist(serverDay);
  }

  Future<void> _persist(int day) async {
    _highWater = day;
    try {
      await _storage.saveHighWaterDay(day);
    } catch (_) {}
    try {
      await _db.into(_db.appSettings).insertOnConflictUpdate(
        AppSettingsCompanion.insert(key: _dbSettingKey, value: day.toString()),
      );
    } catch (_) {}
  }
}
