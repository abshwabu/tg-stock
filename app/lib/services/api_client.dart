import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

class ApiException implements Exception {
  final String message;
  final int? statusCode;

  ApiException(this.message, [this.statusCode]);

  @override
  String toString() => 'ApiException: $message (status: $statusCode)';
}

class UnauthorizedException extends ApiException {
  UnauthorizedException([String message = 'Device token is invalid or has been revoked.'])
      : super(message, 401);
}

class RateLimitedException extends ApiException {
  final int retryAfterSeconds;

  RateLimitedException({this.retryAfterSeconds = 5, String message = 'Rate limit reached.'})
      : super(message, 429);
}

class NetworkException extends ApiException {
  NetworkException(super.message);
}

class PairingStartResponse {
  final String code;
  final String deepLink;

  PairingStartResponse({required this.code, required this.deepLink});

  factory PairingStartResponse.fromJson(Map<String, dynamic> json) {
    return PairingStartResponse(
      code: json['code'] as String,
      deepLink: json['deep_link'] as String,
    );
  }
}

class PairingStatusResponse {
  final String status; // 'pending', 'confirmed', 'expired'
  final String? deviceToken;

  PairingStatusResponse({required this.status, this.deviceToken});

  bool get isConfirmed => status == 'confirmed';
  bool get isPending => status == 'pending';
  bool get isExpired => status == 'expired';

  factory PairingStatusResponse.fromJson(Map<String, dynamic> json) {
    return PairingStatusResponse(
      status: json['status'] as String? ?? 'pending',
      deviceToken: json['device_token'] as String?,
    );
  }
}

class SyncResponse {
  final List<String> accepted;
  final List<Map<String, String>> rejected;
  final List<Map<String, dynamic>> changes;
  final int cursor;

  SyncResponse({
    required this.accepted,
    required this.rejected,
    required this.changes,
    required this.cursor,
  });

  factory SyncResponse.fromJson(Map<String, dynamic> json) {
    final acceptedList = (json['accepted'] as List<dynamic>?)
            ?.map((e) => e.toString())
            .toList() ??
        [];

    final rejectedList = (json['rejected'] as List<dynamic>?)
            ?.map((e) {
              if (e is Map) {
                return {
                  'id': e['id']?.toString() ?? '',
                  'reason': e['reason']?.toString() ?? 'Rejected by server',
                };
              }
              return {'id': '', 'reason': 'Unknown error'};
            })
            .toList() ??
        [];

    final changesList = (json['changes'] as List<dynamic>?)
            ?.map((e) => Map<String, dynamic>.from(e as Map))
            .toList() ??
        [];

    final cursorInt = json['cursor'] is int
        ? json['cursor'] as int
        : int.tryParse(json['cursor']?.toString() ?? '0') ?? 0;

    return SyncResponse(
      accepted: acceptedList,
      rejected: rejectedList,
      changes: changesList,
      cursor: cursorInt,
    );
  }
}

class ApiClient {
  final http.Client _client;
  final Duration timeout;

  ApiClient({
    http.Client? client,
    this.timeout = const Duration(seconds: 25),
  }) : _client = client ?? http.Client();

  /// Enforce TLS unless connecting to local test environment.
  void _assertTls(Uri uri) {
    final host = uri.host.toLowerCase();
    final isLocal = host == 'localhost' || host == '127.0.0.1' || host == '10.0.2.2';
    if (!isLocal && uri.scheme != 'https') {
      throw ApiException('Insecure HTTP connection rejected. Production connections must use HTTPS / TLS.');
    }
  }

  /// POST /v1/pair/start
  Future<PairingStartResponse> startPairing({
    required String serverUrl,
    String? shopName,
    required String deviceName,
  }) async {
    final uri = Uri.parse('$serverUrl/v1/pair/start');
    _assertTls(uri);

    final payload = {
      if (shopName != null && shopName.trim().isNotEmpty) 'shop_name': shopName.trim(),
      'device_name': deviceName.trim(),
    };

    final response = await _sendRequest(() => _client.post(
          uri,
          headers: {'Content-Type': 'application/json'},
          body: jsonEncode(payload),
        ));

    if (response.statusCode == 200 || response.statusCode == 201) {
      final json = jsonDecode(response.body) as Map<String, dynamic>;
      return PairingStartResponse.fromJson(json);
    }

    _handleError(response);
    throw ApiException('Failed to start pairing', response.statusCode);
  }

  /// GET /v1/pair/status?code=<code>
  Future<PairingStatusResponse> checkPairingStatus({
    required String serverUrl,
    required String code,
  }) async {
    final uri = Uri.parse('$serverUrl/v1/pair/status?code=${Uri.encodeComponent(code)}');
    _assertTls(uri);

    final response = await _sendRequest(() => _client.get(
          uri,
          headers: {'Accept': 'application/json'},
        ));

    if (response.statusCode == 200) {
      final json = jsonDecode(response.body) as Map<String, dynamic>;
      return PairingStatusResponse.fromJson(json);
    }

    _handleError(response);
    throw ApiException('Failed to check pairing status', response.statusCode);
  }

  /// POST /v1/sync
  Future<SyncResponse> sync({
    required String serverUrl,
    required String deviceToken,
    required int cursor,
    required List<Map<String, dynamic>> changes,
  }) async {
    final uri = Uri.parse('$serverUrl/v1/sync');
    _assertTls(uri);

    final payload = {
      'cursor': cursor,
      'changes': changes,
    };

    final response = await _sendRequest(() => _client.post(
          uri,
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': 'Bearer $deviceToken',
          },
          body: jsonEncode(payload),
        ));

    if (response.statusCode == 200) {
      final json = jsonDecode(response.body) as Map<String, dynamic>;
      return SyncResponse.fromJson(json);
    }

    _handleError(response);
    throw ApiException('Sync request failed', response.statusCode);
  }

  /// DELETE /v1/shop
  Future<bool> deleteShopOnline({
    required String serverUrl,
    required String deviceToken,
  }) async {
    final uri = Uri.parse('$serverUrl/v1/shop');
    _assertTls(uri);

    final response = await _sendRequest(() => _client.delete(
          uri,
          headers: {
            'Accept': 'application/json',
            'Authorization': 'Bearer $deviceToken',
          },
        ));

    if (response.statusCode == 200 || response.statusCode == 204) {
      return true;
    }

    _handleError(response);
    return false;
  }

  Future<http.Response> _sendRequest(Future<http.Response> Function() requestFn) async {
    try {
      return await requestFn().timeout(timeout);
    } on TimeoutException {
      throw NetworkException('Request timed out. Please check your internet connection.');
    } on SocketException catch (e) {
      throw NetworkException('Network connection failed: ${e.message}');
    } on http.ClientException catch (e) {
      throw NetworkException('HTTP client error: ${e.message}');
    } catch (e) {
      if (e is ApiException) rethrow;
      throw NetworkException('Unexpected network error: $e');
    }
  }

  void _handleError(http.Response response) {
    if (response.statusCode == 401) {
      throw UnauthorizedException();
    }

    if (response.statusCode == 429 || response.statusCode == 503) {
      int retrySeconds = 5;
      final retryHeader = response.headers['retry-after'];
      if (retryHeader != null) {
        retrySeconds = int.tryParse(retryHeader) ?? 5;
      }
      throw RateLimitedException(
        retryAfterSeconds: retrySeconds,
        message: 'Server requested delay (${retrySeconds}s) before retrying.',
      );
    }

    String serverError = '';
    try {
      final json = jsonDecode(response.body);
      if (json is Map && json['error'] != null) {
        serverError = json['error'].toString();
      }
    } catch (_) {}

    throw ApiException(
      serverError.isNotEmpty ? serverError : 'HTTP Error ${response.statusCode}',
      response.statusCode,
    );
  }
}
