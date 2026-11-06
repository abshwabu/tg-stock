import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../services/api_client.dart';
import '../../services/auth_storage.dart';
import '../../services/sync_engine.dart';

class PairingScreen extends StatefulWidget {
  final ApiClient apiClient;
  final AuthStorage authStorage;
  final SyncEngine syncEngine;

  const PairingScreen({
    super.key,
    required this.apiClient,
    required this.authStorage,
    required this.syncEngine,
  });

  @override
  State<PairingScreen> createState() => _PairingScreenState();
}

class _PairingScreenState extends State<PairingScreen> {
  final _formKey = GlobalKey<FormState>();
  final _shopNameController = TextEditingController();
  final _deviceNameController = TextEditingController();

  bool _isLoading = false;
  String? _errorMessage;

  // Pairing active state
  String? _code;
  String? _deepLink;
  Timer? _pollingTimer;
  int _secondsRemaining = 600; // 10 minutes
  Timer? _countdownTimer;

  // Initial sync progress
  bool _isUploadingHistory = false;
  int _historyCurrent = 0;
  int _historyTotal = 0;
  String? _historyStatusText;

  @override
  void initState() {
    super.initState();
    _loadInitialValues();
  }

  Future<void> _loadInitialValues() async {
    final shop = await widget.authStorage.getShopName();
    final device = await widget.authStorage.getDeviceName();
    if (mounted) {
      setState(() {
        if (shop != null) _shopNameController.text = shop;
        _deviceNameController.text = device;
      });
    }
  }

  @override
  void dispose() {
    _pollingTimer?.cancel();
    _countdownTimer?.cancel();
    _shopNameController.dispose();
    _deviceNameController.dispose();
    super.dispose();
  }

  Future<void> _startPairing() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final serverUrl = await widget.authStorage.getServerUrl();
      final shopName = _shopNameController.text.trim();
      final deviceName = _deviceNameController.text.trim();

      await widget.authStorage.setServerUrl(serverUrl);
      await widget.authStorage.setShopName(shopName.isNotEmpty ? shopName : null);
      await widget.authStorage.setDeviceName(deviceName);

      final result = await widget.apiClient.startPairing(
        serverUrl: serverUrl,
        shopName: shopName.isNotEmpty ? shopName : null,
        deviceName: deviceName,
      );

      setState(() {
        _code = result.code;
        _deepLink = result.deepLink;
        _isLoading = false;
        _secondsRemaining = 600;
      });

      _startPolling(serverUrl, result.code);
      _startCountdown();
    } catch (e) {
      if (mounted) {
        setState(() {
          _isLoading = false;
          _errorMessage = e.toString();
        });
      }
    }
  }

  void _startCountdown() {
    _countdownTimer?.cancel();
    _countdownTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) {
        timer.cancel();
        return;
      }
      setState(() {
        if (_secondsRemaining > 0) {
          _secondsRemaining--;
        } else {
          timer.cancel();
          _pollingTimer?.cancel();
          _errorMessage = 'Pairing code expired. Please request a new code.';
          _code = null;
        }
      });
    });
  }

  void _startPolling(String serverUrl, String code) {
    _pollingTimer?.cancel();
    _pollingTimer = Timer.periodic(const Duration(seconds: 3), (timer) async {
      try {
        final status = await widget.apiClient.checkPairingStatus(
          serverUrl: serverUrl,
          code: code,
        );

        if (status.isConfirmed && status.deviceToken != null) {
          timer.cancel();
          _countdownTimer?.cancel();
          await _onPairingConfirmed(status.deviceToken!);
        } else if (status.isExpired) {
          timer.cancel();
          _countdownTimer?.cancel();
          if (mounted) {
            setState(() {
              _code = null;
              _errorMessage = 'Pairing code expired on server.';
            });
          }
        }
      } catch (e) {
        // Tolerant of intermittent network drops during polling
      }
    });
  }

  Future<void> _onPairingConfirmed(String deviceToken) async {
    // 1. Save token securely & enable online mode
    await widget.authStorage.saveDeviceToken(deviceToken);
    await widget.authStorage.setOnlineEnabled(true);

    if (!mounted) return;
    setState(() {
      _isUploadingHistory = true;
      _historyStatusText = 'Connected to Telegram! Uploading offline history...';
    });

    // 2. Perform initial history sync with progress tracking
    try {
      await widget.syncEngine.runInitialHistorySync(
        onProgress: (current, total) {
          if (mounted) {
            setState(() {
              _historyCurrent = current;
              _historyTotal = total;
              _historyStatusText = 'Uploading offline history: $current / $total';
            });
          }
        },
      );

      widget.syncEngine.startPeriodicSync();

      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            backgroundColor: Colors.green,
            content: Text('🎉 Online mode enabled! Offline history synced successfully.'),
          ),
        );
        Navigator.of(context).pop(true);
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _isUploadingHistory = false;
          _errorMessage = 'Initial sync encountered an issue: $e';
        });
      }
    }
  }

  Future<void> _openDeepLink() async {
    if (_deepLink == null) return;
    final uri = Uri.parse(_deepLink!);
    if (await canLaunchUrl(uri)) {
      await launchUrl(uri, mode: LaunchMode.externalApplication);
    } else {
      await Clipboard.setData(ClipboardData(text: _deepLink!));
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Telegram link copied to clipboard.')),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Connect with Telegram'),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(20),
        child: _isUploadingHistory
            ? _buildUploadProgressView()
            : _code != null
                ? _buildWaitingView()
                : _buildStartPairingForm(),
      ),
    );
  }

  Widget _buildStartPairingForm() {
    final scheme = Theme.of(context).colorScheme;
    return Form(
      key: _formKey,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: scheme.primaryContainer,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: scheme.primary.withValues(alpha: 0.35)),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(Icons.info_outline, color: scheme.onPrimaryContainer),
                    const SizedBox(width: 8),
                    Text(
                      'Why go online? / ለምን ኦንላይን ይገናኛሉ?',
                      style: TextStyle(
                        fontWeight: FontWeight.bold,
                        color: scheme.onPrimaryContainer,
                        fontSize: 15,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                Text(
                  '• Automatic cloud backup of your shop records.\n'
                  '• Multi-phone sync across all cashiers and staff.\n'
                  '• Telegram alerts when stock is low and daily 8:00 PM sales summaries.\n'
                  '• No passwords needed: Your Telegram account is your secure key.',
                  style: TextStyle(fontSize: 13, color: scheme.onPrimaryContainer, height: 1.4),
                ),
              ],
            ),
          ),
          const SizedBox(height: 24),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
            decoration: BoxDecoration(
              color: scheme.surfaceContainerHighest,
              borderRadius: BorderRadius.circular(8),
              border: Border.all(color: scheme.outlineVariant),
            ),
            child: Row(
              children: [
                const Icon(Icons.cloud_done_outlined, size: 20, color: Color(0xFF0F766E)),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Cloud Server / የደመና ሰርቨር',
                        style: TextStyle(fontSize: 11, color: Colors.grey.shade600, fontWeight: FontWeight.w600),
                      ),
                      const SizedBox(height: 2),
                      const Text(
                        'https://stock.yenesuq.com',
                        style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: Color(0xFF0F766E)),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),
          TextFormField(
            controller: _shopNameController,
            decoration: const InputDecoration(
              labelText: 'Shop Name / የሱቁ ስም (Optional)',
              hintText: 'e.g. Bole Minimarket',
              border: OutlineInputBorder(),
              prefixIcon: Icon(Icons.storefront),
            ),
          ),
          const SizedBox(height: 16),
          TextFormField(
            controller: _deviceNameController,
            decoration: const InputDecoration(
              labelText: 'Device Name / የስልኩ ስም',
              hintText: 'e.g. Counter Phone 1',
              border: OutlineInputBorder(),
              prefixIcon: Icon(Icons.phone_android),
            ),
            validator: (val) {
              if (val == null || val.trim().isEmpty) {
                return 'Please enter a name for this phone / device';
              }
              return null;
            },
          ),
          if (_errorMessage != null) ...[
            const SizedBox(height: 16),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: Colors.red.shade50,
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text(
                _errorMessage!,
                style: TextStyle(color: Colors.red.shade900, fontSize: 13),
              ),
            ),
          ],
          const SizedBox(height: 24),
          FilledButton.icon(
            onPressed: _isLoading ? null : _startPairing,
            icon: _isLoading
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                  )
                : const Icon(Icons.send_rounded),
            label: Text(_isLoading ? 'Generating Link...' : 'Start Pairing / ማገናኘት ጀምር'),
            style: FilledButton.styleFrom(padding: const EdgeInsets.symmetric(vertical: 14)),
          ),
        ],
      ),
    );
  }

  Widget _buildWaitingView() {
    final scheme = Theme.of(context).colorScheme;
    final mins = _secondsRemaining ~/ 60;
    final secs = (_secondsRemaining % 60).toString().padLeft(2, '0');

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Icon(Icons.telegram, size: 72, color: Color(0xFF2AABEE)),
        const SizedBox(height: 16),
        const Text(
          'Approve on Telegram',
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold),
        ),
        const SizedBox(height: 8),
        Text(
          'Open your shop Telegram bot and tap "Start" to link this device:',
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 14, color: scheme.onSurfaceVariant),
        ),
        const SizedBox(height: 20),
        Container(
          padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 20),
            decoration: BoxDecoration(
            color: scheme.surfaceContainerHighest,
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: scheme.outlineVariant),
          ),
          child: Column(
            children: [
              Text('Pairing Code / የማገናኛ ኮድ:', style: TextStyle(fontSize: 12, color: scheme.onSurfaceVariant)),
              const SizedBox(height: 6),
              Text(
                _code ?? '---',
                style: TextStyle(
                  fontSize: 32,
                  fontWeight: FontWeight.bold,
                  letterSpacing: 4,
                  fontFamily: 'monospace',
                  color: scheme.onSurface,
                ),
              ),
              const SizedBox(height: 4),
              Text(
                'Expires in $mins:$secs',
                style: TextStyle(fontSize: 12, color: Colors.red.shade700, fontWeight: FontWeight.w500),
              ),
            ],
          ),
        ),
        const SizedBox(height: 20),
        FilledButton.icon(
          onPressed: _openDeepLink,
          icon: const Icon(Icons.open_in_new),
          label: const Text('Open Telegram Bot / ቴሌግራም ክፈት'),
          style: FilledButton.styleFrom(
            backgroundColor: const Color(0xFF2AABEE),
            padding: const EdgeInsets.symmetric(vertical: 14),
          ),
        ),
        const SizedBox(height: 12),
        OutlinedButton.icon(
          onPressed: () {
            Clipboard.setData(ClipboardData(text: _deepLink ?? _code ?? ''));
            ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(content: Text('Link copied to clipboard.')),
            );
          },
          icon: const Icon(Icons.copy),
          label: const Text('Copy Link / ሊንኩን ቅዳ'),
        ),
        const SizedBox(height: 24),
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)),
            const SizedBox(width: 10),
            Text(
              'Waiting for confirmation...',
              style: TextStyle(fontSize: 13, color: Colors.grey.shade700, fontStyle: FontStyle.italic),
            ),
          ],
        ),
        const SizedBox(height: 16),
        TextButton(
          onPressed: () {
            _pollingTimer?.cancel();
            _countdownTimer?.cancel();
            setState(() {
              _code = null;
              _deepLink = null;
            });
          },
          child: const Text('Cancel / ሰርዝ', style: TextStyle(color: Colors.red)),
        ),
      ],
    );
  }

  Widget _buildUploadProgressView() {
    final progress = _historyTotal > 0 ? (_historyCurrent / _historyTotal).clamp(0.0, 1.0) : null;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        const SizedBox(height: 30),
        const Icon(Icons.cloud_upload_rounded, size: 64, color: Colors.green),
        const SizedBox(height: 16),
        const Text(
          'Syncing Offline History',
          style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
        ),
        const SizedBox(height: 8),
        Text(
          _historyStatusText ?? 'Uploading...',
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 14, color: Colors.grey),
        ),
        const SizedBox(height: 24),
        LinearProgressIndicator(value: progress, minHeight: 8),
        const SizedBox(height: 12),
        if (_historyTotal > 0)
          Text('$_historyCurrent of $_historyTotal records uploaded', style: const TextStyle(fontSize: 12)),
        const SizedBox(height: 30),
        const Text(
          'Please keep the app open while records are uploaded to your online shop.',
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 12, color: Colors.grey),
        ),
      ],
    );
  }
}
