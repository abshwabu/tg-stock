class EthiopicCalendar {
  static const Map<int, String> amharicMonths = {
    1: 'መስከረም',
    2: 'ጥቅምት',
    3: 'ኅዳር',
    4: 'ታኅሣሥ',
    5: 'ጥር',
    6: 'የካቲት',
    7: 'መጋቢት',
    8: 'ሚያዝያ',
    9: 'ግንቦት',
    10: 'ሰኔ',
    11: 'ሐምሌ',
    12: 'ነሐሴ',
    13: 'ጳጉሜ',
  };

  static const Map<int, String> englishMonths = {
    1: 'Meskerem',
    2: 'Tikimt',
    3: 'Hidar',
    4: 'Tahsas',
    5: 'Tir',
    6: 'Yakatit',
    7: 'Megabit',
    8: 'Miyazya',
    9: 'Ginbot',
    10: 'Sene',
    11: 'Hamle',
    12: 'Nehase',
    13: 'Pagume',
  };

  /// Convert a Gregorian DateTime to Ethiopian calendar components:
  /// {year, month, day, monthName}
  static ({int year, int month, int day, String monthName}) fromGregorian(
    DateTime date, {
    String locale = 'am',
  }) {
    final gy = date.year;
    final gm = date.month;
    final gd = date.day;

    // Calculate Julian Day Number from Gregorian Date
    final a = (14 - gm) ~/ 12;
    final y = gy + 4800 - a;
    final m = gm + 12 * a - 3;
    final jdn = gd +
        ((153 * m + 2) ~/ 5) +
        365 * y +
        (y ~/ 4) -
        (y ~/ 100) +
        (y ~/ 400) -
        32045;

    // Convert Julian Day Number to Ethiopic Calendar
    final r = (jdn - 1723856) % 1461;
    final n = (r % 365) + 365 * (r ~/ 1460);
    final ey = 4 * ((jdn - 1723856) ~/ 1461) + (r ~/ 365) - (r ~/ 1460);
    final em = (n ~/ 30) + 1;
    final ed = (n % 30) + 1;

    final monthName = locale == 'en'
        ? (englishMonths[em] ?? '')
        : (amharicMonths[em] ?? '');

    return (
      year: ey,
      month: em,
      day: ed,
      monthName: monthName,
    );
  }

  /// Format an Ethiopian date: e.g. "ጥቅምት 24, 2019" or "Tikimt 24, 2019"
  static String format(DateTime date, {String locale = 'am'}) {
    final eth = fromGregorian(date, locale: locale);
    return '${eth.monthName} ${eth.day}, ${eth.year}';
  }

  /// Dual format showing both Gregorian and Ethiopian calendar representations:
  /// e.g. "Dec 06, 2026 (ህዳር 27, 2019)"
  static String formatDual(DateTime date, {String locale = 'am'}) {
    final eth = format(date, locale: locale);
    final gregMonth = _gregorianMonthName(date.month, locale);
    final greg = '$gregMonth ${date.day}, ${date.year}';
    return '$greg ($eth)';
  }

  static String _gregorianMonthName(int month, String locale) {
    const en = [
      '', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
      'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
    ];
    const am = [
      '', 'ጃንዩ', 'ፌብሩ', 'ማርች', 'ኤፕሪ', 'ሜይ', 'ጁን',
      'ጁላይ', 'ኦገስ', 'ሴፕቴ', 'ኦክቶ', 'ኖቬም', 'ዲሴም',
    ];
    if (locale == 'am' && month >= 1 && month <= 12) {
      return am[month];
    }
    if (month >= 1 && month <= 12) {
      return en[month];
    }
    return '';
  }
}
