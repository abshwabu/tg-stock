import 'dart:typed_data';

import 'package:app/services/licensing/crockford_base32.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('CrockfordBase32 Encoding & Decoding', () {
    test('encodes and decodes arbitrary bytes cleanly', () {
      final input = Uint8List.fromList([0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08]);
      final encoded = CrockfordBase32.encode(input);
      final decoded = CrockfordBase32.decode(encoded);
      expect(decoded, equals(input));
    });

    test('normalizes lowercase to uppercase', () {
      final input = Uint8List.fromList([10, 20, 30, 40]);
      final encoded = CrockfordBase32.encode(input);
      final decoded = CrockfordBase32.decode(encoded.toLowerCase());
      expect(decoded, equals(input));
    });

    test('normalizes O, o to 0 and I, i, L, l to 1', () {
      // Decode '0' vs 'O' vs 'o'
      final zeroDecoded = CrockfordBase32.decode('00');
      final oDecoded = CrockfordBase32.decode('OO');
      final lowerODecoded = CrockfordBase32.decode('oo');
      expect(oDecoded, equals(zeroDecoded));
      expect(lowerODecoded, equals(zeroDecoded));

      // Decode '1' vs 'I' vs 'L' vs 'i' vs 'l'
      final oneDecoded = CrockfordBase32.decode('11');
      final iDecoded = CrockfordBase32.decode('II');
      final lDecoded = CrockfordBase32.decode('LL');
      final lowerIDecoded = CrockfordBase32.decode('ii');
      final lowerLDecoded = CrockfordBase32.decode('ll');
      expect(iDecoded, equals(oneDecoded));
      expect(lDecoded, equals(oneDecoded));
      expect(lowerIDecoded, equals(oneDecoded));
      expect(lowerLDecoded, equals(oneDecoded));
    });

    test('ignores whitespace and hyphens in decoded input', () {
      final input = Uint8List.fromList([1, 2, 3, 4, 5, 6, 7, 8]);
      final encoded = CrockfordBase32.encode(input);
      final spaced = '  ${encoded.substring(0, 4)} - ${encoded.substring(4)} \n';
      final decoded = CrockfordBase32.decode(spaced);
      expect(decoded, equals(input));
    });

    test('throws FormatException on invalid characters (e.g. U)', () {
      expect(() => CrockfordBase32.decode('ABCUDEF'), throwsFormatException);
      expect(() => CrockfordBase32.decode('123#456'), throwsFormatException);
    });

    test('formats token in 5-character dash-separated groups with LIC1- prefix', () {
      final raw = Uint8List.fromList(List.generate(95, (i) => i % 256));
      final formatted = CrockfordBase32.formatToken(raw);
      expect(formatted.startsWith('LIC1-'), isTrue);

      final cleanToken = formatted.substring(5).replaceAll('-', '');
      final decoded = CrockfordBase32.decode(cleanToken);
      expect(decoded, equals(raw));
    });
  });

  group('Device & Shop Code Parsing & Check Character', () {
    test('formats device code with D prefix and valid check char', () {
      final bind = Uint8List.fromList([0xAA, 0xBB, 0xCC, 0xDD, 0xEE, 0xFF, 0x11, 0x22]);
      final code = CrockfordBase32.formatCode('D', bind);
      expect(code.startsWith('D'), isTrue);

      final parsed = CrockfordBase32.parseCode(code);
      expect(parsed.isValid, isTrue);
      expect(parsed.prefix, equals('D'));
      expect(parsed.bind, equals(bind));
    });

    test('formats shop code with S prefix and valid check char', () {
      final bind = Uint8List.fromList([0x12, 0x34, 0x56, 0x78, 0x9A, 0xBC, 0xDE, 0xF0]);
      final code = CrockfordBase32.formatCode('S', bind);
      expect(code.startsWith('S'), isTrue);

      final parsed = CrockfordBase32.parseCode(code);
      expect(parsed.isValid, isTrue);
      expect(parsed.prefix, equals('S'));
      expect(parsed.bind, equals(bind));
    });

    test('rejects wrong check character in code', () {
      final bind = Uint8List.fromList([0xAA, 0xBB, 0xCC, 0xDD, 0xEE, 0xFF, 0x11, 0x22]);
      final code = CrockfordBase32.formatCode('D', bind);

      // Mutate the last character (check character)
      final lastChar = code[code.length - 1];
      final mutatedChar = lastChar == '0' ? '1' : '0';
      final tampered = code.substring(0, code.length - 1) + mutatedChar;

      final parsed = CrockfordBase32.parseCode(tampered);
      expect(parsed.isValid, isFalse);
      expect(parsed.error, contains('Check character mismatch'));
    });

    test('rejects invalid prefix character (not D or S)', () {
      final bind = Uint8List.fromList([0xAA, 0xBB, 0xCC, 0xDD, 0xEE, 0xFF, 0x11, 0x22]);
      final code = CrockfordBase32.formatCode('D', bind);
      final replaced = 'X${code.substring(1)}';

      final parsed = CrockfordBase32.parseCode(replaced);
      expect(parsed.isValid, isFalse);
      expect(parsed.error, contains("must begin with 'D' (device) or 'S' (shop)"));
    });
  });
}
