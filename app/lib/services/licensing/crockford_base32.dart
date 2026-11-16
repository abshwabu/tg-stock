import 'dart:typed_data';

class CrockfordBase32 {
  static const String alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

  /// Encode binary bytes into Crockford Base32 string.
  static String encode(List<int> bytes) {
    final sb = StringBuffer();
    int buffer = 0;
    int bits = 0;

    for (final b in bytes) {
      buffer = (buffer << 8) | (b & 0xFF);
      bits += 8;
      while (bits >= 5) {
        bits -= 5;
        final idx = (buffer >> bits) & 0x1F;
        buffer &= (1 << bits) - 1;
        sb.write(alphabet[idx]);
      }
    }

    if (bits > 0) {
      final idx = (buffer << (5 - bits)) & 0x1F;
      sb.write(alphabet[idx]);
    }

    return sb.toString();
  }

  /// Decode Crockford Base32 string into binary bytes.
  static Uint8List decode(String str) {
    final clean = normalize(str);
    int buffer = 0;
    int bits = 0;
    final bytes = <int>[];

    for (int i = 0; i < clean.length; i++) {
      final char = clean[i];
      final val = alphabet.indexOf(char);
      if (val < 0) {
        throw FormatException('Invalid Crockford Base32 character: $char');
      }
      buffer = (buffer << 5) | val;
      bits += 5;
      if (bits >= 8) {
        bits -= 8;
        bytes.add((buffer >> bits) & 0xFF);
        buffer &= (1 << bits) - 1;
      }
    }

    return Uint8List.fromList(bytes);
  }

  /// Normalize user-entered string:
  /// - Strips whitespace and dashes
  /// - Uppercases
  /// - Maps Crockford aliases: O -> 0, I -> 1, L -> 1
  static String normalize(String input) {
    final clean = input.replaceAll(RegExp(r'[\s\-]+'), '').toUpperCase();
    final sb = StringBuffer();
    for (int i = 0; i < clean.length; i++) {
      final c = clean[i];
      if (c == 'O') {
        sb.write('0');
      } else if (c == 'I' || c == 'L') {
        sb.write('1');
      } else {
        sb.write(c);
      }
    }
    return sb.toString();
  }

  /// Calculate single check character for a 14-character string using weighted modulo 32.
  /// Weights: (2*i + 1) are always odd and therefore coprime to 32.
  static String calculateCheckChar(String str14) {
    if (str14.length != 14) {
      throw ArgumentError('Input must be exactly 14 characters to compute check character');
    }

    int sum = 0;
    for (int i = 0; i < 14; i++) {
      final v = alphabet.indexOf(str14[i]);
      if (v < 0) {
        throw FormatException('Invalid character: ${str14[i]}');
      }
      sum += v * (2 * i + 1);
    }

    return alphabet[sum % 32];
  }

  /// Format an 8-byte bind into a 15-character code with prefix and check character, grouped by 4.
  /// Prefix 'D' for device, 'S' for shop.
  /// Output format: DXXX-XXXX-XXXX-XXX or SXXX-XXXX-XXXX-XXX
  static String formatCode(String prefix, List<int> bind8Bytes) {
    if (bind8Bytes.length != 8) {
      throw ArgumentError('Bind must be exactly 8 bytes');
    }

    final p = prefix.toUpperCase();
    if (p != 'D' && p != 'S') {
      throw ArgumentError("Prefix must be 'D' or 'S'");
    }

    final b32 = encode(bind8Bytes).substring(0, 13);
    final payload14 = p + b32;
    final check = calculateCheckChar(payload14);
    final full15 = payload14 + check;

    return '${full15.substring(0, 4)}-${full15.substring(4, 8)}-${full15.substring(8, 12)}-${full15.substring(12, 15)}';
  }

  /// Parse and validate a device or shop code.
  static ({String prefix, Uint8List? bind, bool isValid, String? error, String normalized}) parseCode(String input) {
    final normalized = normalize(input);
    if (normalized.length != 15) {
      return (
        prefix: '',
        bind: null,
        isValid: false,
        error: 'Code must be 15 characters (expected 4 blocks)',
        normalized: normalized,
      );
    }

    final prefix = normalized[0];
    if (prefix != 'D' && prefix != 'S') {
      return (
        prefix: prefix,
        bind: null,
        isValid: false,
        error: "Code must begin with 'D' (device) or 'S' (shop)",
        normalized: normalized,
      );
    }

    final payload14 = normalized.substring(0, 14);
    final expectedCheck = normalized[14];
    String calculatedCheck;
    try {
      calculatedCheck = calculateCheckChar(payload14);
    } catch (e) {
      return (
        prefix: prefix,
        bind: null,
        isValid: false,
        error: 'Invalid characters in code: $e',
        normalized: normalized,
      );
    }

    if (calculatedCheck != expectedCheck) {
      return (
        prefix: prefix,
        bind: null,
        isValid: false,
        error: 'Check character mismatch',
        normalized: normalized,
      );
    }

    try {
      final b32Body = normalized.substring(1, 14);
      final bindBytes = decode(b32Body);
      if (bindBytes.length != 8) {
        return (
          prefix: prefix,
          bind: null,
          isValid: false,
          error: 'Decoded bind length must be 8 bytes',
          normalized: normalized,
        );
      }
      return (
        prefix: prefix,
        bind: bindBytes,
        isValid: true,
        error: null,
        normalized: normalized,
      );
    } catch (e) {
      return (
        prefix: prefix,
        bind: null,
        isValid: false,
        error: 'Base32 decoding failed: $e',
        normalized: normalized,
      );
    }
  }

  /// Format a 95-byte token into standard string:
  /// LIC1-XXXXX-XXXXX-...-XX (30 blocks of 5, 1 block of 2)
  static String formatToken(List<int> token95Bytes) {
    if (token95Bytes.length != 95) {
      throw ArgumentError('Token must be exactly 95 bytes');
    }
    final b32 = encode(token95Bytes);
    if (b32.length != 152) {
      throw StateError('Encoded token length must be 152 characters');
    }

    final blocks = <String>[];
    for (int i = 0; i < 30; i++) {
      blocks.add(b32.substring(i * 5, i * 5 + 5));
    }
    blocks.add(b32.substring(150, 152));

    return 'LIC1-${blocks.join('-')}';
  }
}
