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
      final lang = await repository.getLanguage();

      final channelName = lang == 'om'
          ? 'Beeksisa Kuusaa Hir\'ate'
          : (lang == 'am' ? 'የአነስተኛ ክምችት ማስጠንቀቂያ' : 'Low Stock Alerts');

      final androidDetails = AndroidNotificationDetails(
        lowStockChannelId,
        channelName,
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
      final notifTitle = lang == 'om'
          ? '⚠️ Beeksisa Kuusaa Hir\'ate: ${product.name}$locSuffix'
          : (lang == 'am'
              ? '⚠️ የአነስተኛ ክምችት ማስጠንቀቂያ: ${product.name}$locSuffix'
              : '⚠️ Low Stock Alert: ${product.name}$locSuffix');
      final notifBody = lang == 'om'
          ? 'Kuusaan gahaa miti: $availableDisplay ${product.unit} qofatu hafe (Daangaa: $thresholdDisplay ${product.unit}).'
          : (lang == 'am'
              ? 'በቂ ክምችት የለም: $availableDisplay ${product.unit} ብቻ ቀርቷል (ዝቅተኛ መጠን: $thresholdDisplay ${product.unit})'
              : 'Only $availableDisplay ${product.unit} remaining$locSuffix (Threshold: $thresholdDisplay ${product.unit}).');

      await _plugin.show(
        id: id,
        title: notifTitle,
        body: notifBody,
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

      final lang = await repository.getLanguage();

      final channelName = lang == 'om'
          ? 'Cuunfaa Guyyaa'
          : (lang == 'am' ? 'የቀን ማጠቃለያ' : 'Daily Summary');

      final androidDetails = AndroidNotificationDetails(
        dailySummaryChannelId,
        channelName,
        channelDescription: 'Daily evening reminder to review sales and inventory',
        importance: Importance.defaultImportance,
        priority: Priority.defaultPriority,
        icon: '@mipmap/ic_launcher',
      );
      final details = NotificationDetails(
        android: androidDetails,
        iOS: const DarwinNotificationDetails(),
      );

      final notifTitle = lang == 'om'
          ? '📊 Cuunfaa Gurgurtaa Guyyaa'
          : (lang == 'am' ? '📊 የቀን ሽያጭ ማጠቃለያ' : '📊 Daily Sales Summary');
      final notifBody = lang == 'om'
          ? 'Galii guyyaa fi haala meeshaalee dhumatan Qabiinsa Meeshaa keessatti ilaalaa.'
          : (lang == 'am'
              ? 'የሱቅዎን የቀን ገቢ እና የአነስተኛ ዕቃዎችን ሁኔታ በኢንቬንቶሪ ይመልከቱ።'
              : 'Review your shop\'s daily performance and low stock items in Inventory.');

      await _plugin.periodicallyShow(
        id: 99999,
        title: notifTitle,
        body: notifBody,
        repeatInterval: RepeatInterval.daily,
        notificationDetails: details,
        androidScheduleMode: AndroidScheduleMode.inexact,
      );
    } catch (_) {}
  }

  /// Manually dispatches today's sales summary notification on demand.
  Future<void> showDailySummaryNow() async {
    try {
      final totalCents = await repository.todaySalesTotalCents();
      final lowCount = await repository.lowStockCount();
      final formattedTotal = MoneyFormatter.formatCents(totalCents);
      final lang = await repository.getLanguage();

      final channelName = lang == 'om'
          ? 'Cuunfaa Guyyaa'
          : (lang == 'am' ? 'የቀን ማጠቃለያ' : 'Daily Summary');

      final androidDetails = AndroidNotificationDetails(
        dailySummaryChannelId,
        channelName,
        importance: Importance.high,
        priority: Priority.high,
        icon: '@mipmap/ic_launcher',
      );
      final details = NotificationDetails(
        android: androidDetails,
        iOS: const DarwinNotificationDetails(),
      );

      final notifTitle = lang == 'om'
          ? '📊 Cuunfaa Har\'aa'
          : (lang == 'am' ? '📊 የዛሬ ማጠቃለያ' : '📊 Today\'s Summary');
      final notifBody = lang == 'om'
          ? 'Gurgurtaa waliigalaa: $formattedTotal. Meeshaalee dhumatan: $lowCount.'
          : (lang == 'am'
              ? 'ጠቅላላ ሽያጭ: $formattedTotal። ያለቀባቸው ዕቃዎች: $lowCount።'
              : 'Total sales: $formattedTotal. Low stock items: $lowCount.');

      await _plugin.show(
        id: 99998,
        title: notifTitle,
        body: notifBody,
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

      final lang = await repository.getLanguage();

      final channelName = lang == 'om'
          ? 'Yaadachiisa Liqii'
          : (lang == 'am' ? 'የዱቤ ማስታወሻ' : 'Dube Reminders');

      final androidDetails = AndroidNotificationDetails(
        dubeReminderChannelId,
        channelName,
        channelDescription: 'Reminders for dube payments due today or overdue',
        importance: Importance.high,
        priority: Priority.high,
        icon: '@mipmap/ic_launcher',
      );
      final details = NotificationDetails(
        android: androidDetails,
        iOS: const DarwinNotificationDetails(),
      );

      final msg = lang == 'om'
          ? (dueTodayCount > 0
              ? 'Maamiloota $dueTodayCount har\'a liqii kaffaluu qaban jiru${overdueCount > 0 ? " ($overdueCount yeroon darbe)" : ""}.'
              : 'Maamiloota $overdueCount liqiin isaanii yeroon darbe jira.')
          : (lang == 'am'
              ? (dueTodayCount > 0
                  ? '$dueTodayCount ደንበኞች ዛሬ የሚከፍሉት ዱቤ አለ${overdueCount > 0 ? " ($overdueCount ያለፈባቸው)" : ""}።'
                  : '$overdueCount ደንበኞች ክፍያቸው ያለፈበት ዱቤ አለ።')
              : (dueTodayCount > 0
                  ? '$dueTodayCount customers have dube due today${overdueCount > 0 ? " ($overdueCount overdue)" : ""}.'
                  : '$overdueCount customers have overdue dube.'));

      final notifTitle = lang == 'om'
          ? '⏰ Yaadachiisa Liqii'
          : (lang == 'am' ? '⏰ የዱቤ ማስታወሻ' : '⏰ Dube Due Reminder');

      await _plugin.show(
        id: 99997,
        title: notifTitle,
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

      final lang = await repository.getLanguage();

      final channelName = lang == 'om'
          ? 'Yaadachiisa Liqii'
          : (lang == 'am' ? 'የዱቤ ማስታወሻ' : 'Dube Reminders');

      final androidDetails = AndroidNotificationDetails(
        dubeReminderChannelId,
        channelName,
        channelDescription: 'Daily reminder for customer dube due today',
        importance: Importance.defaultImportance,
        priority: Priority.defaultPriority,
        icon: '@mipmap/ic_launcher',
      );
      final details = NotificationDetails(
        android: androidDetails,
        iOS: const DarwinNotificationDetails(),
      );

      final notifTitle = lang == 'om'
          ? '⏰ Yaadachiisa Liqii'
          : (lang == 'am' ? '⏰ የዱቤ ማስታወሻ' : '⏰ Dube Reminders');
      final notifBody = lang == 'om'
          ? 'Maamiloota har\'a kaffalan ykn yeroon kaffaltii darbe ilaalaa.'
          : (lang == 'am'
              ? 'ዛሬ የሚከፍሉ ወይም ክፍያቸው ያለፈባቸውን የዱቤ ደንበኞች ይመልከቱ።'
              : 'Check customers with dube due today or overdue.');

      await _plugin.periodicallyShow(
        id: 99997,
        title: notifTitle,
        body: notifBody,
        repeatInterval: RepeatInterval.daily,
        notificationDetails: details,
        androidScheduleMode: AndroidScheduleMode.inexact,
      );
    } catch (_) {}
  }
}
