import 'package:app/database/app_database.dart';
import 'package:app/services/licensing/clock_service.dart';
import 'package:app/services/licensing/date_epoch_service.dart';
import 'package:app/services/licensing/license_storage.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  late AppDatabase db;
  late InMemoryLicenseStorage storage;
  late ClockService clockService;

  setUp(() async {
    db = AppDatabase.inMemory();
    storage = InMemoryLicenseStorage();
    clockService = ClockService(storage: storage, db: db);
    await clockService.init();
  });

  tearDown(() async {
    await db.close();
  });

  group('ClockService Monotonic Clock & High-Water Mark', () {
    test('initializes high-water mark from storage or system today', () async {
      final currentToday = DateEpochService.today();
      expect(clockService.highWater, greaterThanOrEqualTo(currentToday));
      expect(clockService.effectiveToday, equals(clockService.highWater));
      expect(clockService.isClockSuspect, isFalse);
    });

    test('advances high-water mark monotonically from movement timestamp', () async {
      final futureDate = DateTime.now().toUtc().add(const Duration(days: 30));
      final futureDay = DateEpochService.dateToDay(futureDate);

      await clockService.advanceFromMovementTimestamp(futureDate);
      expect(clockService.highWater, equals(futureDay));
      expect(clockService.effectiveToday, equals(futureDay));

      // Attempting to advance from an earlier movement timestamp does not lower highWater
      final pastDate = DateTime.now().toUtc().subtract(const Duration(days: 5));
      await clockService.advanceFromMovementTimestamp(pastDate);
      expect(clockService.highWater, equals(futureDay));
    });

    test('advances high-water mark from token issuedDay', () async {
      final futureDay = DateEpochService.today() + 15;
      await clockService.advanceFromTokenIssuedDay(futureDay);
      expect(clockService.highWater, equals(futureDay));
    });

    test('detects clock suspect when system date is rolled back behind highWater - 1', () async {
      // Simulate highWater advanced 10 days into the future (e.g. user previously ran on day +10)
      final advancedDay = DateEpochService.today() + 10;
      await clockService.advanceFromTokenIssuedDay(advancedDay);

      expect(clockService.isClockSuspect, isTrue);
      expect(clockService.effectiveToday, equals(advancedDay));
    });

    test('server time calibration can lower high-water mark and clear clock suspect', () async {
      // Advance high-water 15 days ahead
      final advancedDay = DateEpochService.today() + 15;
      await clockService.advanceFromTokenIssuedDay(advancedDay);
      expect(clockService.isClockSuspect, isTrue);

      // Authenticated server time reports true current time
      final currentServerTime = DateTime.now().toUtc();
      await clockService.calibrateFromServerTime(currentServerTime);

      final expectedDay = DateEpochService.dateToDay(currentServerTime);
      expect(clockService.highWater, equals(expectedDay));
      expect(clockService.isClockSuspect, isFalse);
    });

    test('persists highWater to both secure storage and app_settings DB', () async {
      final targetDay = DateEpochService.today() + 42;
      await clockService.advanceFromTokenIssuedDay(targetDay);

      // Verify stored in memory license storage
      final storedHw = await storage.getHighWaterDay();
      expect(storedHw, equals(targetDay));

      // Verify stored in DB app_settings
      final setting = await (db.select(db.appSettings)..where((s) => s.key.equals('license_high_water_day'))).getSingle();
      expect(setting.value, equals(targetDay.toString()));

      // Create a fresh ClockService instance and verify it restores the saved highWater
      final freshService = ClockService(storage: storage, db: db);
      await freshService.init();
      expect(freshService.highWater, equals(targetDay));
    });
  });
}
