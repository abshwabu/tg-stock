// ignore_for_file: deprecated_member_use
import 'package:flutter/material.dart';

import '../../repositories/ledger_repository.dart';

/// Shows a bottom sheet to set up Warehouse & Store, asking where current stock lives.
Future<bool?> showWarehouseSetupSheet(
  BuildContext context, {
  required LedgerRepository repository,
  VoidCallback? onComplete,
}) {
  return showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
    ),
    builder: (ctx) => _WarehouseSetupSheetContent(
      repository: repository,
      onComplete: onComplete,
    ),
  );
}

class _WarehouseSetupSheetContent extends StatefulWidget {
  final LedgerRepository repository;
  final VoidCallback? onComplete;

  const _WarehouseSetupSheetContent({
    required this.repository,
    this.onComplete,
  });

  @override
  State<_WarehouseSetupSheetContent> createState() => _WarehouseSetupSheetContentState();
}

class _WarehouseSetupSheetContentState extends State<_WarehouseSetupSheetContent> {
  String _stockPlacement = 'store';
  bool _isLoading = false;

  Future<void> _submit() async {
    setState(() => _isLoading = true);
    try {
      await widget.repository.enableWarehouseAndStore(stockPlacement: _stockPlacement);

      if (mounted) {
        final isSplit = _stockPlacement == 'split_later';
        final msg = isSplit
            ? 'መጋዘን እና ሱቅ ተዘጋጅተዋል! ዕቃው በሙሉ በሱቅ ተቀምጧል፤ በዝውውር ወደ መጋዘን መላክ ይችላሉ።'
            : 'መጋዘን እና ሱቅ በተሳካ ሁኔታ ተዘጋጅተዋል! / Warehouse & Store enabled!';

        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(msg),
            backgroundColor: const Color(0xFF0F766E),
            duration: const Duration(seconds: 4),
          ),
        );

        widget.onComplete?.call();
        Navigator.of(context).pop(true);
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Failed to enable: $e'),
            backgroundColor: Colors.red,
          ),
        );
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: EdgeInsets.only(
        left: 20,
        right: 20,
        top: 20,
        bottom: MediaQuery.of(context).viewInsets.bottom + 24,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Center(
            child: Container(
              width: 40,
              height: 4,
              decoration: BoxDecoration(
                color: Colors.grey.shade300,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
          ),
          const SizedBox(height: 16),
          const Row(
            children: [
              Icon(Icons.warehouse_rounded, color: Color(0xFF0F766E), size: 28),
              SizedBox(width: 10),
              Expanded(
                child: Text(
                  'መጋዘን እና ሱቅ ማዘጋጀት\nSet Up Warehouse & Store',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, height: 1.2),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          const Text(
            'አሁን ያለው ዕቃ የት ነው የሚገኘው?\nWhere does your current stock live?',
            style: TextStyle(fontSize: 14, fontWeight: FontWeight.w600, color: Colors.black87),
          ),
          const SizedBox(height: 12),

          // Options
          RadioListTile<String>(
            value: 'store',
            groupValue: _stockPlacement,
            onChanged: _isLoading ? null : (v) => setState(() => _stockPlacement = v!),
            title: const Text('ሁሉም በሱቅ ውስጥ / All in Store', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
            subtitle: const Text('አሁን ያሉት ዕቃዎች በሙሉ ወደ "Store" ይመደባሉ', style: TextStyle(fontSize: 12)),
            activeColor: const Color(0xFF0F766E),
            contentPadding: EdgeInsets.zero,
          ),
          RadioListTile<String>(
            value: 'warehouse',
            groupValue: _stockPlacement,
            onChanged: _isLoading ? null : (v) => setState(() => _stockPlacement = v!),
            title: const Text('ሁሉም በመጋዘን ውስጥ / All in Warehouse', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
            subtitle: const Text('አሁን ያሉት ዕቃዎች በሙሉ ወደ "Warehouse" ይመደባሉ', style: TextStyle(fontSize: 12)),
            activeColor: const Color(0xFF0F766E),
            contentPadding: EdgeInsets.zero,
          ),
          RadioListTile<String>(
            value: 'split_later',
            groupValue: _stockPlacement,
            onChanged: _isLoading ? null : (v) => setState(() => _stockPlacement = v!),
            title: const Text('ቆይቼ እከፍላለሁ / I\'ll split it later', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
            subtitle: const Text('ሁሉም ለጊዜው በሱቅ ይቀመጣሉ። በኋላ በዝውውር (Transfer) መክፈል ይችላሉ።', style: TextStyle(fontSize: 12)),
            activeColor: const Color(0xFF0F766E),
            contentPadding: EdgeInsets.zero,
          ),

          if (_stockPlacement == 'split_later') ...[
            const SizedBox(height: 8),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: Colors.amber.shade50,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: Colors.amber.shade300),
              ),
              child: const Row(
                children: [
                  Icon(Icons.info_outline, color: Colors.amber, size: 20),
                  SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'Hint: Everything will stay in Store. Use Transfer or Adjust later to move stock to Warehouse.',
                      style: TextStyle(fontSize: 12, color: Colors.black87),
                    ),
                  ),
                ],
              ),
            ),
          ],

          const SizedBox(height: 20),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: const Color(0xFF0F766E),
              padding: const EdgeInsets.symmetric(vertical: 14),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
            ),
            onPressed: _isLoading ? null : _submit,
            child: _isLoading
                ? const SizedBox(
                    width: 20,
                    height: 20,
                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                  )
                : const Text(
                    'አግብር / Enable Warehouse & Store',
                    style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                  ),
          ),
        ],
      ),
    );
  }
}
