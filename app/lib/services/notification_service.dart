import 'package:flutter_local_notifications/flutter_local_notifications.dart';

import '../database/app_database.dart';
import '../repositories/ledger_repository.dart';
import '../utils/money_formatter.dart';
import '../utils/quantity_formatter.dart';

class NotificationService {
  final FlutterLocalNotificationsPlugin _plugin;
  final LedgerRepository repository;

  NotificationService({
    FlutterLocalNotificationsPlugin? plugin,
    required this.repository,
  }) : _plugin = plugin ?? FlutterLocalNotificationsPlugin();

  static const String lowStockChannelId = 'low_stock_channel';
  static const String dailySummaryChannelId = 'daily_summary_channel';

  /// Initializes the local notification plugin and configures notification channels.
  Future<void> init() async {
    try {
      const androidSettings = AndroidInitializationSettings('@mipmap/ic_launcher');
      const darwinSettings = DarwinInitializationSettings(
        requestAlertPermission: false,
        requestBadgePermission: false,
        requestSoundPermission: false,
      );
      const settings = InitializationSettings(
        android: androidSettings,
        iOS: darwinSettings,
        macOS: darwinSettings,
      );

      await _plugin.initialize(settings: settings);

      // Request notification permissions for Android 13+
      await _plugin
          .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
          ?.requestNotificationsPermission();
    } catch (_) {
      // Gracefully handles test runners or unsupported headless environments.
    }
  }

  /// Triggers a low stock warning when a sale or adjustment causes stock to drop to/below threshold.
  Future<void> notifyLowStock({
    required Product product,
    required int currentStockMilli,
  }) async {
    try {
      final masterEnabled = await repository.isNotificationsEnabled();
      final lowStockEnabled = await repository.isLowStockNotificationsEnabled();
      if (!masterEnabled || !lowStockEnabled) return;

      final threshold = product.lowStockAtMilli;
      if (threshold == null || currentStockMilli > threshold) return;

      final availableDisplay = QuantityFormatter.formatDisplay(currentStockMilli);
      final thresholdDisplay = QuantityFormatter.formatDisplay(threshold);
      final id = (product.id.hashCode & 0x7FFFFFFF) % 100000;

      const androidDetails = AndroidNotificationDetails(
        lowStockChannelId,
        'Low Stock Alerts / የአነስተኛ ክምችት ማስጠንቀቂያ',
        channelDescription: 'Alerts when inventory levels drop to or below threshold',
        importance: Importance.high,
        priority: Priority.high,
        icon: '@mipmap/ic_launcher',
      );
      const details = NotificationDetails(
        android: androidDetails,
        iOS: DarwinNotificationDetails(),
      );

      await _plugin.show(
        id: id,
        title: '⚠️ Low Stock Alert: ${product.name}',
        body: 'Only $availableDisplay ${product.unit} remaining (Threshold: $thresholdDisplay ${product.unit}).',
        notificationDetails: details,
      );
    } catch (_) {}
  }

  /// Configures or cancels the daily sales summary reminder locally.
  Future<void> scheduleDailySummary({
    required bool enabled,
    String? timeStr,
  }) async {
    try {
      await _plugin.cancel(id: 99999);
      if (!enabled) return;

      final masterEnabled = await repository.isNotificationsEnabled();
      if (!masterEnabled) return;

      const androidDetails = AndroidNotificationDetails(
        dailySummaryChannelId,
        'Daily Summary / የቀን ማጠቃለያ',
        channelDescription: 'Daily evening reminder to review sales and inventory',
        importance: Importance.defaultImportance,
        priority: Priority.defaultPriority,
        icon: '@mipmap/ic_launcher',
      );
      const details = NotificationDetails(
        android: androidDetails,
        iOS: DarwinNotificationDetails(),
      );

      await _plugin.periodicallyShow(
        id: 99999,
        title: '📊 Daily Sales Summary / የቀን ማጠቃለያ',
        body: 'Review your shop\'s daily performance and low stock items in Inventory.',
        repeatInterval: RepeatInterval.daily,
        notificationDetails: details,
        androidScheduleMode: AndroidScheduleMode.inexact,
      );
    } catch (_) {}
  }

  /// Manually dispatches today\'s sales summary notification on demand.
  Future<void> showDailySummaryNow() async {
    try {
      final totalCents = await repository.todaySalesTotalCents();
      final lowCount = await repository.lowStockCount();
      final formattedTotal = MoneyFormatter.formatCents(totalCents);

      const androidDetails = AndroidNotificationDetails(
        dailySummaryChannelId,
        'Daily Summary / የቀን ማጠቃለያ',
        importance: Importance.high,
        priority: Priority.high,
        icon: '@mipmap/ic_launcher',
      );
      const details = NotificationDetails(
        android: androidDetails,
        iOS: DarwinNotificationDetails(),
      );

      await _plugin.show(
        id: 99998,
        title: '📊 Today\'s Summary / የዛሬ ማጠቃለያ',
        body: 'Total sales: $formattedTotal. Low stock items: $lowCount.',
        notificationDetails: details,
      );
    } catch (_) {}
  }
}
