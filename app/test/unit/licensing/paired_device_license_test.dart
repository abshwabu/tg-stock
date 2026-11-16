import 'package:app/database/app_database.dart';
import 'package:app/services/licensing/clock_service.dart';
import 'package:app/services/licensing/install_id_service.dart';
import 'package:app/services/licensing/license_gate.dart';
import 'package:app/services/licensing/license_models.dart';
import 'package:app/services/licensing/license_storage.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  const shopUuid = 'e3b0c442-98fc-1c14-9afb-4c8996fb9242';
  const shopBoundToken =
      'LIC1-040G4-0G14D-2PF2D-BSM8K-5CMZ7-8MVKP-QZ0FM-07T05-AM70A-00Z03-QM0CR-3QS0D-82JQH-22BYD-YERG8-2PRKG-ZZRN2-5YNMP-W9ZRT-6JYNY-FJSVX-3EG8C-XTVDW-4WY9F-JQ6EW-FC7FX-6TXAY-K2K9X-EN41S-Z6P36-G4';

  late AppDatabase db;
  late InMemoryLicenseStorage storage;
  late ClockService clockService;
  late InstallIdService installIdService;
  late LicenseGate gate;

  setUp(() async {
    db = AppDatabase.inMemory();
    storage = InMemoryLicenseStorage();
    // Device install ID is separate and random
    await storage.saveInstallId('random-staff-device-install-id-999');

    clockService = ClockService(storage: storage, db: db);
    await clockService.init();

    installIdService = InstallIdService(storage: storage);
    gate = LicenseGate(
      storage: storage,
      clockService: clockService,
      installIdService: installIdService,
      db: db,
    );
  });

  tearDown(() async {
    await db.close();
  });

  group('Paired Device Licensing (Shop-Bound)', () {
    test('staff device inherits shop license via paired shop UUID', () async {
      // Before pairing, activating shop-bound token evaluates to wrongBind (since device install ID does not match)
      final unpairRes = await gate.activateToken(shopBoundToken);
      expect(unpairRes.state, equals(LicenseState.wrongBind));

      // Now pair device with the shop UUID
      await storage.savePairedShopUuid(shopUuid);

      // Activate shop token for paired shop
      final res = await gate.activateToken(shopBoundToken);
      expect(res.state, equals(LicenseState.active));
      expect(res.isValid, isTrue);
      expect(res.payload, isNotNull);
      expect(res.payload!.type, equals(LicenseType.shop));
      expect(res.payload!.seats, equals(5));
      expect(res.hasFeature('online_sync'), isTrue);
      expect(res.hasFeature('telegram_bot'), isTrue);
      expect(gate.isReadOnly, isFalse);

      // Reevaluate confirms active status
      final reeval = await gate.reevaluate();
      expect(reeval.state, equals(LicenseState.active));
      expect(reeval.isValid, isTrue);
    });

    test('rejects shop-bound token if paired shop UUID does not match', () async {
      await storage.savePairedShopUuid('different-shop-uuid-11112222');
      final res = await gate.activateToken(shopBoundToken);
      expect(res.state, equals(LicenseState.wrongBind));
      expect(res.isValid, isFalse);
    });
  });
}
