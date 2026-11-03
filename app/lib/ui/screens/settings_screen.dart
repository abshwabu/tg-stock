import 'package:flutter/material.dart';

import '../../services/api_client.dart';
import '../../services/auth_storage.dart';
import '../../services/sync_engine.dart';
import 'pairing_screen.dart';

class SettingsScreen extends StatefulWidget {
  final ApiClient apiClient;
  final AuthStorage authStorage;
  final SyncEngine syncEngine;

  const SettingsScreen({
    super.key,
    required this.apiClient,
    required this.authStorage,
    required this.syncEngine,
  });

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  bool _isOnline = false;
  String? _deviceName;
  String? _shopName;
  String _serverUrl = '';
  bool _isLoading = false;

  @override
  void initState() {
    super.initState();
    _loadState();
  }

  Future<void> _loadState() async {
    final online = await widget.authStorage.isOnlineEnabled();
    final device = await widget.authStorage.getDeviceName();
    final shop = await widget.authStorage.getShopName();
    final url = await widget.authStorage.getServerUrl();

    if (mounted) {
      setState(() {
        _isOnline = online;
        _deviceName = device;
        _shopName = shop;
        _serverUrl = url;
      });
    }
  }

  Future<void> _goOfflineAgain() async {
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Go Offline Again? / ኦፍላይን ሁን?'),
        content: const Text(
          'Cloud sync will stop. All your products, stock counts, and sales remain completely safe on this phone. You can reconnect anytime.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Cancel / ሰርዝ'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Go Offline / ኦፍላይን ሁን'),
          ),
        ],
      ),
    );

    if (confirm == true) {
      widget.syncEngine.stopPeriodicSync();
      await widget.authStorage.setOnlineEnabled(false);
      widget.syncEngine.stateNotifier.value = widget.syncEngine.state.copyWith(
        status: SyncStatus.offline,
      );
      await _loadState();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('App is now in offline mode.')),
        );
      }
    }
  }

  Future<void> _deleteOnlineData() async {
    // Confirmation Step 1
    final step1 = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('⚠️ Delete Online Data? / የኦንላይን ዳታ አጥፋ?'),
        content: const Text(
          'This will permanently delete your online shop data on the server, including Telegram bot connection and sync with other phones.\n\nYour local inventory records on this phone will NOT be deleted.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Cancel / ሰርዝ'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Continue / ቀጥል'),
          ),
        ],
      ),
    );

    if (step1 != true || !mounted) return;

    // Confirmation Step 2: Double Confirmation
    final step2 = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('🚨 FINAL CONFIRMATION'),
        content: const Text(
          'Are you absolutely sure? Once deleted, other devices and the Telegram bot will lose access immediately.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Cancel / ሰርዝ'),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.red.shade900),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Permanently Delete Online Shop'),
          ),
        ],
      ),
    );

    if (step2 != true || !mounted) return;

    setState(() => _isLoading = true);
    try {
      final token = await widget.authStorage.getDeviceToken();
      final serverUrl = await widget.authStorage.getServerUrl();

      if (token != null) {
        await widget.apiClient.deleteShopOnline(
          serverUrl: serverUrl,
          deviceToken: token,
        );
      }

      widget.syncEngine.stopPeriodicSync();
      await widget.authStorage.clearDeviceToken();
      await widget.authStorage.setOnlineEnabled(false);

      widget.syncEngine.stateNotifier.value = widget.syncEngine.state.copyWith(
        status: SyncStatus.offline,
      );

      await _loadState();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            backgroundColor: Colors.red,
            content: Text('Online shop data permanently deleted from server.'),
          ),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Failed to delete online data: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _editServerUrl() async {
    final controller = TextEditingController(text: _serverUrl);
    final newUrl = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Server URL'),
        content: TextField(
          controller: controller,
          decoration: const InputDecoration(
            hintText: 'https://...',
            labelText: 'Server Base URL',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.of(ctx).pop(controller.text.trim()),
            child: const Text('Save'),
          ),
        ],
      ),
    );

    if (newUrl != null && newUrl.isNotEmpty) {
      await widget.authStorage.setServerUrl(newUrl);
      await _loadState();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Settings / ቅንብሮች'),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          // Section 1: Cloud & Sync
          Card(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Icon(
                        _isOnline ? Icons.cloud_done_rounded : Icons.cloud_off_rounded,
                        color: _isOnline ? Colors.green : Colors.grey,
                        size: 28,
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              _isOnline ? 'Online Sync Active' : 'Offline Mode (ኦፍላይን)',
                              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                            ),
                            Text(
                              _isOnline
                                  ? 'Device: $_deviceName${_shopName != null ? ' • Shop: $_shopName' : ''}'
                                  : 'Working locally without internet connection',
                              style: TextStyle(fontSize: 12, color: Colors.grey.shade700),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  if (!_isOnline) ...[
                    const Text(
                      'Optionally connect this phone with your Telegram account to back up inventory, share stock between devices, and receive Telegram sales alerts.',
                      style: TextStyle(fontSize: 13, height: 1.4),
                    ),
                    const SizedBox(height: 16),
                    FilledButton.icon(
                      onPressed: () async {
                        final res = await Navigator.of(context).push(
                          MaterialPageRoute(
                            builder: (ctx) => PairingScreen(
                              apiClient: widget.apiClient,
                              authStorage: widget.authStorage,
                              syncEngine: widget.syncEngine,
                            ),
                          ),
                        );
                        if (res == true) {
                          await _loadState();
                        }
                      },
                      icon: const Icon(Icons.cloud_upload_outlined),
                      label: const Text('Go Online (Optional) / ኦንላይን ይገናኙ'),
                    ),
                  ] else ...[
                    Row(
                      children: [
                        Expanded(
                          child: OutlinedButton.icon(
                            onPressed: _goOfflineAgain,
                            icon: const Icon(Icons.cloud_off),
                            label: const Text('Go Offline Again'),
                          ),
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: FilledButton.icon(
                            onPressed: () => widget.syncEngine.syncNow(),
                            icon: const Icon(Icons.refresh),
                            label: const Text('Sync Now'),
                          ),
                        ),
                      ],
                    ),
                  ],
                ],
              ),
            ),
          ),
          const SizedBox(height: 20),

          // Section 2: Server Connection
          Card(
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            child: ListTile(
              title: const Text('Server Endpoint'),
              subtitle: Text(_serverUrl.isNotEmpty ? _serverUrl : 'Default'),
              leading: const Icon(Icons.dns_rounded),
              trailing: const Icon(Icons.edit_outlined),
              onTap: _editServerUrl,
            ),
          ),
          const SizedBox(height: 20),

          // Section 3: Danger Zone (Delete Online Data)
          if (_isOnline) ...[
            const Padding(
              padding: EdgeInsets.symmetric(horizontal: 4, vertical: 8),
              child: Text(
                'DANGER ZONE / አደገኛ እርምጃ',
                style: TextStyle(
                  fontSize: 12,
                  fontWeight: FontWeight.bold,
                  color: Colors.red,
                  letterSpacing: 1,
                ),
              ),
            ),
            Card(
              color: Colors.red.shade50,
              shape: RoundedRectangleBorder(
                side: BorderSide(color: Colors.red.shade200),
                borderRadius: BorderRadius.circular(12),
              ),
              child: ListTile(
                title: Text(
                  'Delete My Online Data',
                  style: TextStyle(color: Colors.red.shade900, fontWeight: FontWeight.bold),
                ),
                subtitle: const Text(
                  'Deletes cloud shop records and Telegram link. Local data on this phone is kept.',
                  style: TextStyle(fontSize: 12),
                ),
                trailing: _isLoading
                    ? const SizedBox(
                        width: 20,
                        height: 20,
                        child: CircularProgressIndicator(strokeWidth: 2, color: Colors.red),
                      )
                    : Icon(Icons.delete_forever, color: Colors.red.shade900),
                onTap: _isLoading ? null : _deleteOnlineData,
              ),
            ),
          ],
        ],
      ),
    );
  }
}
