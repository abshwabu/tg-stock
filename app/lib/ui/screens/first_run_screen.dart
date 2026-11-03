import 'package:flutter/material.dart';

import '../../repositories/ledger_repository.dart';

class FirstRunScreen extends StatefulWidget {
  final LedgerRepository repository;
  final VoidCallback onComplete;

  const FirstRunScreen({
    super.key,
    required this.repository,
    required this.onComplete,
  });

  @override
  State<FirstRunScreen> createState() => _FirstRunScreenState();
}

class _FirstRunScreenState extends State<FirstRunScreen> {
  final _formKey = GlobalKey<FormState>();
  final _shopNameController = TextEditingController();

  String _selectedLang = 'am'; // Default Amharic
  bool _enablePin = false;
  final _pinController = TextEditingController();

  @override
  void dispose() {
    _shopNameController.dispose();
    _pinController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    final shopName = _shopNameController.text.trim();
    await widget.repository.setShopName(shopName);
    await widget.repository.setLanguage(_selectedLang);
    await widget.repository.setAppLockEnabled(_enablePin);
    if (_enablePin && _pinController.text.isNotEmpty) {
      await widget.repository.setAppLockPin(_pinController.text.trim());
    }
    await widget.repository.setFirstRunCompleted(true);

    widget.onComplete();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Form(
              key: _formKey,
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Icon(
                    Icons.store_mall_directory_rounded,
                    size: 72,
                    color: Color(0xFF0F766E),
                  ),
                  const SizedBox(height: 16),
                  const Text(
                    'እንኳን ወደ ኢንቬንቶሪ በደህና መጡ\nWelcome to Inventory',
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontSize: 22,
                      fontWeight: FontWeight.bold,
                      height: 1.3,
                    ),
                  ),
                  const SizedBox(height: 8),
                  const Text(
                    'Offline-first inventory for Ethiopian retail shops.',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 13, color: Colors.grey),
                  ),
                  const SizedBox(height: 28),

                  // Language Selection
                  const Text(
                    'ቋንቋ ይምረጡ / Choose Language',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                  ),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton(
                          style: OutlinedButton.styleFrom(
                            backgroundColor: _selectedLang == 'am'
                                ? const Color(0xFF0F766E).withValues(alpha: 0.15)
                                : null,
                            side: BorderSide(
                              color: _selectedLang == 'am'
                                  ? const Color(0xFF0F766E)
                                  : Colors.grey.shade300,
                              width: _selectedLang == 'am' ? 2 : 1,
                            ),
                            padding: const EdgeInsets.symmetric(vertical: 14),
                          ),
                          onPressed: () => setState(() => _selectedLang = 'am'),
                          child: const Text(
                            'አማርኛ (Amharic)',
                            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                          ),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: OutlinedButton(
                          style: OutlinedButton.styleFrom(
                            backgroundColor: _selectedLang == 'en'
                                ? const Color(0xFF0F766E).withValues(alpha: 0.15)
                                : null,
                            side: BorderSide(
                              color: _selectedLang == 'en'
                                  ? const Color(0xFF0F766E)
                                  : Colors.grey.shade300,
                              width: _selectedLang == 'en' ? 2 : 1,
                            ),
                            padding: const EdgeInsets.symmetric(vertical: 14),
                          ),
                          onPressed: () => setState(() => _selectedLang = 'en'),
                          child: const Text(
                            'English',
                            style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 24),

                  // Shop Name
                  TextFormField(
                    controller: _shopNameController,
                    decoration: InputDecoration(
                      labelText: _selectedLang == 'am' ? 'የሱቅ ስም' : 'Shop Name',
                      hintText: _selectedLang == 'am' ? 'ለምሳሌ: መርካቶ ሱፐርማርኬት' : 'e.g. Merkato Supermarket',
                      border: const OutlineInputBorder(),
                      prefixIcon: const Icon(Icons.storefront),
                    ),
                    validator: (val) {
                      if (val == null || val.trim().isEmpty) {
                        return _selectedLang == 'am'
                            ? 'እባክዎ የሱቁን ስም ያስገቡ'
                            : 'Please enter your shop name';
                      }
                      return null;
                    },
                  ),
                  const SizedBox(height: 20),

                  // Optional App Lock
                  Card(
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                      child: Column(
                        children: [
                          SwitchListTile(
                            contentPadding: EdgeInsets.zero,
                            title: Text(
                              _selectedLang == 'am'
                                  ? 'መተግበሪያውን በኮድ ቆልፍ (አማራጭ)'
                                  : 'Lock App with PIN (Optional)',
                              style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
                            ),
                            subtitle: Text(
                              _selectedLang == 'am'
                                  ? 'በተጋራ ስልክ ላይ ያለ ፈቃድ እንዳይከፈት ይረዳል'
                                  : 'Protects POS on shared staff devices',
                              style: const TextStyle(fontSize: 12),
                            ),
                            value: _enablePin,
                            onChanged: (val) => setState(() => _enablePin = val),
                          ),
                          if (_enablePin) ...[
                            const SizedBox(height: 8),
                            TextFormField(
                              controller: _pinController,
                              keyboardType: TextInputType.number,
                              obscureText: true,
                              maxLength: 4,
                              decoration: InputDecoration(
                                labelText: _selectedLang == 'am' ? 'ባለ 4-አሃዝ ኮድ (PIN)' : '4-digit PIN',
                                border: const OutlineInputBorder(),
                                prefixIcon: const Icon(Icons.lock_outline),
                              ),
                              validator: (val) {
                                if (_enablePin && (val == null || val.trim().length < 4)) {
                                  return _selectedLang == 'am'
                                      ? 'ባለ 4-አሃዝ ኮድ ያስገቡ'
                                      : 'Enter a 4-digit PIN';
                                }
                                return null;
                              },
                            ),
                          ],
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 28),

                  FilledButton(
                    style: FilledButton.styleFrom(
                      backgroundColor: const Color(0xFF0F766E),
                      padding: const EdgeInsets.symmetric(vertical: 16),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                    ),
                    onPressed: _submit,
                    child: Text(
                      _selectedLang == 'am' ? 'መተግበሪያውን ጀምር / Start' : 'Get Started',
                      style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
