import 'package:app/database/app_database.dart';
import 'package:app/repositories/ledger_repository.dart';
import 'package:app/services/licensing/clock_service.dart';
import 'package:app/services/licensing/install_id_service.dart';
import 'package:app/services/licensing/license_gate.dart';
import 'package:app/services/licensing/license_models.dart';
import 'package:app/services/licensing/license_storage.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  const testContextId = 'a1b2c3d4-e5f6-7a8b-9c0d-1e2f3a4b5c6d';

  // Tokens from shared test vectors (docs/fixtures/license_vectors.json)
  const validMonthlyToken =
      'LIC1-040G2-0814D-2PF2D-BSQQK-T72VG-CEQRP-VS0G8-08404-5R3G2-00K05-0SP99-BSYV4-J56DX-XFF7R-MAJFA-W23E3-2G0QN-HNY64-MW494-79CD2-5BEBJ-7FEKP-0TX7F-Z7AN5-24FDQ-7ZV34-AK823-J9SPR-VZ4F6-D7B5J-8C';

  const graceToken =
      'LIC1-040G2-0814D-2PF2D-BSM1K-T72VG-CEQRP-VS0FV-G7XR4-2M3G2-00H06-XTAB5-VAXQ5-37T4R-7TBD5-PXEXC-EZ51V-0BKBV-7PRH9-EAK5P-P9BDW-EDR25-KXZ6P-CB5AK-R8BP2-JS0QP-9FHBR-5JJHM-41R22-DQW5A-DPCQF-G3';

  const expiredToken =
      'LIC1-040G2-0814D-2PF2D-BSM23-T72VG-CEQRP-VS0FM-07T04-0R3G2-00H07-40F6C-WRM8T-49CHC-ZH4R8-25ZYQ-EKSHJ-VWVA7-YBQ6C-R3N2V-CFS1A-A6496-9EPK9-V1VFK-VNHGE-FRX3V-TQAXH-9JDKK-P5T20-CYMV3-W7QJX-R8';

  late AppDatabase db;
  late InMemoryLicenseStorage storage;
  late _TestClockService clockService;
  late InstallIdService installIdService;
  late LicenseGate gate;
  late LedgerRepository repo;

  setUp(() async {
    db = AppDatabase.inMemory();
    storage = InMemoryLicenseStorage();
    await storage.saveInstallId(testContextId);

    clockService = _TestClockService(storage: storage, db: db);
    await clockService.init();

    installIdService = InstallIdService(storage: storage);
    gate = LicenseGate(
      storage: storage,
      clockService: clockService,
      installIdService: installIdService,
      db: db,
    );
    repo = LedgerRepository(db, licenseGate: gate, clockService: clockService);
  });

  tearDown(() async {
    await db.close();
  });

  group('LicenseGate Unlicensed Mode Free Limits', () {
    test('allows up to 30 products, then blocks 31st with LicenseLimitException', () async {
      await gate.reevaluate();
      expect(gate.state, equals(LicenseState.unlicensed));
      expect(gate.isReadOnly, isFalse);

      // Create 30 products
      for (int i = 1; i <= 30; i++) {
        await repo.createProduct(
          name: 'Item $i',
          costPriceCents: 1000,
          sellPriceCents: 1500,
        );
      }

      final limits = await gate.getFreeLimitsStatus();
      expect(limits.currentProducts, equals(30));
      expect(limits.isProductLimitReached, isTrue);

      // 31st product creation must be blocked
      expect(
        () => repo.createProduct(
          name: 'Item 31',
          costPriceCents: 1000,
          sellPriceCents: 1500,
        ),
        throwsA(isA<LicenseLimitException>()),
      );

      // But updating existing products is allowed
      final firstProduct = (await repo.listProducts()).first.product;
      await repo.updateProduct(firstProduct.copyWith(name: 'Updated Item 1'));
      final updated = await repo.getProduct(firstProduct.id);
      expect(updated?.name, equals('Updated Item 1'));
    });

    test('allows up to 50 sales, then blocks 51st with LicenseLimitException', () async {
      await gate.reevaluate();

      final product = await repo.createProduct(
        name: 'Teff (ጤፍ)',
        costPriceCents: 5000,
        sellPriceCents: 8000,
      );
      await repo.recordStockIn(productId: product.id, qtyMilli: 500000);

      // Record 50 sales
      for (int i = 1; i <= 50; i++) {
        await repo.recordSale(
          items: [
            SaleLineItem(
              product: product,
              qtyMilli: 1000,
              unitPriceCents: 8000,
            ),
          ],
        );
      }

      final limits = await gate.getFreeLimitsStatus();
      expect(limits.currentSales, equals(50));
      expect(limits.isSaleLimitReached, isTrue);

      // 51st sale must be blocked
      expect(
        () => repo.recordSale(
          items: [
            SaleLineItem(
              product: product,
              qtyMilli: 1000,
              unitPriceCents: 8000,
            ),
          ],
        ),
        throwsA(isA<LicenseLimitException>()),
      );
    });
  });

  group('LicenseGate Active License', () {
    test('activating valid monthly token unlocks unlimited products and sales', () async {
      final res = await gate.activateToken(validMonthlyToken);
      expect(res.isValid, isTrue);
      expect(gate.state, equals(LicenseState.active));
      expect(gate.isReadOnly, isFalse);

      // Can add more than 30 products
      for (int i = 1; i <= 35; i++) {
        await repo.createProduct(
          name: 'Licensed Product $i',
          costPriceCents: 500,
          sellPriceCents: 1000,
        );
      }
      final products = await repo.listProducts();
      expect(products.length, equals(35));
    });

    test('grace token allows all operations while signaling grace state', () async {
      clockService.mockToday = 1050;
      clockService.mockHighWater = 1050;
      final res = await gate.activateToken(graceToken);
      expect(res.state, equals(LicenseState.grace));
      expect(gate.isReadOnly, isFalse);

      final p = await repo.createProduct(
        name: 'Grace Product',
        costPriceCents: 100,
        sellPriceCents: 200,
      );
      expect(p.name, equals('Grace Product'));
      clockService.mockToday = null;
      clockService.mockHighWater = null;
    });
  });

  group('LicenseGate Expired License Read-Only Enforcement', () {
    test('expired token blocks all repository business mutations', () async {
      // First create a product while unlicensed
      final product = await repo.createProduct(
        name: 'Oil (ዘይት)',
        costPriceCents: 4000,
        sellPriceCents: 6000,
      );

      // Now activate expired token
      await gate.activateToken(expiredToken);
      expect(gate.state, equals(LicenseState.expired));
      expect(gate.isReadOnly, isTrue);

      // Product creation blocked
      expect(
        () => repo.createProduct(name: 'Blocked Item', costPriceCents: 100, sellPriceCents: 200),
        throwsA(isA<LicenseReadOnlyException>()),
      );

      // Stock adjustment / in blocked
      expect(
        () => repo.recordStockIn(productId: product.id, qtyMilli: 10000),
        throwsA(isA<LicenseReadOnlyException>()),
      );

      // Sale blocked
      expect(
        () => repo.recordSale(
          items: [
            SaleLineItem(
              product: product,
              qtyMilli: 1000,
              unitPriceCents: 6000,
            ),
          ],
        ),
        throwsA(isA<LicenseReadOnlyException>()),
      );

      // Customer payment recording blocked
      expect(
        () => repo.receiveCustomerPayment(
          customerId: 'test-customer-1',
          amountCents: 500,
          method: 'cash',
        ),
        throwsA(isA<LicenseReadOnlyException>()),
      );

      // But read-only queries still work!
      final list = await repo.listProducts();
      expect(list.length, equals(1));
      expect(list.first.product.name, equals('Oil (ዘይት)'));
    });
  });
}

class _TestClockService extends ClockService {
  int? mockToday;
  int? mockHighWater;
  _TestClockService({required super.storage, required super.db});
  @override
  int get today => mockToday ?? super.today;
  @override
  int get highWater => mockHighWater ?? super.highWater;
  @override
  int get effectiveToday => mockToday ?? super.effectiveToday;
}
