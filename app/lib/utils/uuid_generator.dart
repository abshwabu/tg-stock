import 'package:uuid/uuid.dart';

class UuidGenerator {
  static const _uuid = Uuid();

  /// Generates a time-ordered UUIDv7 string.
  static String v7() {
    return _uuid.v7();
  }
}
