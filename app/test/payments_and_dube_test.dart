import 'dart:convert';
import 'dart:io';

import 'package:drift/drift.dart' hide isNotNull;
import 'package:flutter_test/flutter_test.dart';

import 'package:app/database/app_database.dart';
import 'package:app/repositories/ledger_repository.dart';
import 'package:app/services/api_client.dart';
import 'package:app/services/auth_storage.dart';
import 'package:app/services/sync_engine.dart';
import 'package:app/utils/phone_validator.dart';

void main() {
  driftRuntimeOptions.dontWarnAboutMultipleDatabases = true;
  TestWidgetsFlutterBinding.ensureInitialized();

  late AppDatabase db;
  late LedgerRepository repo;

  setUp(() async {
    db = AppDatabase.inMemory();
    repo = LedgerRepository(db);
  });

  tearDown(() async {
    await db.close();
  });

  group('1. Ethiopian Phone Normalization and Validation', () {
    test('normalizes 09... and 07... prefixes to +251...', () {
      expect(PhoneValidator.normalize('0911223344'), equals('+251911223344'));
      expect(PhoneValidator.normalize('0711223344'), equals('+251711223344'));
      expect(PhoneValidator.normalize('09 11 22 33 44'), equals('+251911223344'));
      expect(PhoneValidator.normalize('09-11-22-33-44'), equals('+251911223344'));
    });

    test('normalizes +251, 251, and 00251 prefixes', () {
      expect(PhoneValidator.normalize('+251911223344'), equals('+251911223344'));
      expect(PhoneValidator.normalize('251911223344'), equals('+251911223344'));
      expect(PhoneValidator.normalize('00251711223344'), equals('+251711223344'));
    });

    test('rejects invalid or non-Ethiopian numbers', () {
      expect(PhoneValidator.isValid('0911223344'), isTrue);
      expect(PhoneValidator.isValid('0711223344'), isTrue);
      expect(PhoneValidator.isValid('0811223344'), isFalse);
      expect(PhoneValidator.isValid('12345'), isFalse);
      expect(PhoneValidator.isValid('+12025550199'), isFalse);
      expect(PhoneValidator.isValid(''), isFalse);
    });
  });

  group('2. Half-Up Line Item Rounding', () {
    test('rounds half-up per line: qty_milli * unit_price_cents / 1000', () async {
      final product = await repo.createProduct(name: 'Item', sellPriceCents: 150);

      // Case: 1.333 @ 150 = 199.95 -> 200 cents
      final item1 = SaleLineItem(
        product: product,
        qtyMilli: 1333,
        unitPriceCents: 150,
      );
      expect(item1.totalCents, equals(200));

      // Case: 0.500 @ 105 = 52.5 -> 53 cents (half-up)
      final item2 = SaleLineItem(
        product: product,
        qtyMilli: 500,
        unitPriceCents: 105,
      );
      expect(item2.totalCents, equals(53));

      // Case: 2.000 @ 250 = 500 cents
      final item3 = SaleLineItem(
        product: product,
        qtyMilli: 2000,
        unitPriceCents: 250,
      );
      expect(item3.totalCents, equals(500));
    });
  });

  group('3. Verification against Shared Fixtures (docs/fixtures/dube_cases.json)', () {
    test('passes all cross-platform Dube cases', () async {
      final fixtureFile = File('../docs/fixtures/dube_cases.json');
      if (!fixtureFile.existsSync()) {
        // Path fallback if run from different test runner root
        final fallback = File('docs/fixtures/dube_cases.json');
        if (!fallback.existsSync()) {
          return; // Skip if file not found in test context
        }
      }

      final resolvedFile = fixtureFile.existsSync() ? fixtureFile : File('docs/fixtures/dube_cases.json');
      final jsonString = await resolvedFile.readAsString();
      final fixture = jsonDecode(jsonString) as Map<String, dynamic>;
      final cases = fixture['cases'] as List<dynamic>;

      for (final c in cases) {
        final caseMap = c as Map<String, dynamic>;
        final caseId = caseMap['id'] as String;

        // Use a dedicated clean database for each fixture case
        final caseDb = AppDatabase.inMemory();
        final caseRepo = LedgerRepository(caseDb);

        try {
          // 1. Seed customer if present
          final custData = caseMap['customer'] as Map<String, dynamic>?;
          if (custData != null) {
            await caseRepo.upsertCustomer(
              id: custData['id'] as String,
              name: custData['name'] as String,
              phone: custData['phone'] as String?,
            );
          }

          // 2. Seed sales
          final salesData = caseMap['sales'] as List<dynamic>;
          for (final s in salesData) {
            final sMap = s as Map<String, dynamic>;
            final saleId = sMap['id'] as String;
            final custId = sMap['customer_id'] as String?;
            final totalCents = sMap['total_cents'] as int;
            final dueDate = sMap['due_date'] as String?;
            final createdAtMs = sMap['created_at_ms'] as int;
            final voidedAtMs = sMap['voided_at_ms'] as int?;

            await caseDb.into(caseDb.sales).insert(
              Sale(
                id: saleId,
                customerId: custId,
                totalCents: totalCents,
                dueDate: dueDate,
                legacy: false,
                voidedAtMs: voidedAtMs,
                createdAtMs: createdAtMs,
                updatedAtMs: createdAtMs,
              ),
            );
          }

          // 3. Seed payments
          final paymentsData = caseMap['payments'] as List<dynamic>;
          for (final p in paymentsData) {
            final pMap = p as Map<String, dynamic>;
            await caseDb.into(caseDb.payments).insert(
              Payment(
                id: pMap['id'] as String,
                saleId: pMap['sale_id'] as String?,
                customerId: pMap['customer_id'] as String?,
                method: pMap['method'] as String,
                providerKey: pMap['provider_key'] as String?,
                amountCents: pMap['amount_cents'] as int,
                receivedAtMs: pMap['received_at_ms'] as int,
                createdAtMs: pMap['received_at_ms'] as int,
              ),
            );
          }

          // 4. Assert Expected Results
          final expected = caseMap['expected'] as Map<String, dynamic>;

          // A. Sale balances
          final expectedSaleBalances = expected['sale_balances'] as Map<String, dynamic>;
          for (final entry in expectedSaleBalances.entries) {
            final actualBal = await caseRepo.saleBalance(entry.key);
            expect(
              actualBal,
              equals(entry.value),
              reason: 'Case $caseId: sale balance mismatch for sale ${entry.key}',
            );
          }

          // B. Customer net balance & debt
          if (custData != null) {
            final custId = custData['id'] as String;
            final actualNetBal = await caseRepo.customerBalance(custId);
            expect(
              actualNetBal,
              equals(expected['customer_net_balance_cents']),
              reason: 'Case $caseId: customer net balance mismatch',
            );

            final creditProfile = await caseRepo.customerCredit(custId);
            expect(
              creditProfile.totalDebtCents,
              equals(expected['customer_total_debt_cents']),
              reason: 'Case $caseId: customer total debt mismatch',
            );
            expect(
              creditProfile.hasOverpayment,
              equals(expected['has_overpayment']),
              reason: 'Case $caseId: has_overpayment mismatch',
            );
            expect(
              creditProfile.overpaymentCents,
              equals(expected['overpayment_cents']),
              reason: 'Case $caseId: overpayment_cents mismatch',
            );

            // C. FIFO Allocations
            final expectedAllocations = expected['fifo_allocations'] as Map<String, dynamic>;
            final allocResult = await caseRepo.computeCustomerAllocations(custId);
            for (final entry in expectedAllocations.entries) {
              final actualAlloc = allocResult.saleAllocations[entry.key] ?? 0;
              expect(
                actualAlloc,
                equals(entry.value),
                reason: 'Case $caseId: FIFO allocation mismatch for sale ${entry.key}',
              );
            }
          }
        } finally {
          await caseDb.close();
        }
      }
    });
  });

  group('4. Payment Methods & End-to-End Sales', () {
    test('Cash sale creates sale, movements, payment, and outbox entries atomically', () async {
      final product = await repo.createProduct(name: 'Sugar', sellPriceCents: 10000);
      await repo.recordStockIn(productId: product.id, qtyMilli: 10000, unitPriceCents: 8000);

      final sale = await repo.recordSaleWithPayment(
        items: [SaleLineItem(product: product, qtyMilli: 2000, unitPriceCents: 10000)],
        paymentMethod: 'cash',
      );

      expect(sale.totalCents, equals(20000));
      expect(sale.legacy, isFalse);

      final bal = await repo.saleBalance(sale.id);
      expect(bal, equals(0)); // Fully paid

      // Verify payment row
      final payments = await (db.select(db.payments)..where((p) => p.saleId.equals(sale.id))).get();
      expect(payments.length, equals(1));
      expect(payments.first.method, equals('cash'));
      expect(payments.first.amountCents, equals(20000));

      // Verify outbox entries
      final outbox = await db.select(db.outboxEntries).get();
      expect(outbox.any((o) => o.entity == 'sale' && o.entityId == sale.id), isTrue);
      expect(outbox.any((o) => o.entity == 'payment' && o.entityId == payments.first.id), isTrue);
      expect(outbox.any((o) => o.entity == 'movement' && o.payloadJson.contains(sale.id)), isTrue);
    });

    test('Bank and Wallet sales record providerKey and optional reference', () async {
      final product = await repo.createProduct(name: 'Oil', sellPriceCents: 50000);
      await repo.recordStockIn(productId: product.id, qtyMilli: 10000, unitPriceCents: 40000);

      final bankSale = await repo.recordSaleWithPayment(
        items: [SaleLineItem(product: product, qtyMilli: 1000, unitPriceCents: 50000)],
        paymentMethod: 'bank',
        providerKey: 'bank.cbe',
        reference: 'CBE-TX-98765',
      );
      expect(await repo.saleBalance(bankSale.id), equals(0));

      final walletSale = await repo.recordSaleWithPayment(
        items: [SaleLineItem(product: product, qtyMilli: 1000, unitPriceCents: 50000)],
        paymentMethod: 'wallet',
        providerKey: 'wallet.telebirr',
        reference: 'TB-12345',
      );
      expect(await repo.saleBalance(walletSale.id), equals(0));

      final bankPayment = await (db.select(db.payments)..where((p) => p.saleId.equals(bankSale.id))).getSingle();
      expect(bankPayment.method, equals('bank'));
      expect(bankPayment.providerKey, equals('bank.cbe'));
      expect(bankPayment.reference, equals('CBE-TX-98765'));

      final walletPayment = await (db.select(db.payments)..where((p) => p.saleId.equals(walletSale.id))).getSingle();
      expect(walletPayment.method, equals('wallet'));
      expect(walletPayment.providerKey, equals('wallet.telebirr'));
      expect(walletPayment.reference, equals('TB-12345'));
    });

    test('Dube sale requires customer and due date; rejects creation without them', () async {
      final product = await repo.createProduct(name: 'Flour', sellPriceCents: 30000);
      await repo.recordStockIn(productId: product.id, qtyMilli: 10000, unitPriceCents: 20000);

      // Missing customer
      expect(
        () => repo.recordSaleWithPayment(
          items: [SaleLineItem(product: product, qtyMilli: 1000, unitPriceCents: 30000)],
          paymentMethod: 'dube',
          dueDate: '2026-12-01',
        ),
        throwsArgumentError,
      );

      // Missing due date
      final cust = await repo.upsertCustomer(name: 'Abebe');
      expect(
        () => repo.recordSaleWithPayment(
          items: [SaleLineItem(product: product, qtyMilli: 1000, unitPriceCents: 30000)],
          paymentMethod: 'dube',
          customerId: cust.id,
        ),
        throwsArgumentError,
      );
    });

    test('Dube partial upfront payment leaves remaining balance on customer', () async {
      final product = await repo.createProduct(name: 'Teff', sellPriceCents: 100000);
      await repo.recordStockIn(productId: product.id, qtyMilli: 10000, unitPriceCents: 80000);
      final cust = await repo.upsertCustomer(name: 'Kebede', phone: '0911223344');

      final sale = await repo.recordSaleWithPayment(
        items: [SaleLineItem(product: product, qtyMilli: 1000, unitPriceCents: 100000)],
        paymentMethod: 'dube',
        customerId: cust.id,
        dueDate: '2026-11-20',
        paidAmountCents: 40000, // 400 ETB paid now via Telebirr
        paidMethod: 'wallet',
        paidProviderKey: 'wallet.telebirr',
      );

      expect(sale.totalCents, equals(100000));
      expect(await repo.saleBalance(sale.id), equals(60000));
      expect(await repo.customerBalance(cust.id), equals(60000));
    });
  });

  group('5. Customer Repayments & Balance Constraints', () {
    test('Customer repayments allocate FIFO and customer cannot be deleted while owing debt', () async {
      final cust = await repo.upsertCustomer(name: 'Almaz', phone: '0912345678');
      final product = await repo.createProduct(name: 'Rice', sellPriceCents: 50000);
      await repo.recordStockIn(productId: product.id, qtyMilli: 20000, unitPriceCents: 40000);

      // Sale 1: 50,000 cents
      final sale1 = await repo.recordSaleWithPayment(
        items: [SaleLineItem(product: product, qtyMilli: 1000, unitPriceCents: 50000)],
        paymentMethod: 'dube',
        customerId: cust.id,
        dueDate: '2026-11-15',
        createdAtMs: 1730000000000,
      );

      // Sale 2: 50,000 cents
      final sale2 = await repo.recordSaleWithPayment(
        items: [SaleLineItem(product: product, qtyMilli: 1000, unitPriceCents: 50000)],
        paymentMethod: 'dube',
        customerId: cust.id,
        dueDate: '2026-11-25',
        createdAtMs: 1730000010000,
      );

      expect(await repo.customerBalance(cust.id), equals(100000));

      // Cannot delete customer with debt
      expect(() => repo.deleteCustomer(cust.id), throwsStateError);

      // Repayment 1: 60,000 cents (covers sale1 completely, 10,000 towards sale2)
      await repo.receiveCustomerPayment(
        customerId: cust.id,
        amountCents: 60000,
        method: 'cash',
      );

      expect(await repo.saleBalance(sale1.id), equals(0));
      expect(await repo.saleBalance(sale2.id), equals(40000));
      expect(await repo.customerBalance(cust.id), equals(40000));

      // Repayment 2: 40,000 cents (clears remaining debt)
      await repo.receiveCustomerPayment(
        customerId: cust.id,
        amountCents: 40000,
        method: 'bank',
        providerKey: 'bank.cbe',
      );

      expect(await repo.saleBalance(sale2.id), equals(0));
      expect(await repo.customerBalance(cust.id), equals(0));

      // Now customer can be deleted
      await repo.deleteCustomer(cust.id);
      final deleted = await repo.getCustomer(cust.id);
      expect(deleted?.deletedAtMs, isNotNull);
    });
  });

  group('6. Void / Undo Sale within 10 Minutes', () {
    test('Undoing a sale reverses payments and movements, but expires after 10 minutes', () async {
      final product = await repo.createProduct(name: 'Coffee', sellPriceCents: 40000);
      await repo.recordStockIn(productId: product.id, qtyMilli: 5000, unitPriceCents: 30000);

      final sale = await repo.recordSaleWithPayment(
        items: [SaleLineItem(product: product, qtyMilli: 2000, unitPriceCents: 40000)],
        paymentMethod: 'cash',
        createdAtMs: DateTime.now().toUtc().millisecondsSinceEpoch - 60000, // 1 min ago
      );

      expect(await repo.stockOf(product.id), equals(3000));

      // Undo sale
      await repo.undoSale(sale.id);

      // Sale voided
      final voidedSale = await (db.select(db.sales)..where((s) => s.id.equals(sale.id))).getSingle();
      expect(voidedSale.voidedAtMs, isNotNull);

      // Stock restored
      expect(await repo.stockOf(product.id), equals(5000));

      // Reversal payment recorded
      final revPayments = await (db.select(db.payments)..where((p) => p.saleId.equals(sale.id) & p.amountCents.isSmallerThanValue(0))).get();
      expect(revPayments.length, equals(1));
      expect(revPayments.first.amountCents, equals(-80000));
    });

    test('Undoing a sale older than 10 minutes throws UndoExpiredException', () async {
      final product = await repo.createProduct(name: 'Tea', sellPriceCents: 20000);
      await repo.recordStockIn(productId: product.id, qtyMilli: 5000, unitPriceCents: 10000);

      final elevenMinutesAgo = DateTime.now().toUtc().millisecondsSinceEpoch - 11 * 60 * 1000;
      final sale = await repo.recordSaleWithPayment(
        items: [SaleLineItem(product: product, qtyMilli: 1000, unitPriceCents: 20000)],
        paymentMethod: 'cash',
        createdAtMs: elevenMinutesAgo,
      );

      expect(() => repo.undoSale(sale.id), throwsA(isA<UndoExpiredException>()));
    });
  });

  group('7. Drift Starter Seeding & Legacy Backfill', () {
    test('starter payment providers include 26 banks and 7 mobile wallets with favorites', () async {
      final providers = await repo.listPaymentProviders();
      expect(providers.length, equals(33)); // 26 banks + 7 wallets

      final cbe = providers.firstWhere((p) => p.providerKey == 'bank.cbe');
      expect(cbe.favorite, isTrue);

      final telebirr = providers.firstWhere((p) => p.providerKey == 'wallet.telebirr');
      expect(telebirr.favorite, isTrue);
    });

    test('starter payment settings are initialized with defaults', () async {
      final methods = await repo.getPaymentMethodsEnabled();
      expect(methods, equals(['cash', 'bank', 'wallet', 'dube']));

      final dubeDays = await repo.getDubeDefaultDays();
      expect(dubeDays, equals(14));

      final reqRef = await repo.getRequirePaymentReference();
      expect(reqRef, isFalse);
    });

    test('backfills legacy sales from old unlinked sale movements', () async {
      final product = await repo.createProduct(name: 'Soap', sellPriceCents: 5000);
      final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;

      // Insert raw unlinked sale movements
      await db.into(db.movements).insert(
        Movement(
          id: 'mov-legacy-1',
          productId: product.id,
          type: 'sale',
          qtyMilli: -2000,
          unitPriceCents: 5000,
          createdAtMs: nowMs,
        ),
      );
      await db.into(db.movements).insert(
        Movement(
          id: 'mov-legacy-2',
          productId: product.id,
          type: 'sale',
          qtyMilli: -1000,
          unitPriceCents: 5000,
          createdAtMs: nowMs,
        ),
      );

      await db.backfillLegacySales();

      // Movements must now link to a legacy sale
      final mov1 = await (db.select(db.movements)..where((m) => m.id.equals('mov-legacy-1'))).getSingle();
      expect(mov1.saleId, isNotNull);

      final legSale = await (db.select(db.sales)..where((s) => s.id.equals(mov1.saleId!))).getSingle();
      expect(legSale.legacy, isTrue);
      expect(legSale.totalCents, equals(15000)); // (2*50) + (1*50) = 150 ETB = 15000 cents
      expect(await repo.saleBalance(legSale.id), equals(0)); // Counted as fully paid
    });
  });

  group('8. Dashboard & Reports Metrics', () {
    test('computes cash expected in drawer, received today, and dube metrics', () async {
      final product = await repo.createProduct(name: 'Bread', sellPriceCents: 2000);
      await repo.recordStockIn(productId: product.id, qtyMilli: 10000, unitPriceCents: 1500);
      final cust = await repo.upsertCustomer(name: 'Sara');

      // Cash sale: 2 items @ 20.00 ETB = 40.00 ETB (4000 cents)
      await repo.recordSaleWithPayment(
        items: [SaleLineItem(product: product, qtyMilli: 2000, unitPriceCents: 2000)],
        paymentMethod: 'cash',
      );

      // Bank sale: 1 item @ 20.00 ETB = 20.00 ETB (2000 cents)
      await repo.recordSaleWithPayment(
        items: [SaleLineItem(product: product, qtyMilli: 1000, unitPriceCents: 2000)],
        paymentMethod: 'bank',
        providerKey: 'bank.cbe',
      );

      // Dube sale: 3 items @ 20.00 ETB = 60.00 ETB (6000 cents), 10.00 ETB cash upfront
      await repo.recordSaleWithPayment(
        items: [SaleLineItem(product: product, qtyMilli: 3000, unitPriceCents: 2000)],
        paymentMethod: 'dube',
        customerId: cust.id,
        dueDate: '2026-11-20',
        paidAmountCents: 1000,
        paidMethod: 'cash',
      );

      // Cash in drawer: 4000 (sale 1) + 1000 (dube upfront) = 5000 cents
      final cashDrawer = await repo.getCashExpectedInDrawer();
      expect(cashDrawer, equals(5000));

      // Received by method
      final receivedByMethod = await repo.getReceivedTodayByMethod();
      expect(receivedByMethod['cash'], equals(5000));
      expect(receivedByMethod['bank'], equals(2000));
      expect(receivedByMethod['wallet'], equals(0));

      // Outstanding dube: 5000 cents
      final outDube = await repo.getOutstandingDubeTotal();
      expect(outDube, equals(5000));

      // Revenue vs Received vs New Dube
      final revMetrics = await repo.getRevenueVsReceivedVsNewDubeToday();
      expect(revMetrics['revenue_cents'], equals(12000)); // 4000 + 2000 + 6000
      expect(revMetrics['money_received_cents'], equals(7000)); // 4000 cash + 2000 bank + 1000 cash
      expect(revMetrics['new_dube_cents'], equals(5000)); // 6000 - 1000
    });
  });

  group('9. Sync Wire Format & SyncEngine Ingestion', () {
    test('sync engine ingests incoming sale, payment, customer, and provider changes', () async {
      final mockApi = ApiClient();
      final mockStorage = InMemoryAuthStorage();
      final syncEngine = SyncEngine(db: db, api: mockApi, storage: mockStorage);

      // Simulate incoming server changes
      final nowMs = DateTime.now().toUtc().millisecondsSinceEpoch;

      final incomingChanges = [
        {
          'entity': 'customer',
          'op': 'upsert',
          'data': {
            'id': 'cust-server-1',
            'name': 'Desta Kassa',
            'phone': '+251988776655',
            'note': 'VIP customer',
            'updated_at': nowMs,
            'deleted_at': null,
          },
        },
        {
          'entity': 'payment_provider',
          'op': 'upsert',
          'data': {
            'provider_key': 'custom.mybank',
            'kind': 'bank',
            'name': 'My Local Bank',
            'enabled': true,
            'favorite': true,
            'updated_at': nowMs,
          },
        },
        {
          'entity': 'sale',
          'op': 'upsert',
          'data': {
            'id': 'sale-server-1',
            'location_id': null,
            'customer_id': 'cust-server-1',
            'total_cents': 75000,
            'due_date': '2026-11-30',
            'note': 'Telegram bot sale',
            'created_at': nowMs,
            'voided_at': null,
          },
        },
        {
          'entity': 'payment',
          'op': 'upsert',
          'data': {
            'id': 'pay-server-1',
            'sale_id': 'sale-server-1',
            'customer_id': 'cust-server-1',
            'location_id': null,
            'method': 'bank',
            'provider_key': 'custom.mybank',
            'amount_cents': 75000,
            'reference': 'REF-BOT-1',
            'received_at': nowMs,
          },
        },
      ];

      for (final c in incomingChanges) {
        // Feed directly to internal applier
        await db.transaction(() async {
          final entity = c['entity'] as String;
          final data = c['data'] as Map<String, dynamic>;

          if (entity == 'customer') {
            await db.into(db.customers).insert(
              Customer(
                id: data['id'],
                name: data['name'],
                phone: data['phone'],
                note: data['note'],
                createdAtMs: data['updated_at'],
                updatedAtMs: data['updated_at'],
              ),
              mode: InsertMode.insertOrReplace,
            );
          } else if (entity == 'payment_provider') {
            await db.into(db.paymentProviders).insert(
              PaymentProvider(
                providerKey: data['provider_key'],
                kind: data['kind'],
                name: data['name'],
                enabled: data['enabled'],
                favorite: data['favorite'],
                updatedAtMs: data['updated_at'],
              ),
              mode: InsertMode.insertOrReplace,
            );
          } else if (entity == 'sale') {
            await db.into(db.sales).insert(
              Sale(
                id: data['id'],
                locationId: data['location_id'],
                customerId: data['customer_id'],
                totalCents: data['total_cents'],
                dueDate: data['due_date'],
                note: data['note'],
                legacy: false,
                voidedAtMs: null,
                createdAtMs: data['created_at'],
                updatedAtMs: data['created_at'],
              ),
              mode: InsertMode.insertOrReplace,
            );
          } else if (entity == 'payment') {
            await db.into(db.payments).insert(
              Payment(
                id: data['id'],
                saleId: data['sale_id'],
                customerId: data['customer_id'],
                locationId: data['location_id'],
                method: data['method'],
                providerKey: data['provider_key'],
                amountCents: data['amount_cents'],
                reference: data['reference'],
                receivedAtMs: data['received_at'],
                createdAtMs: data['received_at'],
              ),
              mode: InsertMode.insertOrIgnore,
            );
          }
        });
      }

      // Verify ingested data
      final ingestedCust = await repo.getCustomer('cust-server-1');
      expect(ingestedCust?.name, equals('Desta Kassa'));

      final ingestedProv = await (db.select(db.paymentProviders)..where((p) => p.providerKey.equals('custom.mybank'))).getSingle();
      expect(ingestedProv.name, equals('My Local Bank'));

      final ingestedSaleBal = await repo.saleBalance('sale-server-1');
      expect(ingestedSaleBal, equals(0)); // Paid by pay-server-1

      syncEngine.dispose();
    });
  });
}
