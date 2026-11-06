import 'package:flutter/material.dart';
import 'package:share_plus/share_plus.dart';

import '../../services/error_logger.dart';

class ErrorLogScreen extends StatefulWidget {
  final String language;

  const ErrorLogScreen({super.key, this.language = 'am'});

  @override
  State<ErrorLogScreen> createState() => _ErrorLogScreenState();
}

class _ErrorLogScreenState extends State<ErrorLogScreen> {
  void _shareReport() {
    final report = ErrorLogger.instance.formatSupportReport(language: widget.language);
    SharePlus.instance.share(
      ShareParams(
        text: report,
        subject: 'Inventory Diagnostics & Error Log',
      ),
    );
  }

  void _clearLogs() {
    setState(() {
      ErrorLogger.instance.clear();
    });
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          widget.language == 'am' ? 'የስህተት መዝገብ ጸድቷል' : 'Error log cleared',
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final isAm = widget.language == 'am';
    final logs = ErrorLogger.instance.logs;

    return Scaffold(
      appBar: AppBar(
        title: Text(isAm ? 'የስህተት መዝገብ (Diagnostics)' : 'Diagnostics & Error Log'),
        actions: [
          if (logs.isNotEmpty)
            IconButton(
              icon: const Icon(Icons.delete_outline),
              tooltip: isAm ? 'መዝገቡን አጽዳ' : 'Clear Log',
              onPressed: _clearLogs,
            ),
          IconButton(
            icon: const Icon(Icons.share_outlined),
            tooltip: isAm ? 'ሪፖርት ላክ' : 'Send Report to Support',
            onPressed: _shareReport,
          ),
        ],
      ),
      body: logs.isEmpty
          ? Center(
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const Icon(Icons.check_circle_outline, size: 64, color: Colors.green),
                    const SizedBox(height: 16),
                    Text(
                      isAm ? 'ምንም ስህተት አልተመዘገበም' : 'No Errors Logged',
                      style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      isAm
                          ? 'መተግበሪያው በጥሩ ሁኔታ እየሰራ ነው።'
                          : 'The app is running smoothly with zero logged issues.',
                      style: const TextStyle(color: Colors.grey),
                    ),
                  ],
                ),
              ),
            )
          : Column(
              children: [
                Container(
                  padding: const EdgeInsets.all(12),
                  color: Colors.blueGrey.shade50,
                  child: Row(
                    children: [
                      const Icon(Icons.info_outline, size: 20, color: Colors.blueGrey),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          isAm
                              ? 'ያለፉት ${logs.length} ስህተቶች (ምንም የግል መረጃ አይያዝም)'
                              : 'Last ${logs.length} errors (Sanitized, no personal data)',
                          style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w500),
                        ),
                      ),
                    ],
                  ),
                ),
                Expanded(
                  child: ListView.separated(
                    padding: const EdgeInsets.all(12),
                    itemCount: logs.length,
                    separatorBuilder: (_, __) => const Divider(height: 1),
                    itemBuilder: (context, index) {
                      final item = logs[index];
                      return ListTile(
                        leading: const Icon(Icons.error_outline, color: Colors.red),
                        title: Text(
                          item.message,
                          style: const TextStyle(fontSize: 14, fontWeight: FontWeight.bold),
                        ),
                        subtitle: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const SizedBox(height: 4),
                            Text(
                              '${item.context} • ${item.timestamp.toLocal().toString().split('.').first}',
                              style: const TextStyle(fontSize: 12, color: Colors.grey),
                            ),
                            if (item.stackSummary != null) ...[
                              const SizedBox(height: 4),
                              Text(
                                item.stackSummary!,
                                maxLines: 2,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(fontSize: 11, fontFamily: 'monospace'),
                              ),
                            ],
                          ],
                        ),
                      );
                    },
                  ),
                ),
                SafeArea(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: FilledButton.icon(
                      style: FilledButton.styleFrom(
                        minimumSize: const Size(double.infinity, 48),
                        backgroundColor: const Color(0xFF0F766E),
                      ),
                      onPressed: _shareReport,
                      icon: const Icon(Icons.send_rounded),
                      label: Text(
                        isAm ? 'ሪፖርቱን ለድጋፍ ሰጪ ላክ' : 'Send Report to Support',
                      ),
                    ),
                  ),
                ),
              ],
            ),
    );
  }
}
