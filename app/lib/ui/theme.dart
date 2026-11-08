import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

class AppColors {
  // Brand Base Tokens
  static const Color primaryLight = Color(0xFF0F766E); // Teal 700
  static const Color primaryDark = Color(0xFF14B8A6);  // Teal 500
  static const Color accentLight = Color(0xFFF59E0B);   // Amber 500
  static const Color accentDark = Color(0xFFFBBF24);    // Amber 400

  // Semantic Colors
  static const Color success = Color(0xFF16A34A);
  static const Color successDark = Color(0xFF22C55E);
  static const Color warning = Color(0xFFD97706);
  static const Color warningDark = Color(0xFFF59E0B);
  static const Color danger = Color(0xFFDC2626);
  static const Color dangerDark = Color(0xFFEF4444);

  // Backgrounds & Surfaces (Light)
  static const Color bgLight = Color(0xFFF8FAFC);
  static const Color surfaceLight = Color(0xFFFFFFFF);
  static const Color surfaceVariantLight = Color(0xFFF1F5F9);
  static const Color textPrimaryLight = Color(0xFF0F172A);
  static const Color textSecondaryLight = Color(0xFF475569);
  static const Color outlineLight = Color(0xFFCBD5E1);

  // Backgrounds & Surfaces (Dark)
  static const Color bgDark = Color(0xFF0A0E17);
  static const Color surfaceDark = Color(0xFF131B2A);
  static const Color surfaceVariantDark = Color(0xFF1E293B);
  static const Color textPrimaryDark = Color(0xFFF1F5F9);
  static const Color textSecondaryDark = Color(0xFF94A3B8);
  static const Color outlineDark = Color(0xFF334155);

  // Semantic Tokens for Surface Badges & Highlights
  static SemanticColors successTokens(bool isDark) => SemanticColors(
    bg: isDark ? const Color(0xFF064E3B).withValues(alpha: 0.35) : const Color(0xFFF0FDF4),
    fg: isDark ? const Color(0xFF4ADE80) : const Color(0xFF15803D),
    border: isDark ? const Color(0xFF047857) : const Color(0xFFBBF7D0),
  );

  static SemanticColors warningTokens(bool isDark) => SemanticColors(
    bg: isDark ? const Color(0xFF78350F).withValues(alpha: 0.35) : const Color(0xFFFFFBEB),
    fg: isDark ? const Color(0xFFFBBF24) : const Color(0xFFB45309),
    border: isDark ? const Color(0xFFB45309) : const Color(0xFFFDE68A),
  );

  static SemanticColors dangerTokens(bool isDark) => SemanticColors(
    bg: isDark ? const Color(0xFF7F1D1D).withValues(alpha: 0.35) : const Color(0xFFFEF2F2),
    fg: isDark ? const Color(0xFFF87171) : const Color(0xFFB91C1C),
    border: isDark ? const Color(0xFFB91C1C) : const Color(0xFFFECACA),
  );

  static SemanticColors infoTokens(bool isDark) => SemanticColors(
    bg: isDark ? const Color(0xFF1E3A8A).withValues(alpha: 0.35) : const Color(0xFFEFF6FF),
    fg: isDark ? const Color(0xFF60A5FA) : const Color(0xFF1D4ED8),
    border: isDark ? const Color(0xFF1D4ED8) : const Color(0xFFBFDBFE),
  );

  static SemanticColors neutralTokens(bool isDark) => SemanticColors(
    bg: isDark ? const Color(0xFF1E293B) : const Color(0xFFF1F5F9),
    fg: isDark ? const Color(0xFF94A3B8) : const Color(0xFF475569),
    border: isDark ? const Color(0xFF334155) : const Color(0xFFCBD5E1),
  );
}

class SemanticColors {
  final Color bg;
  final Color fg;
  final Color border;

  const SemanticColors({
    required this.bg,
    required this.fg,
    required this.border,
  });
}

class AppTheme {
  static const String fontFamily = 'NotoSansEthiopic';
  static const List<String> fontFallbacks = ['NotoSans', 'Roboto', 'sans-serif'];

  static ThemeData get lightTheme {
    const colorScheme = ColorScheme(
      brightness: Brightness.light,
      primary: AppColors.primaryLight,
      onPrimary: Colors.white,
      primaryContainer: Color(0xFFE6FFFA),
      onPrimaryContainer: AppColors.primaryLight,
      secondary: AppColors.accentLight,
      onSecondary: Colors.black,
      secondaryContainer: Color(0xFFFEF3C7),
      onSecondaryContainer: Color(0xFF92400E),
      error: AppColors.danger,
      onError: Colors.white,
      surface: AppColors.surfaceLight,
      onSurface: AppColors.textPrimaryLight,
      surfaceContainerHighest: AppColors.surfaceVariantLight,
      onSurfaceVariant: AppColors.textSecondaryLight,
      outline: AppColors.outlineLight,
    );

    return ThemeData(
      useMaterial3: true,
      colorScheme: colorScheme,
      scaffoldBackgroundColor: AppColors.bgLight,
      fontFamily: fontFamily,
      fontFamilyFallback: fontFallbacks,
      appBarTheme: const AppBarTheme(
        backgroundColor: AppColors.surfaceLight,
        foregroundColor: AppColors.textPrimaryLight,
        elevation: 0,
        centerTitle: false,
        systemOverlayStyle: SystemUiOverlayStyle(
          statusBarColor: Colors.transparent,
          statusBarIconBrightness: Brightness.dark,
          statusBarBrightness: Brightness.light,
          systemNavigationBarColor: AppColors.surfaceLight,
          systemNavigationBarIconBrightness: Brightness.dark,
        ),
      ),
      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: AppColors.surfaceLight,
        surfaceTintColor: Colors.transparent,
      ),
      dialogTheme: const DialogThemeData(
        backgroundColor: AppColors.surfaceLight,
        surfaceTintColor: Colors.transparent,
      ),
      dividerTheme: const DividerThemeData(
        color: AppColors.outlineLight,
        thickness: 0.8,
      ),
      cardTheme: CardThemeData(
        elevation: 0.5,
        color: AppColors.surfaceLight,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: const BorderSide(color: AppColors.outlineLight, width: 0.8),
        ),
        margin: const EdgeInsets.symmetric(vertical: 4),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size(48, 48),
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          textStyle: const TextStyle(
            fontFamily: fontFamily,
            fontFamilyFallback: fontFallbacks,
            fontWeight: FontWeight.w500,
            fontSize: 15,
          ),
        ),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          minimumSize: const Size(48, 48),
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          textStyle: const TextStyle(
            fontFamily: fontFamily,
            fontFamilyFallback: fontFallbacks,
            fontWeight: FontWeight.w500,
            fontSize: 15,
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(48, 48),
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
          side: const BorderSide(color: AppColors.outlineLight, width: 1.2),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          textStyle: const TextStyle(
            fontFamily: fontFamily,
            fontFamilyFallback: fontFallbacks,
            fontWeight: FontWeight.w500,
            fontSize: 15,
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          minimumSize: const Size(48, 48),
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          textStyle: const TextStyle(
            fontFamily: fontFamily,
            fontFamilyFallback: fontFallbacks,
            fontWeight: FontWeight.w500,
            fontSize: 14,
          ),
        ),
      ),
      iconButtonTheme: IconButtonThemeData(
        style: IconButton.styleFrom(
          minimumSize: const Size(48, 48),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        filled: true,
        fillColor: AppColors.surfaceLight,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: const BorderSide(color: AppColors.outlineLight),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: const BorderSide(color: AppColors.outlineLight),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: const BorderSide(color: AppColors.primaryLight, width: 1.8),
        ),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: AppColors.surfaceLight,
        indicatorColor: const Color(0xFFE6FFFA),
        elevation: 2,
        height: 64,
        labelTextStyle: WidgetStateProperty.resolveWith((states) {
          final isSelected = states.contains(WidgetState.selected);
          return TextStyle(
            fontFamily: fontFamily,
            fontFamilyFallback: fontFallbacks,
            fontSize: 12,
            fontWeight: isSelected ? FontWeight.w700 : FontWeight.w500,
            color: isSelected ? AppColors.primaryLight : AppColors.textSecondaryLight,
          );
        }),
      ),
    );
  }

  static ThemeData get darkTheme {
    const colorScheme = ColorScheme(
      brightness: Brightness.dark,
      primary: AppColors.primaryDark,
      onPrimary: Color(0xFF042F2E),
      primaryContainer: AppColors.primaryLight,
      onPrimaryContainer: Color(0xFFCCFBF1),
      secondary: AppColors.accentDark,
      onSecondary: Color(0xFF451A03),
      secondaryContainer: Color(0xFF78350F),
      onSecondaryContainer: Color(0xFFFEF3C7),
      error: AppColors.dangerDark,
      onError: Color(0xFF450A0A),
      surface: AppColors.surfaceDark,
      onSurface: AppColors.textPrimaryDark,
      surfaceContainerHighest: AppColors.surfaceVariantDark,
      onSurfaceVariant: AppColors.textSecondaryDark,
      outline: AppColors.outlineDark,
    );

    return ThemeData(
      useMaterial3: true,
      colorScheme: colorScheme,
      scaffoldBackgroundColor: AppColors.bgDark,
      fontFamily: fontFamily,
      fontFamilyFallback: fontFallbacks,
      appBarTheme: const AppBarTheme(
        backgroundColor: AppColors.surfaceDark,
        foregroundColor: AppColors.textPrimaryDark,
        elevation: 0,
        centerTitle: false,
        systemOverlayStyle: SystemUiOverlayStyle(
          statusBarColor: Colors.transparent,
          statusBarIconBrightness: Brightness.light,
          statusBarBrightness: Brightness.dark,
          systemNavigationBarColor: AppColors.surfaceDark,
          systemNavigationBarIconBrightness: Brightness.light,
        ),
      ),
      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: AppColors.surfaceDark,
        surfaceTintColor: Colors.transparent,
      ),
      dialogTheme: const DialogThemeData(
        backgroundColor: AppColors.surfaceDark,
        surfaceTintColor: Colors.transparent,
      ),
      dividerTheme: const DividerThemeData(
        color: AppColors.outlineDark,
        thickness: 0.8,
      ),
      cardTheme: CardThemeData(
        elevation: 0,
        color: AppColors.surfaceDark,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: const BorderSide(color: AppColors.outlineDark, width: 0.8),
        ),
        margin: const EdgeInsets.symmetric(vertical: 4),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size(48, 48),
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          textStyle: const TextStyle(
            fontFamily: fontFamily,
            fontFamilyFallback: fontFallbacks,
            fontWeight: FontWeight.w500,
            fontSize: 15,
          ),
        ),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          minimumSize: const Size(48, 48),
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          textStyle: const TextStyle(
            fontFamily: fontFamily,
            fontFamilyFallback: fontFallbacks,
            fontWeight: FontWeight.w500,
            fontSize: 15,
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          minimumSize: const Size(48, 48),
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
          side: const BorderSide(color: AppColors.outlineDark, width: 1.2),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          textStyle: const TextStyle(
            fontFamily: fontFamily,
            fontFamilyFallback: fontFallbacks,
            fontWeight: FontWeight.w500,
            fontSize: 15,
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          minimumSize: const Size(48, 48),
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
          textStyle: const TextStyle(
            fontFamily: fontFamily,
            fontFamilyFallback: fontFallbacks,
            fontWeight: FontWeight.w500,
            fontSize: 14,
          ),
        ),
      ),
      iconButtonTheme: IconButtonThemeData(
        style: IconButton.styleFrom(
          minimumSize: const Size(48, 48),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        filled: true,
        fillColor: AppColors.surfaceVariantDark,
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: const BorderSide(color: AppColors.outlineDark),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: const BorderSide(color: AppColors.outlineDark),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(10),
          borderSide: const BorderSide(color: AppColors.primaryDark, width: 1.8),
        ),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: AppColors.surfaceDark,
        indicatorColor: AppColors.primaryLight,
        elevation: 2,
        height: 64,
        labelTextStyle: WidgetStateProperty.resolveWith((states) {
          final isSelected = states.contains(WidgetState.selected);
          return TextStyle(
            fontFamily: fontFamily,
            fontFamilyFallback: fontFallbacks,
            fontSize: 12,
            fontWeight: isSelected ? FontWeight.w700 : FontWeight.w500,
            color: isSelected ? AppColors.primaryDark : AppColors.textSecondaryDark,
          );
        }),
      ),
    );
  }
}
