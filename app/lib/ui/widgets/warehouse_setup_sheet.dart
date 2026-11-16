// ignore_for_file: deprecated_member_use
import 'package:flutter/material.dart';

import '../../repositories/ledger_repository.dart';
import '../theme.dart';

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
        final isAm = Localizations.localeOf(context).languageCode == 'am';
        final msg = isSplit
            ? (isAm
                ? 'መጋዘን እና ሱቅ ተዘጋጅተዋል! ዕቃው በሙሉ በሱቅ ተቀምጧል፤ በዝውውር ወደ መጋዘን መላክ ይችላሉ።'
                : 'Warehouse & Store enabled! Stock is in Store; use Transfer to move stock.')
            : (isAm
                ? 'መጋዘን እና ሱቅ በተሳካ ሁኔታ ተዘጋጅተዋል!'
                : 'Warehouse & Store enabled!');

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
    final isAm = Localizations.localeOf(context).languageCode == 'am';

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
                color: Theme.of(context).colorScheme.outline,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
          ),
          const SizedBox(height: 16),
          Row(
            children: [
              Icon(Icons.warehouse_rounded, color: Theme.of(context).colorScheme.primary, size: 28),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  isAm ? 'መጋዘን እና ሱቅ ማዘጋጀት' : 'Set Up Warehouse & Store',
                  style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold, height: 1.2),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Text(
            isAm ? 'አሁን ያለው ዕቃ የት ነው የሚገኘው?' : 'Where does your current stock live?',
            style: TextStyle(
              fontSize: 14,
              fontWeight: FontWeight.w600,
              color: Theme.of(context).colorScheme.onSurface,
            ),
          ),
          const SizedBox(height: 12),

          // Options
          RadioListTile<String>(
            value: 'store',
            groupValue: _stockPlacement,
            onChanged: _isLoading ? null : (v) => setState(() => _stockPlacement = v!),
            title: Text(isAm ? 'ሁሉም በሱቅ ውስጥ' : 'All in Store', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
            subtitle: Text(isAm ? 'አሁን ያሉት ዕቃዎች በሙሉ ወደ ሱቅ ይመደባሉ' : 'All existing stock goes to Store', style: const TextStyle(fontSize: 12)),
            activeColor: Theme.of(context).colorScheme.primary,
            contentPadding: EdgeInsets.zero,
          ),
          RadioListTile<String>(
            value: 'warehouse',
            groupValue: _stockPlacement,
            onChanged: _isLoading ? null : (v) => setState(() => _stockPlacement = v!),
            title: Text(isAm ? 'ሁሉም በመጋዘን ውስጥ' : 'All in Warehouse', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
            subtitle: Text(isAm ? 'አሁን ያሉት ዕቃዎች በሙሉ ወደ መጋዘን ይመደባሉ' : 'All existing stock goes to Warehouse', style: const TextStyle(fontSize: 12)),
            activeColor: Theme.of(context).colorScheme.primary,
            contentPadding: EdgeInsets.zero,
          ),
          RadioListTile<String>(
            value: 'split_later',
            groupValue: _stockPlacement,
            onChanged: _isLoading ? null : (v) => setState(() => _stockPlacement = v!),
            title: Text(isAm ? 'ቆይቼ እከፍላለሁ' : "I'll split it later", style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
            subtitle: Text(isAm ? 'ሁሉም ለጊዜው በሱቅ ይቀመጣሉ። በኋላ በዝውውር ማስተካከል ይችላሉ።' : 'Keeps stock in Store. Transfer or adjust later.', style: const TextStyle(fontSize: 12)),
            activeColor: Theme.of(context).colorScheme.primary,
            contentPadding: EdgeInsets.zero,
          ),

          if (_stockPlacement == 'split_later') ...[
            const SizedBox(height: 8),
            Builder(builder: (ctx) {
              final isDark = Theme.of(ctx).brightness == Brightness.dark;
              final tokens = AppColors.warningTokens(isDark);
              return Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: tokens.bg,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: tokens.border),
                ),
                child: Row(
                  children: [
                    Icon(Icons.info_outline, color: tokens.fg, size: 20),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        isAm
                            ? 'ማስታወሻ፡ ሁሉም ዕቃ በሱቅ ውስጥ ይጀምራል። ወደ መጋዘን ለማዘዋወር "ዝውውር" ይጠቀሙ።'
                            : 'Hint: Everything will stay in Store. Use Transfer or Adjust later to move stock to Warehouse.',
                        style: TextStyle(
                          fontSize: 12,
                          color: Theme.of(ctx).colorScheme.onSurface,
                        ),
                      ),
                    ),
                  ],
                ),
              );
            }),
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
                : Text(
                    isAm ? 'አግብር' : 'Enable Warehouse & Store',
                    style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
                  ),
          ),
        ],
      ),
    );
  }
}
