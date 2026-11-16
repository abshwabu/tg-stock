import 'package:app/services/licensing/ethiopic_calendar.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('EthiopicCalendar', () {
    test('converts known historical Gregorian dates to Ethiopian dates', () {
      // 2024-01-01 -> Tahsas 22, 2016
      final ethiopian = EthiopicCalendar.fromGregorian(DateTime.utc(2024, 1, 1));
      expect(ethiopian.year, equals(2016));
      expect(ethiopian.month, equals(4)); // Tahsas
      expect(ethiopian.day, equals(22));

      // 2024-09-11 -> Meskerem 1, 2017 (Ethiopian New Year)
      final eny = EthiopicCalendar.fromGregorian(DateTime.utc(2024, 9, 11));
      expect(eny.year, equals(2017));
      expect(eny.month, equals(1)); // Meskerem
      expect(eny.day, equals(1));
    });

    test('handles 13th month (Pagume)', () {
      // 2024-09-06 -> Pagume 1, 2016
      final pagume = EthiopicCalendar.fromGregorian(DateTime.utc(2024, 9, 6));
      expect(pagume.year, equals(2016));
      expect(pagume.month, equals(13)); // Pagume
      expect(pagume.day, equals(1));
    });

    test('formats dates correctly in Amharic and English', () {
      final date = DateTime.utc(2024, 9, 11);
      final amFormat = EthiopicCalendar.format(date, locale: 'am');
      expect(amFormat, equals('መስከረም 1, 2017'));

      final enFormat = EthiopicCalendar.format(date, locale: 'en');
      expect(enFormat, equals('Meskerem 1, 2017'));
    });

    test('formats dual Gregorian and Ethiopian display', () {
      final date = DateTime.utc(2024, 1, 1);
      final dualAm = EthiopicCalendar.formatDual(date, locale: 'am');
      expect(dualAm, contains('ታኅሣሥ 22, 2016'));
      expect(dualAm, contains('2024'));

      final dualEn = EthiopicCalendar.formatDual(date, locale: 'en');
      expect(dualEn, contains('Tahsas 22, 2016'));
      expect(dualEn, contains('Jan 1, 2024'));
    });
  });
}
