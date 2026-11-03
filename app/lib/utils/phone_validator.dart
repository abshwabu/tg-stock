class PhoneValidator {
  /// Normalizes Ethiopian phone numbers into E.164 (+251...) format.
  /// Handles formats like:
  ///   0911223344 -> +251911223344
  ///   0711223344 -> +251711223344
  ///   +251911223344 -> +251911223344
  ///   251911223344 -> +251911223344
  ///   00251911223344 -> +251911223344
  static String? normalize(String? phone) {
    if (phone == null) return null;
    var cleaned = phone.replaceAll(RegExp(r'[\s\-\(\)\.\/]+'), '').trim();
    if (cleaned.isEmpty) return null;

    if (cleaned.startsWith('00251')) {
      cleaned = '+${cleaned.substring(2)}';
    } else if (cleaned.startsWith('251')) {
      cleaned = '+$cleaned';
    } else if (RegExp(r'^0([97]\d{8})$').hasMatch(cleaned)) {
      final match = RegExp(r'^0([97]\d{8})$').firstMatch(cleaned);
      cleaned = '+251${match!.group(1)}';
    } else if (RegExp(r'^([97]\d{8})$').hasMatch(cleaned)) {
      final match = RegExp(r'^([97]\d{8})$').firstMatch(cleaned);
      cleaned = '+251${match!.group(1)}';
    }

    return cleaned;
  }

  /// Checks whether a normalized phone matches valid Ethiopian mobile number format (+2519... or +2517...).
  static bool isValid(String? phone) {
    final norm = normalize(phone);
    if (norm == null) return false;
    return RegExp(r'^\+251[97]\d{8}$').hasMatch(norm);
  }
}
