import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:intl/intl.dart';

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
  static const String dubeReminderChannelId = 'dube_reminder_channel';

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
    } catch (_) {
      // Gracefully handles test runners or unsupported headless environments.
    }
  }

  /// Requests notification permissions on Android 13+ only on user opt-in
  Future<bool?> requestPermission() async {
    try {
      return await _plugin
          .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
          ?.requestNotificationsPermission();
    } catch (_) {
      return null;
    }
  }

  /// Triggers a low stock warning when a sale or adjustment causes stock to drop to/below threshold.
  Future<void> notifyLowStock({
    required Product product,
    required int currentStockMilli,
    String? locationName,
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
      final isAm = (await repository.getLanguage()) == 'am';

      final androidDetails = AndroidNotificationDetails(
        lowStockChannelId,
        isAm ? 'የአነስተኛ ክምችት ማስጠንቀቂያ' : 'Low Stock Alerts',
        channelDescription: 'Alerts when inventory levels drop to or below threshold',
        importance: Importance.high,
        priority: Priority.high,
        icon: '@mipmap/ic_launcher',
      );
      final details = NotificationDetails(
        android: androidDetails,
        iOS: const DarwinNotificationDetails(),
      );

      final locSuffix = (locationName != null && locationName.isNotEmpty) ? ' ($locationName)' : '';
      await _plugin.show(
        id: id,
        title: isAm
            ? '⚠️ የአነስተኛ ክምችት ማስጠንቀቂያ: ${product.name}$locSuffix'
            : '⚠️ Low Stock Alert: ${product.name}$locSuffix',
        body: isAm
            ? 'በቂ ክምችት የለም: $availableDisplay ${product.unit} ብቻ ቀርቷል (ዝቅተኛ መጠን: $thresholdDisplay ${product.unit})'
            : 'Only $availableDisplay ${product.unit} remaining$locSuffix (Threshold: $thresholdDisplay ${product.unit}).',
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

      final isAm = (await repository.getLanguage()) == 'am';

      final androidDetails = AndroidNotificationDetails(
        dailySummaryChannelId,
        isAm ? 'የቀን ማጠቃለያ' : 'Daily Summary',
        channelDescription: 'Daily evening reminder to review sales and inventory',
        importance: Importance.defaultImportance,
        priority: Priority.defaultPriority,
        icon: '@mipmap/ic_launcher',
      );
      final details = NotificationDetails(
        android: androidDetails,
        iOS: const DarwinNotificationDetails(),
      );

      await _plugin.periodicallyShow(
        id: 99999,
        title: isAm ? '📊 የቀን ሽያጭ ማጠቃለያ' : '📊 Daily Sales Summary',
        body: isAm
            ? 'የሱቅዎን የቀን ገቢ እና የአነስተኛ ዕቃዎችን ሁኔታ በኢንቬንቶሪ ይመልከቱ።'
            : 'Review your shop\'s daily performance and low stock items in Inventory.',
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
      final isAm = (await repository.getLanguage()) == 'am';

      final androidDetails = AndroidNotificationDetails(
        dailySummaryChannelId,
        isAm ? 'የቀን ማጠቃለያ' : 'Daily Summary',
        importance: Importance.high,
        priority: Priority.high,
        icon: '@mipmap/ic_launcher',
      );
      final details = NotificationDetails(
        android: androidDetails,
        iOS: const DarwinNotificationDetails(),
      );

      await _plugin.show(
        id: 99998,
        title: isAm ? '📊 የዛሬ ማጠቃለያ' : '📊 Today\'s Summary',
        body: isAm
            ? 'ጠቅላላ ሽያጭ: $formattedTotal። ያለቀባቸው ዕቃዎች: $lowCount።'
            : 'Total sales: $formattedTotal. Low stock items: $lowCount.',
        notificationDetails: details,
      );
    } catch (_) {}
  }

  /// Checks for customers with dube due today or overdue, and dispatches a notification if any exist.
  Future<void> checkAndNotifyDubeDueToday() async {
    try {
      final masterEnabled = await repository.isNotificationsEnabled();
      if (!masterEnabled) return;

      final today = DateFormat('yyyy-MM-dd').format(DateTime.now());
      final customers = await repository.listCustomers();
      int dueTodayCount = 0;
      int overdueCount = 0;

      for (final c in customers) {
        final openSales = await repository.openSales(c.id, asOfDate: today);
        bool hasDueToday = false;
        bool hasOverdue = false;

        for (final item in openSales) {
          final d = item.dueDate;
          if (d != null) {
            if (d == today) {
              hasDueToday = true;
            } else if (d.compareTo(today) < 0) {
              hasOverdue = true;
            }
          }
        }
        if (hasDueToday) dueTodayCount++;
        if (hasOverdue) overdueCount++;
      }

      if (dueTodayCount == 0 && overdueCount == 0) return;

      final isAm = (await repository.getLanguage()) == 'am';

      final androidDetails = AndroidNotificationDetails(
        dubeReminderChannelId,
        isAm ? 'የዱቤ ማስታወሻ' : 'Dube Reminders',
        channelDescription: 'Reminders for dube payments due today or overdue',
        importance: Importance.high,
        priority: Priority.high,
        icon: '@mipmap/ic_launcher',
      );
      final details = NotificationDetails(
        android: androidDetails,
        iOS: const DarwinNotificationDetails(),
      );

      final msg = isAm
          ? (dueTodayCount > 0
              ? '$dueTodayCount ደንበኞች ዛሬ የሚከፍሉት ዱቤ አለ${overdueCount > 0 ? " ($overdueCount ያለፈባቸው)" : ""}።'
              : '$overdueCount ደንበኞች ክፍያቸው ያለፈበት ዱቤ አለ።')
          : (dueTodayCount > 0
              ? '$dueTodayCount customers have dube due today${overdueCount > 0 ? " ($overdueCount overdue)" : ""}.'
              : '$overdueCount customers have overdue dube.');

      await _plugin.show(
        id: 99997,
        title: isAm ? '⏰ የዱቤ ማስታወሻ' : '⏰ Dube Due Reminder',
        body: msg,
        notificationDetails: details,
      );
    } catch (_) {}
  }

  /// Configures or cancels the daily dube due notification schedule.
  Future<void> scheduleDubeDueReminder({
    required bool enabled,
    String? timeStr,
  }) async {
    try {
      await _plugin.cancel(id: 99997);
      if (!enabled) return;

      final masterEnabled = await repository.isNotificationsEnabled();
      if (!masterEnabled) return;

      final isAm = (await repository.getLanguage()) == 'am';

      final androidDetails = AndroidNotificationDetails(
        dubeReminderChannelId,
        isAm ? 'የዱቤ ማስታወሻ' : 'Dube Reminders',
        channelDescription: 'Daily reminder for customer dube due today',
        importance: Importance.defaultImportance,
        priority: Priority.defaultPriority,
        icon: '@mipmap/ic_launcher',
      );
      final details = NotificationDetails(
        android: androidDetails,
        iOS: const DarwinNotificationDetails(),
      );

      await _plugin.periodicallyShow(
        id: 99997,
        title: isAm ? '⏰ የዱቤ ማስታወሻ' : '⏰ Dube Reminders',
        body: isAm
            ? 'ዛሬ የሚከፍሉ ወይም ክፍያቸው ያለፈባቸውን የዱቤ ደንበኞች ይመልከቱ።'
            : 'Check customers with dube due today or overdue.',
        repeatInterval: RepeatInterval.daily,
        notificationDetails: details,
        androidScheduleMode: AndroidScheduleMode.inexact,
      );
    } catch (_) {}
  }
}
