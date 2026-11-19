import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../repositories/ledger_repository.dart';
import '../../services/licensing/date_epoch_service.dart';
import '../../services/licensing/ethiopic_calendar.dart';
import '../../services/licensing/install_id_service.dart';
import '../../services/licensing/license_gate.dart';
import '../../services/licensing/license_models.dart';
import '../../services/sync_engine.dart';

class LicenseScreen extends StatefulWidget {
  final LicenseGate licenseGate;
  final InstallIdService installIdService;
  final LedgerRepository repository;
  final SyncEngine? syncEngine;

  const LicenseScreen({
    super.key,
    required this.licenseGate,
    required this.installIdService,
    required this.repository,
    this.syncEngine,
  });

  @override
  State<LicenseScreen> createState() => _LicenseScreenState();
}

class _LicenseScreenState extends State<LicenseScreen> {
  final _tokenController = TextEditingController();
  bool _isLoading = false;
  String? _deviceCode;
  String _language = 'am';
  FreeLimitsStatus? _freeLimits;

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  @override
  void dispose() {
    _tokenController.dispose();
    super.dispose();
  }

  Future<void> _loadData() async {
    setState(() => _isLoading = true);
    try {
      final code = await widget.installIdService.getDeviceCode();
      final lang = await widget.repository.getLanguage();
      final limits = await widget.licenseGate.getFreeLimitsStatus();
      if (mounted) {
        setState(() {
          _deviceCode = code;
          _language = lang;
          _freeLimits = limits;
        });
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _activateToken() async {
    final rawToken = _tokenController.text.trim();
    if (rawToken.isEmpty) {
      _showToast(_language == 'am' ? 'እባክዎ ፈቃድ ያስገቡ' : 'Please enter a license token', isError: true);
      return;
    }

    setState(() => _isLoading = true);
    try {
      String? shopUuid = await widget.syncEngine?.licenseStorage?.getPairedShopUuid();
      if ((shopUuid == null || shopUuid.isEmpty) && widget.syncEngine != null) {
        try {
          final enabled = await widget.syncEngine!.storage.isOnlineEnabled();
          final token = await widget.syncEngine!.storage.getDeviceToken();
          final serverUrl = await widget.syncEngine!.storage.getServerUrl();
          if (enabled && token != null && serverUrl.isNotEmpty) {
            final meData = await widget.syncEngine!.api.getMe(serverUrl: serverUrl, deviceToken: token);
            final userObj = meData['user'] as Map<String, dynamic>?;
            final meShopId = (userObj?['shop_id'] ?? meData['shop_id'])?.toString();
            if (meShopId != null && meShopId.isNotEmpty) {
              shopUuid = meShopId;
              await widget.syncEngine!.licenseStorage?.savePairedShopUuid(meShopId);
            }
          }
        } catch (_) {}
      }

      final result = await widget.licenseGate.activateToken(rawToken, shopUuid: shopUuid);
      await _loadData();
      if (!mounted) return;
      if (result.isValid || result.state == LicenseState.grace || result.state == LicenseState.expiring) {
        _tokenController.clear();
        final planName = _language == 'am'
            ? result.payload?.plan.displayNameAm ?? 'ፈቃድ'
            : result.payload?.plan.displayNameEn ?? 'License';
        _showToast(
          _language == 'am'
              ? 'የሱቅ ፈቃድ በተሳካ ሁኔታ ነቅቷል! ($planName)'
              : 'License activated successfully! ($planName)',
        );
      } else {
        final reason = result.reason ?? (_language == 'am' ? 'ልክ ያልሆነ ፈቃድ' : 'Invalid license token');
        _showToast(reason, isError: true);
      }
    } catch (e) {
      _showToast('Activation error: $e', isError: true);
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  Future<void> _copyDeviceCode() async {
    final code = _deviceCode ?? await widget.installIdService.getDeviceCode();
    await Clipboard.setData(ClipboardData(text: code));
    _showToast(_language == 'am' ? 'የመሳሪያው ኮድ ተቀድቷል' : 'Device code copied to clipboard');
  }

  Future<void> _shareDeviceCode() async {
    final readyMsg = await widget.installIdService.getReadyMessage();
    await SharePlus.instance.share(ShareParams(text: readyMsg));
  }

  Future<void> _openTelegramBot() async {
    final link = await widget.installIdService.getTelegramDeepLink();
    final uri = Uri.parse(link);
    try {
      final launched = await launchUrl(uri, mode: LaunchMode.externalApplication);
      if (!launched) {
        await launchUrl(uri);
      }
    } catch (e) {
      _showToast('Could not open Telegram: $e', isError: true);
    }
  }

  void _showToast(String message, {bool isError = false}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).hideCurrentSnackBar();
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: isError ? Colors.red : Colors.green[700],
        duration: const Duration(seconds: 3),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final isAm = _language == 'am';

    return Scaffold(
      appBar: AppBar(
        title: Text(isAm ? 'የመተግበሪያ ፈቃድ' : 'App License & Activation'),
      ),
      body: _isLoading && _deviceCode == null
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: () async {
                if (widget.syncEngine != null) {
                  try {
                    await widget.syncEngine!.syncLicense();
                  } catch (_) {}
                }
                await _loadData();
              },
              child: SingleChildScrollView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    _buildStatusCard(isAm),
                    const SizedBox(height: 16),
                    _buildDeviceCodeCard(isAm),
                    const SizedBox(height: 16),
                    _buildActivationCard(isAm),
                    const SizedBox(height: 16),
                    _buildTelegramActionsCard(isAm),
                  ],
                ),
              ),
            ),
    );
  }

  Widget _buildStatusCard(bool isAm) {
    return ValueListenableBuilder<LicenseVerificationResult>(
      valueListenable: widget.licenseGate.currentResultNotifier,
      builder: (context, result, _) {
        final state = result.state;
        final payload = result.payload;

        Color badgeColor;
        String stateText;
        IconData stateIcon;

        switch (state) {
          case LicenseState.active:
            badgeColor = Colors.green;
            stateText = isAm ? 'ንቁ (Active)' : 'Active';
            stateIcon = Icons.verified_rounded;
            break;
          case LicenseState.expiring:
            badgeColor = Colors.amber[800]!;
            stateText = isAm ? 'በቅርቡ ያበቃል (Expiring)' : 'Expiring Soon';
            stateIcon = Icons.hourglass_top_rounded;
            break;
          case LicenseState.grace:
            badgeColor = Colors.orange[800]!;
            stateText = isAm ? 'የችሮታ ጊዜ (Grace)' : 'Grace Period';
            stateIcon = Icons.warning_amber_rounded;
            break;
          case LicenseState.expired:
            badgeColor = Colors.red;
            stateText = isAm ? 'ጊዜው አልቋል (የንባብ ብቻ)' : 'Expired (Read-Only)';
            stateIcon = Icons.lock_clock_rounded;
            break;
          case LicenseState.unlicensed:
            badgeColor = Colors.blueGrey;
            stateText = isAm ? 'ያልነቃ ነፃ ስሪት' : 'Free Starter (Unlicensed)';
            stateIcon = Icons.info_outline_rounded;
            break;
          default:
            badgeColor = Colors.red[800]!;
            stateText = result.state.wireName;
            stateIcon = Icons.error_outline_rounded;
        }

        String planDisplay;
        if (payload != null) {
          planDisplay = isAm ? payload.plan.displayNameAm : payload.plan.displayNameEn;
        } else {
          planDisplay = isAm ? 'ነፃ ጀማሪ (Starter)' : 'Free Starter Tier';
        }

        String? expiryDisplay;
        if (payload != null) {
          if (payload.isLifetime) {
            expiryDisplay = isAm ? 'የዕድሜ ልክ (አያበቃም)' : 'Lifetime (Never Expires)';
          } else {
            final expDate = DateEpochService.dayToDate(payload.expDay);
            expiryDisplay = EthiopicCalendar.formatDual(expDate, locale: _language);
          }
        }

        return Card(
          elevation: 2,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      isAm ? 'የፈቃድ ሁኔታ' : 'License Status',
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                      decoration: BoxDecoration(
                        color: badgeColor.withValues(alpha: 0.12),
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(color: badgeColor, width: 1.2),
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(stateIcon, color: badgeColor, size: 16),
                          const SizedBox(width: 4),
                          Text(
                            stateText,
                            style: TextStyle(color: badgeColor, fontWeight: FontWeight.bold, fontSize: 12),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
                const Divider(height: 24),
                _buildInfoRow(
                  Localizations.localeOf(context).languageCode == 'om'
                      ? 'Gosa Hayyamaa'
                      : (isAm ? 'የፈቃድ ዓይነት' : 'License Plan'),
                  planDisplay,
                ),
                if (expiryDisplay != null) ...[
                  const SizedBox(height: 8),
                  _buildInfoRow(isAm ? 'የሚያበቃበት ቀን' : 'Expires On', expiryDisplay),
                ],
                if (result.daysLeft != null && payload != null && !payload.isLifetime) ...[
                  const SizedBox(height: 8),
                  _buildInfoRow(
                    isAm ? 'የቀሩ ቀናት' : 'Days Remaining',
                    '${result.daysLeft} ${isAm ? "ቀናት" : "days"}',
                  ),
                ],
                if (state == LicenseState.unlicensed && _freeLimits != null) ...[
                  const SizedBox(height: 12),
                  LinearProgressIndicator(
                    value: (_freeLimits!.currentProducts / _freeLimits!.maxProducts).clamp(0.0, 1.0),
                    backgroundColor: Colors.grey[200],
                    valueColor: AlwaysStoppedAnimation(
                      _freeLimits!.isProductLimitReached ? Colors.red : Colors.teal,
                    ),
                  ),
                  const SizedBox(height: 6),
                  Text(
                    isAm
                        ? 'እቃዎች: ${_freeLimits!.currentProducts}/${_freeLimits!.maxProducts} • ሽያጮች: ${_freeLimits!.currentSales}/${_freeLimits!.maxSales}'
                        : 'Products: ${_freeLimits!.currentProducts}/${_freeLimits!.maxProducts} • Sales: ${_freeLimits!.currentSales}/${_freeLimits!.maxSales}',
                    style: TextStyle(fontSize: 12, color: Colors.grey[700]),
                  ),
                ],
                if (widget.syncEngine != null) ...[
                  const SizedBox(height: 14),
                  SizedBox(
                    width: double.infinity,
                    child: OutlinedButton.icon(
                      icon: const Icon(Icons.sync_rounded, size: 18),
                      label: Text(isAm ? 'ፈቃድ ከሰርቨር አድስ' : 'Sync License from Server'),
                      onPressed: _isLoading
                          ? null
                          : () async {
                              setState(() => _isLoading = true);
                              try {
                                final res = await widget.syncEngine!.syncLicense();
                                await _loadData();
                                if (!mounted) return;
                                if (res != null &&
                                    (res.isValid ||
                                        res.state == LicenseState.expiring ||
                                        res.state == LicenseState.grace)) {
                                  final planName = isAm
                                      ? res.payload?.plan.displayNameAm ?? 'ፈቃድ'
                                      : res.payload?.plan.displayNameEn ?? 'License';
                                  _showToast(
                                    isAm
                                        ? 'የሱቅ ፈቃድ በተሳካ ሁኔታ ነቅቷል! ($planName)'
                                        : 'Shop license activated successfully! ($planName)',
                                  );
                                } else if (res == null) {
                                  _showToast(
                                    isAm
                                        ? 'በአገልጋዩ ላይ ለዚህ ሱቅ የተገኘ ፈቃድ የለም። እባክዎ በቴሌግራም ቦት ፈቃድ ያግብሩ።'
                                        : 'No active license found on server for this shop. Please activate via Telegram bot.',
                                    isError: true,
                                  );
                                } else {
                                  _showToast(
                                    res.reason ??
                                        (isAm ? 'ፈቃድ ልክ አይደለም' : 'Invalid license token'),
                                    isError: true,
                                  );
                                }
                              } catch (e) {
                                _showToast('Sync error: $e', isError: true);
                              } finally {
                                if (mounted) setState(() => _isLoading = false);
                              }
                            },
                    ),
                  ),
                ],
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _buildDeviceCodeCard(bool isAm) {
    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              isAm ? 'የዚህ መሳሪያ መለያ ኮድ (Device Code)' : 'Device Identifier Code',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
            ),
            const SizedBox(height: 6),
            Text(
              isAm
                  ? 'ፈቃድ ለመግዛት ወይም ለማደስ ይህንን ኮድ ለሻጩ ያጋሩ።'
                  : 'Share this code with the product owner or bot to issue your license.',
              style: TextStyle(fontSize: 12.5, color: Colors.grey[600]),
            ),
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
              decoration: BoxDecoration(
                color: Colors.grey[100],
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: Colors.grey[300]!),
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(
                    _deviceCode ?? 'D...',
                    style: const TextStyle(
                      fontFamily: 'monospace',
                      fontSize: 18,
                      fontWeight: FontWeight.bold,
                      letterSpacing: 1.5,
                      color: Color(0xFF0F766E),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: _copyDeviceCode,
                    icon: const Icon(Icons.copy_rounded, size: 18),
                    label: Text(isAm ? 'ኮድ ቅዳ' : 'Copy Code'),
                  ),
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: ElevatedButton.icon(
                    onPressed: _shareDeviceCode,
                    icon: const Icon(Icons.share_rounded, size: 18),
                    label: Text(isAm ? 'አጋራ' : 'Share'),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildActivationCard(bool isAm) {
    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              isAm ? 'ፈቃድ ማግበሪያ (Activate Token)' : 'Activate License Token',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
            ),
            const SizedBox(height: 6),
            Text(
              isAm
                  ? 'የተላከልዎትን የፈቃድ ቁልፍ እዚህ ለጥፈው ያግብሩ።'
                  : 'Paste your signed LIC1 token here to activate.',
              style: TextStyle(fontSize: 12.5, color: Colors.grey[600]),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _tokenController,
              decoration: InputDecoration(
                hintText: 'LIC1-XXXXX-XXXXX-...',
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
                contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                suffixIcon: IconButton(
                  icon: const Icon(Icons.paste_rounded),
                  tooltip: isAm ? 'ለጥፍ' : 'Paste',
                  onPressed: () async {
                    final data = await Clipboard.getData('text/plain');
                    if (data?.text != null) {
                      _tokenController.text = data!.text!.trim();
                    }
                  },
                ),
              ),
              maxLines: 2,
            ),
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                onPressed: _isLoading ? null : _activateToken,
                icon: const Icon(Icons.check_circle_outline_rounded),
                label: Text(isAm ? 'ፈቃድ አግብር' : 'Activate Token'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF0F766E),
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(vertical: 12),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildTelegramActionsCard(bool isAm) {
    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              isAm ? 'ፈቃድ ለመግዛት ወይም በነፃ ለመሞከር' : 'Buy License or Free Trial',
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
            ),
            const SizedBox(height: 6),
            Text(
              Localizations.localeOf(context).languageCode == 'om'
                  ? 'Botiin Telegram fayyadamuun hayyama yaalii fudhachuu ykn Telebirr fi CBE fayyadamuun bitachuu dandeessu.'
                  : (isAm
                      ? 'የቴሌግራም ቦቱን በመጠቀም የሙከራ ፈቃድ መውሰድ ወይም በቴሌብር እና በሲቢኢ መግዛት ይችላሉ።'
                      : 'Open our official Telegram bot to request a free trial or purchase a license via Telebirr or CBE.'),
              style: TextStyle(fontSize: 12.5, color: Colors.grey[600]),
            ),
            const SizedBox(height: 12),
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                onPressed: _openTelegramBot,
                icon: const Icon(Icons.send_rounded, color: Colors.blue),
                label: Text(
                  isAm ? 'በቴሌግራም ቦት ክፈት' : 'Open in Telegram Bot',
                  style: const TextStyle(fontWeight: FontWeight.bold),
                ),
                style: OutlinedButton.styleFrom(
                  padding: const EdgeInsets.symmetric(vertical: 12),
                  side: const BorderSide(color: Colors.blue),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildInfoRow(String label, String value) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: TextStyle(color: Colors.grey[600], fontSize: 13)),
        const SizedBox(width: 8),
        Flexible(
          child: Text(
            value,
            textAlign: TextAlign.end,
            style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
          ),
        ),
      ],
    );
  }
}
