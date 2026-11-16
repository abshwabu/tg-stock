import 'package:flutter/material.dart';

import '../../services/licensing/clock_service.dart';
import '../../services/licensing/license_gate.dart';
import '../../services/licensing/license_models.dart';

class LicenseBanner extends StatelessWidget {
  final LicenseGate licenseGate;
  final ClockService clockService;
  final bool isAm;
  final VoidCallback? onVerifyOnline;
  final VoidCallback? onOpenLicenseScreen;

  const LicenseBanner({
    super.key,
    required this.licenseGate,
    required this.clockService,
    required this.isAm,
    this.onVerifyOnline,
    this.onOpenLicenseScreen,
  });

  @override
  Widget build(BuildContext context) {
    return ValueListenableBuilder<LicenseVerificationResult>(
      valueListenable: licenseGate.currentResultNotifier,
      builder: (context, result, _) {
        final state = result.state;
        final isClockSuspect = clockService.isClockSuspect;

        // 1. Clock Suspect Alert
        if (isClockSuspect) {
          return _buildBannerCard(
            context,
            color: const Color(0xFFFEF3C7), // Amber 100
            borderColor: const Color(0xFFD97706), // Amber 600
            icon: Icons.access_time_filled_rounded,
            iconColor: const Color(0xFFB45309),
            title: isAm ? 'የስልክዎን ቀን እና ሰዓት ይፈትሹ' : 'Check Your Phone Date',
            message: isAm
                ? 'የቀን መዛባት ተገኝቷል። የፈቃድ ስራዎች እንዳይስተጓጎሉ እባክዎ የስልክዎን ቀን ያስተካክሉ ወይም በኦንላይን ያረጋግጡ።'
                : 'Clock discrepancy detected. Please ensure your device clock is correct or verify online.',
            actionLabel: isAm ? 'በኦንላይን አረጋግጥ' : 'Verify Online',
            onAction: onVerifyOnline,
          );
        }

        // 2. Expired State (Read-Only Mode)
        if (state == LicenseState.expired) {
          return _buildBannerCard(
            context,
            color: const Color(0xFFFEF2F2), // Red 50
            borderColor: const Color(0xFFEF4444), // Red 500
            icon: Icons.lock_clock_rounded,
            iconColor: const Color(0xFFDC2626),
            title: isAm ? 'የፈቃድ ጊዜ አልቋል (የንባብ ብቻ ሁነታ)' : 'License Expired (Read-Only Mode)',
            message: isAm
                ? 'የመተግበሪያ ፈቃድዎ አብቅቷል። ሽያጭ ወይም የክምችት ለውጥ ለመመዝገብ እባክዎ ፈቃድዎን ያድሱ።'
                : 'License has expired. Sales and stock mutations are paused. Renew to resume writing.',
            actionLabel: isAm ? 'ፈቃድ አድስ' : 'Renew License',
            onAction: onOpenLicenseScreen,
          );
        }

        // 3. Grace Period
        if (state == LicenseState.grace) {
          final days = result.daysLeft ?? 0;
          return _buildBannerCard(
            context,
            color: const Color(0xFFFFF7ED), // Orange 50
            borderColor: const Color(0xFFF97316), // Orange 500
            icon: Icons.warning_amber_rounded,
            iconColor: const Color(0xFFEA580C),
            title: isAm ? 'የችሮታ ጊዜ (Grace Period)' : 'Operating in Grace Period',
            message: isAm
                ? 'ፈቃድዎ አልቋል! በአሁኑ ወቅት በችሮታ ጊዜ ውስጥ ነዎት ($days ቀናት ቀርተዋል)። እንዳይቋረጥ አሁኑኑ ያድሱ።'
                : 'License expired! Operating in grace period ($days days remaining). Renew to avoid interruption.',
            actionLabel: isAm ? 'አሁኑኑ አድስ' : 'Renew Now',
            onAction: onOpenLicenseScreen,
          );
        }

        // 4. Expiring Soon (<= 7 days)
        if (state == LicenseState.expiring && result.daysLeft != null) {
          final days = result.daysLeft!;
          return _buildBannerCard(
            context,
            color: const Color(0xFFFFFBEB), // Amber 50
            borderColor: const Color(0xFFF59E0B), // Amber 500
            icon: Icons.hourglass_top_rounded,
            iconColor: const Color(0xFFD97706),
            title: isAm ? 'ፈቃድዎ በቅርቡ ያበቃል' : 'License Expiring Soon',
            message: isAm
                ? 'ፈቃድዎ በ$days ቀናት ውስጥ ያበቃል። ያልተቋረጠ አገልግሎት ለማግኘት ፈቃድዎን ያድሱ።'
                : 'Your license expires in $days days. Renew in advance to prevent interruption.',
            actionLabel: isAm ? 'አድስ' : 'Renew',
            onAction: onOpenLicenseScreen,
          );
        }

        // 5. Invalid States (Revoked, Not Yet Valid, Unsupported Version)
        if (state == LicenseState.revoked) {
          return _buildBannerCard(
            context,
            color: const Color(0xFFFEF2F2),
            borderColor: const Color(0xFFEF4444),
            icon: Icons.block_rounded,
            iconColor: const Color(0xFFDC2626),
            title: isAm ? 'ፈቃድ ተሰርዟል' : 'License Revoked',
            message: isAm
                ? 'ይህ ፈቃድ በአስተዳዳሪው ተሰርዟል። እባክዎ የደንበኞች ድጋፍን ያነጋግሩ።'
                : 'This license has been administratively revoked. Please contact support.',
            actionLabel: isAm ? 'አዲስ ፈቃድ አስገባ' : 'Enter New Token',
            onAction: onOpenLicenseScreen,
          );
        }

        // 6. Unlicensed Mode (Free Starter Tier)
        if (state == LicenseState.unlicensed) {
          return _buildBannerCard(
            context,
            color: const Color(0xFFF0FDF4),
            borderColor: const Color(0xFF10B981),
            icon: Icons.verified_user_outlined,
            iconColor: const Color(0xFF059669),
            title: isAm ? 'ነፃ የመጀመሪያ ሁነታ (Free Starter)' : 'Free Starter Tier',
            message: isAm
                ? 'እስከ 30 ምርቶች እና 50 ሽያጮች ድረስ በነፃ መጠቀም ይችላሉ። ያልተገደበ አገልግሎት ለማግኘት ፈቃድ ያግብሩ።'
                : 'Operating on free starter tier (up to 30 products & 50 sales). Activate a license for unlimited operations.',
            actionLabel: isAm ? 'ፈቃድ አግብር' : 'Activate License',
            onAction: onOpenLicenseScreen,
          );
        }

        return const SizedBox.shrink();
      },
    );
  }

  Widget _buildBannerCard(
    BuildContext context, {
    required Color color,
    required Color borderColor,
    required IconData icon,
    required Color iconColor,
    required String title,
    required String message,
    required String actionLabel,
    VoidCallback? onAction,
  }) {
    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      decoration: BoxDecoration(
        color: color,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: borderColor, width: 1.2),
      ),
      padding: const EdgeInsets.all(12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(icon, color: iconColor, size: 22),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  title,
                  style: TextStyle(
                    fontWeight: FontWeight.bold,
                    fontSize: 14,
                    color: iconColor,
                  ),
                ),
              ),
              if (onAction != null)
                TextButton(
                  onPressed: onAction,
                  style: TextButton.styleFrom(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    minimumSize: Size.zero,
                    tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    backgroundColor: borderColor.withValues(alpha: 0.15),
                  ),
                  child: Text(
                    actionLabel,
                    style: TextStyle(
                      fontWeight: FontWeight.bold,
                      fontSize: 12,
                      color: iconColor,
                    ),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            message,
            style: const TextStyle(fontSize: 12.5, height: 1.35),
          ),
        ],
      ),
    );
  }
}
