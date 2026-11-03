import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:intl/intl.dart' as intl;

import 'app_localizations_am.dart';
import 'app_localizations_en.dart';

// ignore_for_file: type=lint

/// Callers can lookup localized strings with an instance of AppLocalizations
/// returned by `AppLocalizations.of(context)`.
///
/// Applications need to include `AppLocalizations.delegate()` in their app's
/// `localizationDelegates` list, and the locales they support in the app's
/// `supportedLocales` list. For example:
///
/// ```dart
/// import 'l10n/app_localizations.dart';
///
/// return MaterialApp(
///   localizationsDelegates: AppLocalizations.localizationsDelegates,
///   supportedLocales: AppLocalizations.supportedLocales,
///   home: MyApplicationHome(),
/// );
/// ```
///
/// ## Update pubspec.yaml
///
/// Please make sure to update your pubspec.yaml to include the following
/// packages:
///
/// ```yaml
/// dependencies:
///   # Internationalization support.
///   flutter_localizations:
///     sdk: flutter
///   intl: any # Use the pinned version from flutter_localizations
///
///   # Rest of dependencies
/// ```
///
/// ## iOS Applications
///
/// iOS applications define key application metadata, including supported
/// locales, in an Info.plist file that is built into the application bundle.
/// To configure the locales supported by your app, you’ll need to edit this
/// file.
///
/// First, open your project’s ios/Runner.xcworkspace Xcode workspace file.
/// Then, in the Project Navigator, open the Info.plist file under the Runner
/// project’s Runner folder.
///
/// Next, select the Information Property List item, select Add Item from the
/// Editor menu, then select Localizations from the pop-up menu.
///
/// Select and expand the newly-created Localizations item then, for each
/// locale your application supports, add a new item and select the locale
/// you wish to add from the pop-up menu in the Value field. This list should
/// be consistent with the languages listed in the AppLocalizations.supportedLocales
/// property.
abstract class AppLocalizations {
  AppLocalizations(String locale)
    : localeName = intl.Intl.canonicalizedLocale(locale.toString());

  final String localeName;

  static AppLocalizations? of(BuildContext context) {
    return Localizations.of<AppLocalizations>(context, AppLocalizations);
  }

  static const LocalizationsDelegate<AppLocalizations> delegate =
      _AppLocalizationsDelegate();

  /// A list of this localizations delegate along with the default localizations
  /// delegates.
  ///
  /// Returns a list of localizations delegates containing this delegate along with
  /// GlobalMaterialLocalizations.delegate, GlobalCupertinoLocalizations.delegate,
  /// and GlobalWidgetsLocalizations.delegate.
  ///
  /// Additional delegates can be added by appending to this list in
  /// MaterialApp. This list does not have to be used at all if a custom list
  /// of delegates is preferred or required.
  static const List<LocalizationsDelegate<dynamic>> localizationsDelegates =
      <LocalizationsDelegate<dynamic>>[
        delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
      ];

  /// A list of this localizations delegate's supported locales.
  static const List<Locale> supportedLocales = <Locale>[
    Locale('am'),
    Locale('en'),
  ];

  /// No description provided for @appTitle.
  ///
  /// In en, this message translates to:
  /// **'Inventory'**
  String get appTitle;

  /// No description provided for @todaySales.
  ///
  /// In en, this message translates to:
  /// **'Today\'s Sales'**
  String get todaySales;

  /// No description provided for @lowStockAlert.
  ///
  /// In en, this message translates to:
  /// **'Low Stock Items'**
  String get lowStockAlert;

  /// No description provided for @btnSale.
  ///
  /// In en, this message translates to:
  /// **'Sale'**
  String get btnSale;

  /// No description provided for @btnStockIn.
  ///
  /// In en, this message translates to:
  /// **'Stock In'**
  String get btnStockIn;

  /// No description provided for @btnProducts.
  ///
  /// In en, this message translates to:
  /// **'Products'**
  String get btnProducts;

  /// No description provided for @btnAdjust.
  ///
  /// In en, this message translates to:
  /// **'Adjust'**
  String get btnAdjust;

  /// No description provided for @btnHistory.
  ///
  /// In en, this message translates to:
  /// **'History'**
  String get btnHistory;

  /// No description provided for @btnSettings.
  ///
  /// In en, this message translates to:
  /// **'Settings'**
  String get btnSettings;

  /// No description provided for @productsTitle.
  ///
  /// In en, this message translates to:
  /// **'Products'**
  String get productsTitle;

  /// No description provided for @searchHint.
  ///
  /// In en, this message translates to:
  /// **'Search products (e.g. Sugar, Teff)...'**
  String get searchHint;

  /// No description provided for @addProduct.
  ///
  /// In en, this message translates to:
  /// **'Add Product'**
  String get addProduct;

  /// No description provided for @editProduct.
  ///
  /// In en, this message translates to:
  /// **'Edit Product'**
  String get editProduct;

  /// No description provided for @deleteProduct.
  ///
  /// In en, this message translates to:
  /// **'Delete Product'**
  String get deleteProduct;

  /// No description provided for @productName.
  ///
  /// In en, this message translates to:
  /// **'Product Name'**
  String get productName;

  /// No description provided for @sellPrice.
  ///
  /// In en, this message translates to:
  /// **'Selling Price (ETB)'**
  String get sellPrice;

  /// No description provided for @costPrice.
  ///
  /// In en, this message translates to:
  /// **'Cost Price (ETB)'**
  String get costPrice;

  /// No description provided for @unit.
  ///
  /// In en, this message translates to:
  /// **'Unit'**
  String get unit;

  /// No description provided for @currentStock.
  ///
  /// In en, this message translates to:
  /// **'Current Stock'**
  String get currentStock;

  /// No description provided for @lowStockLimit.
  ///
  /// In en, this message translates to:
  /// **'Low Stock Threshold'**
  String get lowStockLimit;

  /// No description provided for @saleTitle.
  ///
  /// In en, this message translates to:
  /// **'New Sale'**
  String get saleTitle;

  /// No description provided for @recentProducts.
  ///
  /// In en, this message translates to:
  /// **'Recent Items'**
  String get recentProducts;

  /// No description provided for @customQty.
  ///
  /// In en, this message translates to:
  /// **'Custom'**
  String get customQty;

  /// No description provided for @cartTitle.
  ///
  /// In en, this message translates to:
  /// **'Shopping Cart'**
  String get cartTitle;

  /// No description provided for @finishSale.
  ///
  /// In en, this message translates to:
  /// **'Finish Sale'**
  String get finishSale;

  /// No description provided for @totalAmount.
  ///
  /// In en, this message translates to:
  /// **'Total'**
  String get totalAmount;

  /// No description provided for @emptyCart.
  ///
  /// In en, this message translates to:
  /// **'Cart is empty. Tap products to add.'**
  String get emptyCart;

  /// No description provided for @insufficientStockWarning.
  ///
  /// In en, this message translates to:
  /// **'Warning: Insufficient stock!'**
  String get insufficientStockWarning;

  /// No description provided for @adjustTitle.
  ///
  /// In en, this message translates to:
  /// **'Stock Adjustment'**
  String get adjustTitle;

  /// No description provided for @countedQty.
  ///
  /// In en, this message translates to:
  /// **'Actual Physical Count'**
  String get countedQty;

  /// No description provided for @selectReason.
  ///
  /// In en, this message translates to:
  /// **'Adjustment Reason'**
  String get selectReason;

  /// No description provided for @reasonDamaged.
  ///
  /// In en, this message translates to:
  /// **'Damaged / Spoilage'**
  String get reasonDamaged;

  /// No description provided for @reasonLost.
  ///
  /// In en, this message translates to:
  /// **'Lost / Shrinkage'**
  String get reasonLost;

  /// No description provided for @reasonCountCorrection.
  ///
  /// In en, this message translates to:
  /// **'Count Correction'**
  String get reasonCountCorrection;

  /// No description provided for @reasonOther.
  ///
  /// In en, this message translates to:
  /// **'Other'**
  String get reasonOther;

  /// No description provided for @difference.
  ///
  /// In en, this message translates to:
  /// **'Difference'**
  String get difference;

  /// No description provided for @confirmAdjust.
  ///
  /// In en, this message translates to:
  /// **'Confirm Adjustment'**
  String get confirmAdjust;

  /// No description provided for @historyTitle.
  ///
  /// In en, this message translates to:
  /// **'Movement Ledger'**
  String get historyTitle;

  /// No description provided for @undoLastAction.
  ///
  /// In en, this message translates to:
  /// **'Undo Last Action'**
  String get undoLastAction;

  /// No description provided for @undoConfirm.
  ///
  /// In en, this message translates to:
  /// **'Undo this transaction? (Available within 10 min)'**
  String get undoConfirm;

  /// No description provided for @undoSuccess.
  ///
  /// In en, this message translates to:
  /// **'Transaction reversed successfully'**
  String get undoSuccess;

  /// No description provided for @filterAll.
  ///
  /// In en, this message translates to:
  /// **'All'**
  String get filterAll;

  /// No description provided for @filterSales.
  ///
  /// In en, this message translates to:
  /// **'Sales'**
  String get filterSales;

  /// No description provided for @filterIntake.
  ///
  /// In en, this message translates to:
  /// **'Stock In'**
  String get filterIntake;

  /// No description provided for @filterAdjust.
  ///
  /// In en, this message translates to:
  /// **'Adjustments'**
  String get filterAdjust;

  /// No description provided for @welcomeTitle.
  ///
  /// In en, this message translates to:
  /// **'Welcome to Inventory'**
  String get welcomeTitle;

  /// No description provided for @chooseLanguage.
  ///
  /// In en, this message translates to:
  /// **'Choose Language'**
  String get chooseLanguage;

  /// No description provided for @shopNamePrompt.
  ///
  /// In en, this message translates to:
  /// **'Shop Name'**
  String get shopNamePrompt;

  /// No description provided for @appLockPrompt.
  ///
  /// In en, this message translates to:
  /// **'Protect with PIN / Biometrics'**
  String get appLockPrompt;

  /// No description provided for @startUsingApp.
  ///
  /// In en, this message translates to:
  /// **'Get Started'**
  String get startUsingApp;

  /// No description provided for @cancel.
  ///
  /// In en, this message translates to:
  /// **'Cancel'**
  String get cancel;

  /// No description provided for @save.
  ///
  /// In en, this message translates to:
  /// **'Save'**
  String get save;

  /// No description provided for @confirm.
  ///
  /// In en, this message translates to:
  /// **'Confirm'**
  String get confirm;

  /// No description provided for @delete.
  ///
  /// In en, this message translates to:
  /// **'Delete'**
  String get delete;

  /// No description provided for @checkStock.
  ///
  /// In en, this message translates to:
  /// **'Check Stock'**
  String get checkStock;
}

class _AppLocalizationsDelegate
    extends LocalizationsDelegate<AppLocalizations> {
  const _AppLocalizationsDelegate();

  @override
  Future<AppLocalizations> load(Locale locale) {
    return SynchronousFuture<AppLocalizations>(lookupAppLocalizations(locale));
  }

  @override
  bool isSupported(Locale locale) =>
      <String>['am', 'en'].contains(locale.languageCode);

  @override
  bool shouldReload(_AppLocalizationsDelegate old) => false;
}

AppLocalizations lookupAppLocalizations(Locale locale) {
  // Lookup logic when only language code is specified.
  switch (locale.languageCode) {
    case 'am':
      return AppLocalizationsAm();
    case 'en':
      return AppLocalizationsEn();
  }

  throw FlutterError(
    'AppLocalizations.delegate failed to load unsupported locale "$locale". This is likely '
    'an issue with the localizations generation tool. Please file an issue '
    'on GitHub with a reproducible sample app and the gen-l10n configuration '
    'that was used.',
  );
}
