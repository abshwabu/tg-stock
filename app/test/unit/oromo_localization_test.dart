import 'package:app/database/app_database.dart';
import 'package:app/l10n/app_localizations.dart';
import 'package:app/repositories/ledger_repository.dart';
import 'package:app/utils/l10n_helper.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  late AppDatabase db;
  late LedgerRepository repo;

  setUp(() {
    db = AppDatabase.inMemory();
    repo = LedgerRepository(db);
  });

  tearDown(() async {
    await db.close();
  });

  group('Afaan Oromoo Language Settings & Repository Tests', () {
    test('defaults: Afaan Oromoo is OFF, Amharic is ON, language is Amharic', () async {
      expect(await repo.isOromoEnabled(), isFalse);
      expect(await repo.isAmharicEnabled(), isTrue);
      expect(await repo.getLanguage(), equals('am'));
    });

    test('enabling Afaan Oromoo allows setting language to om', () async {
      await repo.setOromoEnabled(true);
      expect(await repo.isOromoEnabled(), isTrue);

      await repo.setLanguage('om');
      expect(await repo.getLanguage(), equals('om'));
    });

    test('disabling Afaan Oromoo when om was active falls back to am (or en)', () async {
      await repo.setOromoEnabled(true);
      await repo.setLanguage('om');
      expect(await repo.getLanguage(), equals('om'));

      // Now disable Oromo while Amharic is enabled
      await repo.setOromoEnabled(false);
      expect(await repo.getLanguage(), equals('am'));
    });

    test('disabling Amharic when am was active falls back to om (if enabled) or en', () async {
      // Amharic active, Oromo disabled -> disable Amharic -> falls back to en
      expect(await repo.getLanguage(), equals('am'));
      await repo.setAmharicEnabled(false);
      expect(await repo.getLanguage(), equals('en'));

      // If Oromo is enabled and Amharic is disabled -> falls back to om
      await repo.setOromoEnabled(true);
      await repo.setLanguage('am'); // simulated stored key
      expect(await repo.getLanguage(), equals('om'));
    });

    test('appL10n helper strictly separates languages with no overlapping text', () {
      final en = appL10nByCode('en', en: 'Shop Name', am: 'የሱቅ ስም', om: 'Maqaa Suuqii');
      final am = appL10nByCode('am', en: 'Shop Name', am: 'የሱቅ ስም', om: 'Maqaa Suuqii');
      final om = appL10nByCode('om', en: 'Shop Name', am: 'የሱቅ ስም', om: 'Maqaa Suuqii');

      expect(en, equals('Shop Name'));
      expect(am, equals('የሱቅ ስም'));
      expect(om, equals('Maqaa Suuqii'));

      // No slash or concatenated languages
      expect(en.contains('/'), isFalse);
      expect(am.contains('/'), isFalse);
      expect(om.contains('/'), isFalse);
    });

    test('AppLocalizations provides pure Afaan Oromoo strings', () async {
      final localizations = await AppLocalizations.delegate.load(const Locale('om'));

      expect(localizations.appTitle, equals('Qabiinsa Meeshaa'));
      expect(localizations.todaySales, equals("Gurgurtaa Har'aa"));
      expect(localizations.btnSale, equals('Gurgurtaa'));
      expect(localizations.btnStockIn, equals('Galii Meeshaa'));
      expect(localizations.btnProducts, equals('Meeshaalee'));
      expect(localizations.btnAdjust, equals('Sirreessuu'));
      expect(localizations.btnHistory, equals('Seenaa'));
      expect(localizations.btnSettings, equals("Qindaa'ina"));
      expect(localizations.dubeTitle, equals('Liqii'));
    });
  });
}
