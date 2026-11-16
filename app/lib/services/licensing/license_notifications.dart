import 'package:flutter_local_notifications/flutter_local_notifications.dart';

import 'license_models.dart';

class LicenseNotificationService {
  final FlutterLocalNotificationsPlugin _plugin;

  static const String licenseChannelId = 'license_alerts_channel';
  static const int notifId7Days = 7007;
  static const int notifId3Days = 7003;
  static const int notifId1Day = 7001;
  static const int notifIdExpired = 7000;

  LicenseNotificationService({
    FlutterLocalNotificationsPlugin? plugin,
  }) : _plugin = plugin ?? FlutterLocalNotificationsPlugin();

  /// Notify or schedule local reminders according to current license state and days left.
  Future<void> onLicenseEvaluated({
    required LicenseVerificationResult result,
    required bool isAm,
  }) async {
    try {
      if (result.state == LicenseState.expired) {
        await _showNotification(
          id: notifIdExpired,
          title: isAm ? '🚨 የፈቃድ ጊዜ አልቋል' : '🚨 License Expired',
          body: isAm
              ? 'የመተግበሪያ ፈቃድዎ ጊዜ አልቋል። አዳዲስ ሽያጮችን ለመመዝገብ እባክዎ ፈቃድዎን ያድሱ።'
              : 'Your license has expired. Terminal is in Read-Only mode. Please renew your license.',
          isUrgent: true,
        );
        return;
      }

      if (result.state == LicenseState.grace) {
        final daysLeft = result.daysLeft ?? 0;
        await _showNotification(
          id: notifIdExpired,
          title: isAm ? '⚠️ የችሮታ ጊዜ (Grace Period)' : '⚠️ License Grace Period',
          body: isAm
              ? 'ፈቃድዎ አልቋል! በችሮታ ጊዜ ውስጥ ነዎት ($daysLeft ቀናት ቀርተዋል)። እንዳይቋረጥ ፈጥነው ያድሱ።'
              : 'License expired! Operating in grace period ($daysLeft days remaining). Please renew promptly.',
          isUrgent: true,
        );
        return;
      }

      if (result.state == LicenseState.expiring && result.daysLeft != null) {
        final days = result.daysLeft!;
        int notifId = notifId7Days;
        if (days <= 1) {
          notifId = notifId1Day;
        } else if (days <= 3) {
          notifId = notifId3Days;
        }

        await _showNotification(
          id: notifId,
          title: isAm ? '⏳ የፈቃድ ማደሻ ማሳሰቢያ' : '⏳ License Expiring Soon',
          body: isAm
              ? 'ፈቃድዎ በ$days ቀናት ውስጥ ያበቃል። እንዳይቋረጥ አሁኑኑ ያድሱ።'
              : 'Your license expires in $days days. Contact vendor to renew.',
          isUrgent: days <= 1,
        );
      }
    } catch (_) {
      // Gracefully handle test runner or headless environments
    }
  }

  Future<void> _showNotification({
    required int id,
    required String title,
    required String body,
    bool isUrgent = false,
  }) async {
    final androidDetails = AndroidNotificationDetails(
      licenseChannelId,
      'License Alerts',
      channelDescription: 'Alerts for license expiry and renewal notifications',
      importance: isUrgent ? Importance.max : Importance.high,
      priority: isUrgent ? Priority.max : Priority.high,
      icon: '@mipmap/ic_launcher',
    );

    final details = NotificationDetails(
      android: androidDetails,
      iOS: const DarwinNotificationDetails(),
    );

    await _plugin.show(
      id: id,
      title: title,
      body: body,
      notificationDetails: details,
    );
  }
}
