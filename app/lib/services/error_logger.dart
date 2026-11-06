import 'dart:collection';
import 'package:flutter/foundation.dart';

class ErrorLogEntry {
  final String id;
  final DateTime timestamp;
  final String message;
  final String context;
  final String? stackSummary;

  ErrorLogEntry({
    required this.id,
    required this.timestamp,
    required this.message,
    required this.context,
    this.stackSummary,
  });

  Map<String, dynamic> toJson() => {
        'id': id,
        'timestamp': timestamp.toIso8601String(),
        'message': message,
        'context': context,
        'stack': stackSummary,
      };

  @override
  String toString() {
    final timeStr = '${timestamp.hour.toString().padLeft(2, '0')}:${timestamp.minute.toString().padLeft(2, '0')}:${timestamp.second.toString().padLeft(2, '0')}';
    return '[$timeStr][$context] $message';
  }
}

class ErrorLogger {
  static final ErrorLogger instance = ErrorLogger._internal();

  ErrorLogger._internal();

  static const int maxEntries = 50;
  final ListQueue<ErrorLogEntry> _logs = ListQueue<ErrorLogEntry>(maxEntries);

  List<ErrorLogEntry> get logs => _logs.toList(growable: false).reversed.toList();

  int get count => _logs.length;

  /// Sanitizes messages to remove customer phone numbers, names, or sensitive numbers
  static String sanitizeMessage(String input) {
    // Replace Ethiopian phone numbers (+251... or 09... or 07...)
    var sanitized = input.replaceAll(RegExp(r'(\+251|0)[79]\d{8}'), '[REDACTED_PHONE]');
    // Replace UUIDs if any
    sanitized = sanitized.replaceAll(
      RegExp(r'[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'),
      '[ID]',
    );
    // Replace file paths containing user directory
    sanitized = sanitized.replaceAll(RegExp(r'/home/[^/\s]+'), '/home/[USER]');
    return sanitized;
  }

  /// Extracts top 3 meaningful stack frames without user home paths
  static String? sanitizeStackTrace(StackTrace? stack) {
    if (stack == null) return null;
    final lines = stack.toString().split('\n').where((l) => l.trim().isNotEmpty).take(3).toList();
    if (lines.isEmpty) return null;
    return sanitizeMessage(lines.join('\n'));
  }

  void log(Object error, StackTrace? stack, {String context = 'General'}) {
    final rawMessage = error.toString();
    final cleanMessage = sanitizeMessage(rawMessage);
    final cleanStack = sanitizeStackTrace(stack);

    final entry = ErrorLogEntry(
      id: DateTime.now().microsecondsSinceEpoch.toString(),
      timestamp: DateTime.now(),
      message: cleanMessage,
      context: context,
      stackSummary: cleanStack,
    );

    if (_logs.length >= maxEntries) {
      _logs.removeFirst();
    }
    _logs.addLast(entry);

    if (kDebugMode) {
      debugPrint('[ErrorLogger][$context] $cleanMessage');
    }
  }

  void clear() {
    _logs.clear();
  }

  String formatSupportReport({String appVersion = '1.0.0+1', String language = 'am'}) {
    final buffer = StringBuffer();
    buffer.writeln('=== Inventory App Diagnostics Report ===');
    buffer.writeln('Generated: ${DateTime.now().toUtc().toIso8601String()}');
    buffer.writeln('App Version: $appVersion');
    buffer.writeln('Language: $language');
    buffer.writeln('Target OS: Android / Linux (Offline-First)');
    buffer.writeln('Logged Errors Count: ${_logs.length}');
    buffer.writeln('----------------------------------------');

    if (_logs.isEmpty) {
      buffer.writeln('No errors recorded. App running healthy.');
    } else {
      for (final e in logs) {
        buffer.writeln('${e.timestamp.toIso8601String()} [${e.context}]: ${e.message}');
        if (e.stackSummary != null) {
          buffer.writeln('   Stack: ${e.stackSummary}');
        }
      }
    }
    buffer.writeln('========================================');
    return buffer.toString();
  }
}
