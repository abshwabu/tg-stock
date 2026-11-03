// Quantitative conversions between integer thousandths (local DB)
// and 3-decimal string wire representation (server API).

class QuantityFormatter {
  /// Converts integer thousandths to a wire string with exactly 3 decimals.
  /// Example: 2500 -> "2.500", -2500 -> "-2.500", 0 -> "0.000".
  static String toWireQty(int milli) {
    final isNegative = milli < 0;
    final absMilli = milli.abs();
    final whole = absMilli ~/ 1000;
    final frac = (absMilli % 1000).toString().padLeft(3, '0');
    final sign = isNegative ? '-' : '';
    return '$sign$whole.$frac';
  }

  /// Parses wire string or user decimal input to integer thousandths.
  /// Example: "2.5" -> 2500, "-2.500" -> -2500, "10" -> 10000.
  static int parseQty(String input) {
    final clean = input.trim().replaceAll(' ', '').replaceAll(',', '.');
    if (clean.isEmpty) return 0;

    final isNegative = clean.startsWith('-');
    final absClean = isNegative ? clean.substring(1) : clean;

    final parts = absClean.split('.');
    final whole = int.tryParse(parts[0]) ?? 0;
    int fractionMilli = 0;

    if (parts.length > 1) {
      final fracStr = parts[1].padRight(3, '0').substring(0, 3);
      fractionMilli = int.tryParse(fracStr) ?? 0;
    }

    final total = (whole * 1000) + fractionMilli;
    return isNegative ? -total : total;
  }

  /// User-friendly display of quantity (stripping trailing zeros if integer).
  /// Example: 2500 -> "2.5", 5000 -> "5", -1200 -> "-1.2".
  static String formatDisplay(int milli) {
    final wire = toWireQty(milli);
    if (!wire.contains('.')) return wire;

    var trimmed = wire;
    while (trimmed.endsWith('0') && trimmed.contains('.')) {
      trimmed = trimmed.substring(0, trimmed.length - 1);
    }
    if (trimmed.endsWith('.')) {
      trimmed = trimmed.substring(0, trimmed.length - 1);
    }
    return trimmed;
  }
}
