// Ethiopian Birr money formatting (cents to ETB string).

class MoneyFormatter {
  /// Formats integer cents into Ethiopian Birr string.
  /// Example: 125000 -> "1,250.00 ETB", 50 -> "0.50 ETB".
  static String formatCents(int cents) {
    final isNegative = cents < 0;
    final absCents = cents.abs();
    final birr = absCents ~/ 100;
    final santim = (absCents % 100).toString().padLeft(2, '0');

    // Add comma thousands separators to birr
    final birrStr = _addCommas(birr.toString());
    final sign = isNegative ? '-' : '';
    return '$sign$birrStr.$santim ETB';
  }

  /// Parses ETB decimal string to integer cents.
  /// Example: "1250.50" -> 125050, "50" -> 5000.
  static int parseCents(String input) {
    final clean = input.trim().replaceAll(' ', '').replaceAll(',', '.').replaceAll('ETB', '');
    if (clean.isEmpty) return 0;

    final isNegative = clean.startsWith('-');
    final absClean = isNegative ? clean.substring(1) : clean;

    final parts = absClean.split('.');
    final birr = int.tryParse(parts[0]) ?? 0;
    int santim = 0;

    if (parts.length > 1) {
      final s = parts[1].padRight(2, '0').substring(0, 2);
      santim = int.tryParse(s) ?? 0;
    }

    final total = (birr * 100) + santim;
    return isNegative ? -total : total;
  }

  static String _addCommas(String str) {
    final reg = RegExp(r'(\d{1,3})(?=(\d{3})+(?!\d))');
    return str.replaceAllMapped(reg, (match) => '${match[1]},');
  }
}
