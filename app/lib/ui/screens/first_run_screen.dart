// ignore_for_file: deprecated_member_use
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

  // Step 0: Shop Info & Language, Step 1: Locations Question, Step 2: 3-Card Intro
  int _currentStep = 0;
  int _introCardIndex = 0;
  final PageController _introPageController = PageController();

  // Locations choices
  bool _enableLocations = false; // Default: No, one place
  String _stockPlacement = 'store'; // 'store' | 'warehouse' | 'split_later'
  bool _isSaving = false;

  @override
  void dispose() {
    _shopNameController.dispose();
    _pinController.dispose();
    _introPageController.dispose();
    super.dispose();
  }

  void _onNextFromStep0() {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _currentStep = 1;
    });
  }

  Future<void> _submitAll() async {
    setState(() => _isSaving = true);
    try {
      final shopName = _shopNameController.text.trim();
      await widget.repository.setShopName(shopName);
      await widget.repository.setLanguage(_selectedLang);
      await widget.repository.setAppLockEnabled(_enablePin);
      if (_enablePin && _pinController.text.isNotEmpty) {
        await widget.repository.setAppLockPin(_pinController.text.trim());
      }

      if (_enableLocations) {
        await widget.repository.enableWarehouseAndStore(stockPlacement: _stockPlacement);
      } else {
        await widget.repository.setLocationsEnabled(false);
        await widget.repository.setLocationsPromptDismissed(true);
      }

      await widget.repository.setFirstRunCompleted(true);
      widget.onComplete();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error saving setup: $e'), backgroundColor: Colors.red),
        );
      }
    } finally {
      if (mounted) setState(() => _isSaving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final isAm = _selectedLang == 'am';

    return Scaffold(
      body: SafeArea(
        child: _currentStep == 2
            ? _buildStep2(isAm)
            : Center(
                child: SingleChildScrollView(
                  padding: const EdgeInsets.all(24),
                  child: _currentStep == 0 ? _buildStep0(isAm) : _buildStep1(isAm),
                ),
              ),
      ),
    );
  }

  Widget _buildStep0(bool isAm) {
    return Form(
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
              labelText: isAm ? 'የሱቅ ስም' : 'Shop Name',
              hintText: isAm ? 'ለምሳሌ: መርካቶ ሱፐርማርኬት' : 'e.g. Merkato Supermarket',
              border: const OutlineInputBorder(),
              prefixIcon: const Icon(Icons.storefront),
            ),
            validator: (val) {
              if (val == null || val.trim().isEmpty) {
                return isAm
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
                      isAm
                          ? 'መተግበሪያውን በኮድ ቆልፍ (አማራጭ)'
                          : 'Lock App with PIN (Optional)',
                      style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
                    ),
                    subtitle: Text(
                      isAm
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
                        labelText: isAm ? 'ባለ 4-አሃዝ ኮድ (PIN)' : '4-digit PIN',
                        border: const OutlineInputBorder(),
                        prefixIcon: const Icon(Icons.lock_outline),
                      ),
                      validator: (val) {
                        if (_enablePin && (val == null || val.trim().length < 4)) {
                          return isAm
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
            onPressed: _onNextFromStep0,
            child: Text(
              isAm ? 'ቀጥል / Next' : 'Next',
              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildStep1(bool isAm) {
    return Column(
      mainAxisAlignment: MainAxisAlignment.center,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Icon(
          Icons.warehouse_rounded,
          size: 64,
          color: Color(0xFF0F766E),
        ),
        const SizedBox(height: 16),
        Text(
          isAm
              ? 'ዕቃ ከአንድ ቦታ በላይ ያስቀምጣሉ?'
              : 'Do you keep stock in more than one place?',
          textAlign: TextAlign.center,
          style: const TextStyle(
            fontSize: 20,
            fontWeight: FontWeight.bold,
            height: 1.3,
          ),
        ),
        const SizedBox(height: 8),
        Text(
          isAm
              ? 'ለሱቅ እና ለመጋዘን የተለያዩ ስቶኮችን መያዝ እና ማዘዋወር ይችላሉ።'
              : 'Track separate stock for warehouse and shop front with transfers.',
          textAlign: TextAlign.center,
          style: const TextStyle(fontSize: 13, color: Colors.grey),
        ),
        const SizedBox(height: 24),

        // Option 1: No, one place (default)
        InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: () => setState(() => _enableLocations = false),
          child: Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: !_enableLocations ? const Color(0xFF0F766E).withValues(alpha: 0.1) : Colors.white,
              border: Border.all(
                color: !_enableLocations ? const Color(0xFF0F766E) : Colors.grey.shade300,
                width: !_enableLocations ? 2 : 1,
              ),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Row(
              children: [
                Icon(
                  Icons.storefront_rounded,
                  size: 32,
                  color: !_enableLocations ? const Color(0xFF0F766E) : Colors.grey,
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        isAm ? 'አይ፣ በአንድ ቦታ (No, one place)' : 'No, one place',
                        style: TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.bold,
                          color: !_enableLocations ? const Color(0xFF0F766E) : Colors.black87,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        isAm
                            ? 'ሁሉም ዕቃ በአንድ ሱቅ ውስጥ ብቻ ይቀመጣል። (ነባሪ)'
                            : 'All stock is kept in one place. (Default)',
                        style: const TextStyle(fontSize: 12.5, color: Colors.grey),
                      ),
                    ],
                  ),
                ),
                Radio<bool>(
                  value: false,
                  groupValue: _enableLocations,
                  onChanged: (v) => setState(() => _enableLocations = v!),
                  activeColor: const Color(0xFF0F766E),
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 12),

        // Option 2: Yes, warehouse and store
        InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: () => setState(() => _enableLocations = true),
          child: Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: _enableLocations ? const Color(0xFF0F766E).withValues(alpha: 0.1) : Colors.white,
              border: Border.all(
                color: _enableLocations ? const Color(0xFF0F766E) : Colors.grey.shade300,
                width: _enableLocations ? 2 : 1,
              ),
              borderRadius: BorderRadius.circular(12),
            ),
            child: Row(
              children: [
                Icon(
                  Icons.warehouse_rounded,
                  size: 32,
                  color: _enableLocations ? const Color(0xFF0F766E) : Colors.grey,
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        isAm ? 'አዎ፣ መጋዘን እና ሱቅ (Yes, warehouse & store)' : 'Yes, warehouse and store',
                        style: TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.bold,
                          color: _enableLocations ? const Color(0xFF0F766E) : Colors.black87,
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        isAm
                            ? 'የሱቅ እና የመጋዘን ዕቃዎችን ለይተው ይያዙ፣ ዝውውርም ያድርጉ።'
                            : 'Separate stock for Store and Warehouse, with transfers.',
                        style: const TextStyle(fontSize: 12.5, color: Colors.grey),
                      ),
                    ],
                  ),
                ),
                Radio<bool>(
                  value: true,
                  groupValue: _enableLocations,
                  onChanged: (v) => setState(() => _enableLocations = v!),
                  activeColor: const Color(0xFF0F766E),
                ),
              ],
            ),
          ),
        ),

        // If Yes, ask where current stock lives
        if (_enableLocations) ...[
          const SizedBox(height: 20),
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: Colors.grey.shade50,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: Colors.grey.shade200),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  isAm
                      ? 'አሁን ያለው ዕቃ የት ነው የሚገኘው?'
                      : 'Where does your current stock live?',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                ),
                const SizedBox(height: 8),
                RadioListTile<String>(
                  contentPadding: EdgeInsets.zero,
                  value: 'store',
                  groupValue: _stockPlacement,
                  activeColor: const Color(0xFF0F766E),
                  title: Text(isAm ? 'ሁሉም በሱቅ ውስጥ' : 'All in Store'),
                  subtitle: Text(isAm ? 'አሁን ያሉት ዕቃዎች በሙሉ ወደ ሱቅ ይመደባሉ' : 'All existing stock goes to Store'),
                  onChanged: (v) => setState(() => _stockPlacement = v!),
                ),
                RadioListTile<String>(
                  contentPadding: EdgeInsets.zero,
                  value: 'warehouse',
                  groupValue: _stockPlacement,
                  activeColor: const Color(0xFF0F766E),
                  title: Text(isAm ? 'ሁሉም በመጋዘን ውስጥ' : 'All in Warehouse'),
                  subtitle: Text(isAm ? 'አሁን ያሉት ዕቃዎች በሙሉ ወደ መጋዘን ይመደባሉ' : 'All existing stock goes to Warehouse'),
                  onChanged: (v) => setState(() => _stockPlacement = v!),
                ),
                RadioListTile<String>(
                  contentPadding: EdgeInsets.zero,
                  value: 'split_later',
                  groupValue: _stockPlacement,
                  activeColor: const Color(0xFF0F766E),
                  title: Text(isAm ? 'ቆይቼ እከፍላለሁ' : "I'll split it later"),
                  subtitle: Text(isAm ? 'ሁሉም በሱቅ ይቀመጣሉ፤ በኋላ በዝውውር ማስተካከል ይችላሉ' : 'Keeps stock in Store. Transfer or adjust later.'),
                  onChanged: (v) => setState(() => _stockPlacement = v!),
                ),
                if (_stockPlacement == 'split_later')
                  Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: Text(
                      isAm
                          ? 'ℹ️ ማስታወሻ፡ ሁሉም ዕቃ በሱቅ ውስጥ ይጀምራል። ወደ መጋዘን ለማዘዋወር "ዝውውር" (Transfer) ይጠቀሙ።'
                          : 'ℹ️ Hint: All stock starts in Store. Use Transfer or Adjust later to move stock to Warehouse.',
                      style: const TextStyle(fontSize: 12, color: Colors.blueGrey),
                    ),
                  ),
              ],
            ),
          ),
        ],

        const SizedBox(height: 24),

        Row(
          children: [
            OutlinedButton(
              onPressed: _isSaving ? null : () => setState(() => _currentStep = 0),
              style: OutlinedButton.styleFrom(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              ),
              child: Text(isAm ? 'ተመለስ' : 'Back'),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: FilledButton(
                style: FilledButton.styleFrom(
                  backgroundColor: const Color(0xFF0F766E),
                  padding: const EdgeInsets.symmetric(vertical: 16),
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                ),
                onPressed: () => setState(() => _currentStep = 2),
                child: Text(
                  isAm ? 'ቀጥል / Next' : 'Next',
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                ),
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildStep2(bool isAm) {
    final cards = [
      _IntroCardData(
        icon: Icons.wifi_off_rounded,
        title: isAm ? '100% ያለ ኢንተርኔት ይሰራል' : 'Works 100% Offline',
        tagline: isAm ? 'ምንም አይነት ኔትወርክ አያስፈልግዎትም' : 'No internet or mobile data needed',
        description: isAm
            ? 'ሁሉም የዕቃ መዝገቦችዎ እና ሽያጮችዎ በስልክዎ ላይ በደህንነት ይቀመጣሉ። በማንኛውም ጊዜ እና ቦታ ፈጣን ሽያጭ ማካሄድ ይችላሉ።'
            : 'All your stock records and sales stay safely on this device. Make sales instantly anywhere without network delays or server downtime.',
      ),
      _IntroCardData(
        icon: Icons.flash_on_rounded,
        title: isAm ? 'በጥቂት ንክኪዎች ሽያጭ ያካሂዱ' : 'Sell in a Few Taps',
        tagline: isAm ? 'ፈጣን እና ምቹ የሽያጭ ስርዓት' : 'Fast and versatile point of sale',
        description: isAm
            ? 'በጥሬ ገንዘብ፣ በቴሌብር፣ በባንክ ወይም በዱቤ ይሽጡ። የደንበኞችን የዱቤ ክፍያ እና የቀሪ ሂሳብ በግልጽ ይከታተሉ።'
            : 'Accept Cash, Telebirr, Bank Transfer, or Dube (credit). Track customer balances and payment due dates with zero hassle.',
      ),
      _IntroCardData(
        icon: Icons.security_rounded,
        title: isAm ? 'መረጃዎን በየጊዜው ኮፒ ያድርጉ' : 'Back Up Your Data',
        tagline: isAm ? 'የስልክ መጥፋት ወይም መበላሸትን ይከላከሉ' : 'A lost phone means lost data!',
        description: isAm
            ? 'የመተግበሪያውን ዳታበዝ በቴሌግራም ወይም በክላውድ በቀላሉ ማጋራት ይችላሉ። ስልክዎ ቢጠፋ እንኳን ንግድዎ አይቆምም።'
            : 'Export and share your database backup to Telegram Saved Messages or Google Drive anytime. Keep your business safe even if a phone is lost.',
      ),
    ];

    return Column(
      children: [
        // Top Bar with Skip Button
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                '${_introCardIndex + 1} / 3',
                style: const TextStyle(fontWeight: FontWeight.bold, color: Colors.grey),
              ),
              TextButton(
                onPressed: _isSaving ? null : _submitAll,
                child: Text(
                  isAm ? 'ዝለል / Skip' : 'Skip',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                ),
              ),
            ],
          ),
        ),
        // Expanded PageView
        Expanded(
          child: PageView.builder(
            controller: _introPageController,
            itemCount: cards.length,
            onPageChanged: (idx) => setState(() => _introCardIndex = idx),
            itemBuilder: (context, index) {
              final item = cards[index];
              return SingleChildScrollView(
                padding: const EdgeInsets.symmetric(horizontal: 28, vertical: 16),
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const SizedBox(height: 20),
                    Container(
                      padding: const EdgeInsets.all(28),
                      decoration: BoxDecoration(
                        color: const Color(0xFF0F766E).withValues(alpha: 0.12),
                        shape: BoxShape.circle,
                      ),
                      child: Icon(item.icon, size: 72, color: const Color(0xFF0F766E)),
                    ),
                    const SizedBox(height: 28),
                    Text(
                      item.title,
                      textAlign: TextAlign.center,
                      style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold),
                    ),
                    const SizedBox(height: 8),
                    Text(
                      item.tagline,
                      textAlign: TextAlign.center,
                      style: const TextStyle(
                        fontSize: 14,
                        fontWeight: FontWeight.w600,
                        color: Color(0xFF0F766E),
                      ),
                    ),
                    const SizedBox(height: 16),
                    Text(
                      item.description,
                      textAlign: TextAlign.center,
                      style: const TextStyle(fontSize: 14, height: 1.5, color: Colors.black87),
                    ),
                  ],
                ),
              );
            },
          ),
        ),
        // Bottom controls
        Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            children: [
              // Dots indicator
              Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: List.generate(3, (idx) {
                  final isCurrent = idx == _introCardIndex;
                  return AnimatedContainer(
                    duration: const Duration(milliseconds: 250),
                    margin: const EdgeInsets.symmetric(horizontal: 4),
                    width: isCurrent ? 24 : 8,
                    height: 8,
                    decoration: BoxDecoration(
                      color: isCurrent ? const Color(0xFF0F766E) : Colors.grey.shade300,
                      borderRadius: BorderRadius.circular(4),
                    ),
                  );
                }),
              ),
              const SizedBox(height: 20),
              SizedBox(
                width: double.infinity,
                child: FilledButton(
                  style: FilledButton.styleFrom(
                    backgroundColor: const Color(0xFF0F766E),
                    minimumSize: const Size(double.infinity, 50),
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                  ),
                  onPressed: _isSaving
                      ? null
                      : () {
                          if (_introCardIndex < 2) {
                            _introPageController.nextPage(
                              duration: const Duration(milliseconds: 300),
                              curve: Curves.easeInOut,
                            );
                          } else {
                            _submitAll();
                          }
                        },
                  child: _isSaving
                      ? const SizedBox(
                          width: 22,
                          height: 22,
                          child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                        )
                      : Text(
                          _introCardIndex == 2
                              ? (isAm ? 'መተግበሪያውን ጀምር / Start' : 'Get Started')
                              : (isAm ? 'ቀጥል / Next' : 'Next'),
                          style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                        ),
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class _IntroCardData {
  final IconData icon;
  final String title;
  final String tagline;
  final String description;

  const _IntroCardData({
    required this.icon,
    required this.title,
    required this.tagline,
    required this.description,
  });
}
