class DateEpochService {
  static const String timezone = 'Africa/Addis_Ababa';
  static const int timezoneOffsetSeconds = 10800; // +3 hours
  static const int daysBetween1970And2024 = 19723;
  static const int lifetimeExpDay = 0xFFFF; // 65535

  /// Compute days since 2024-01-01 in Africa/Addis_Ababa from a DateTime or timestamp.
  static int dateToDay([DateTime? date]) {
    final dt = date ?? DateTime.now();
    // Unix timestamp in seconds
    final unixSeconds = (dt.millisecondsSinceEpoch / 1000).floor();
    return ((unixSeconds + timezoneOffsetSeconds) ~/ 86400) - daysBetween1970And2024;
  }

  /// Convert a day index since 2024-01-01 into a DateTime (midnight in Africa/Addis_Ababa).
  static DateTime dayToDate(int day) {
    final daysFrom1970 = day + daysBetween1970And2024;
    final timestampSeconds = (daysFrom1970 * 86400) - timezoneOffsetSeconds;
    return DateTime.fromMillisecondsSinceEpoch(timestampSeconds * 1000, isUtc: true);
  }

  /// Format a day index into YYYY-MM-DD in Africa/Addis_Ababa.
  static String dayToIsoDate(int day) {
    if (day == lifetimeExpDay) {
      return 'Lifetime (Never)';
    }
    // Calculate calendar year, month, day in Addis (+3)
    final daysFrom1970 = day + daysBetween1970And2024;
    final timestampSeconds = (daysFrom1970 * 86400); // at UTC 00:00 for the Addis day
    final utcDate = DateTime.fromMillisecondsSinceEpoch(timestampSeconds * 1000, isUtc: true);
    final y = utcDate.year.toString().padLeft(4, '0');
    final m = utcDate.month.toString().padLeft(2, '0');
    final d = utcDate.day.toString().padLeft(2, '0');
    return '$y-$m-$d';
  }

  /// Current day index in Addis Ababa.
  static int today() {
    return dateToDay();
  }
}
