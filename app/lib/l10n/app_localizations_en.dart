// ignore: unused_import
import 'package:intl/intl.dart' as intl;
import 'app_localizations.dart';

// ignore_for_file: type=lint

/// The translations for English (`en`).
class AppLocalizationsEn extends AppLocalizations {
  AppLocalizationsEn([String locale = 'en']) : super(locale);

  @override
  String get appTitle => 'Inventory';

  @override
  String get todaySales => 'Today\'s Sales';

  @override
  String get lowStockAlert => 'Low Stock Items';

  @override
  String get btnSale => 'Sale';

  @override
  String get btnStockIn => 'Stock In';

  @override
  String get btnProducts => 'Products';

  @override
  String get btnAdjust => 'Adjust';

  @override
  String get btnHistory => 'History';

  @override
  String get btnSettings => 'Settings';

  @override
  String get productsTitle => 'Products';

  @override
  String get searchHint => 'Search products (e.g. Sugar, Teff)...';

  @override
  String get addProduct => 'Add Product';

  @override
  String get editProduct => 'Edit Product';

  @override
  String get deleteProduct => 'Delete Product';

  @override
  String get productName => 'Product Name';

  @override
  String get sellPrice => 'Selling Price (ETB)';

  @override
  String get costPrice => 'Cost Price (ETB)';

  @override
  String get unit => 'Unit';

  @override
  String get currentStock => 'Current Stock';

  @override
  String get lowStockLimit => 'Low Stock Threshold';

  @override
  String get saleTitle => 'New Sale';

  @override
  String get recentProducts => 'Recent Items';

  @override
  String get customQty => 'Custom';

  @override
  String get cartTitle => 'Shopping Cart';

  @override
  String get finishSale => 'Finish Sale';

  @override
  String get totalAmount => 'Total';

  @override
  String get emptyCart => 'Cart is empty. Tap products to add.';

  @override
  String get insufficientStockWarning => 'Warning: Insufficient stock!';

  @override
  String get adjustTitle => 'Stock Adjustment';

  @override
  String get countedQty => 'Actual Physical Count';

  @override
  String get selectReason => 'Adjustment Reason';

  @override
  String get reasonDamaged => 'Damaged / Spoilage';

  @override
  String get reasonLost => 'Lost / Shrinkage';

  @override
  String get reasonCountCorrection => 'Count Correction';

  @override
  String get reasonOther => 'Other';

  @override
  String get difference => 'Difference';

  @override
  String get confirmAdjust => 'Confirm Adjustment';

  @override
  String get historyTitle => 'Movement Ledger';

  @override
  String get undoLastAction => 'Undo Last Action';

  @override
  String get undoConfirm => 'Undo this transaction? (Available within 10 min)';

  @override
  String get undoSuccess => 'Transaction reversed successfully';

  @override
  String get filterAll => 'All';

  @override
  String get filterSales => 'Sales';

  @override
  String get filterIntake => 'Stock In';

  @override
  String get filterAdjust => 'Adjustments';

  @override
  String get welcomeTitle => 'Welcome to Inventory';

  @override
  String get chooseLanguage => 'Choose Language';

  @override
  String get shopNamePrompt => 'Shop Name';

  @override
  String get appLockPrompt => 'Protect with PIN / Biometrics';

  @override
  String get startUsingApp => 'Get Started';

  @override
  String get cancel => 'Cancel';

  @override
  String get save => 'Save';

  @override
  String get confirm => 'Confirm';

  @override
  String get delete => 'Delete';

  @override
  String get checkStock => 'Check Stock';

  @override
  String get reportsTitle => 'Reports & Analytics';

  @override
  String get today => 'Today';

  @override
  String get yesterday => 'Yesterday';

  @override
  String get thisWeek => 'This Week';

  @override
  String get thisMonth => 'This Month';

  @override
  String get customRange => 'Custom Range';

  @override
  String get revenue => 'Revenue';

  @override
  String get salesCount => 'Sales Count';

  @override
  String get estimatedProfit => 'Estimated Profit';

  @override
  String get topProducts => 'Top Products';

  @override
  String get topByRevenue => 'Top by Revenue';

  @override
  String get topByQty => 'Top by Quantity';

  @override
  String get stockReport => 'Stock Valuation';

  @override
  String get valueAtCost => 'Value at Cost Price';

  @override
  String get valueAtSelling => 'Value at Selling Price';

  @override
  String get exportCsv => 'Export CSV';

  @override
  String get backupRestore => 'Backup & Restore';

  @override
  String get createBackup => 'Create Backup';

  @override
  String get restoreBackup => 'Restore Backup';

  @override
  String get currencyLabel => 'Currency Label';

  @override
  String get notifications => 'Notifications';

  @override
  String get lowStockNotifications => 'Low Stock Notifications';

  @override
  String get dailySummary => 'Daily Summary Notification';

  @override
  String get dubeTitle => 'Dube / Pay Later';

  @override
  String get btnDube => 'Dube';

  @override
  String get cashInDrawer => 'Cash in Drawer';

  @override
  String get outstandingDube => 'Outstanding Dube';

  @override
  String get paymentMethods => 'Payment Methods';

  @override
  String get dueToday => 'Due Today';

  @override
  String get overdue => 'Overdue';

  @override
  String get kindProduct => 'Product';

  @override
  String get kindService => 'Service';

  @override
  String get kindConsumable => 'Consumable';

  @override
  String get kindMaterial => 'Raw Material';

  @override
  String get whatAreYouAdding => 'What are you adding?';

  @override
  String get askPriceEverySale => 'Ask the price at every sale';

  @override
  String get materialsUsed => 'Materials Used';

  @override
  String get salesByType => 'Sales by Type';

  @override
  String get blockSaleWhenMaterialShort =>
      'Block sale when raw materials are short';

  @override
  String get profitDisclaimer =>
      '* Profit based on current raw material costs, not a historical cost snapshot';

  @override
  String get cannotChangeKindWithStock =>
      'Cannot change kind: stock must be 0 across all locations';

  @override
  String get cannotDeleteUsedAsMaterial =>
      'Cannot delete: This product is used as a raw material in recipes';

  @override
  String get cannotChangeKindUsedAsMaterial =>
      'Cannot change kind: This product is used as a raw material in recipes';

  @override
  String get addMaterial => 'Add Raw Material';

  @override
  String get qtyPerUnit => 'Qty per 1 unit sold';

  @override
  String get estMaterialCost => 'Est. Material Cost';

  @override
  String get marginPerUnit => 'Margin per unit';

  @override
  String get noMaterialsAssigned => 'No materials assigned yet';

  @override
  String get shortageWarning => 'Material shortage';

  @override
  String get filterProducts => 'Products';

  @override
  String get filterServices => 'Services';

  @override
  String get filterConsumables => 'Consumables';

  @override
  String get filterMaterials => 'Materials';
}
