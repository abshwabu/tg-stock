import 'package:flutter/cupertino.dart';
import 'package:flutter/material.dart';

/// Returns a pure translated string based on the active locale.
/// Strictly separates languages to ensure zero overlapping bilingual/trilingual text.
String appL10n(
  BuildContext context, {
  required String en,
  required String am,
  required String om,
}) {
  final code = Localizations.localeOf(context).languageCode;
  if (code == 'om') return om;
  if (code == 'am') return am;
  return en;
}

/// Returns a pure translated string based on an explicit language code ('en', 'am', 'om').
String appL10nByCode(
  String languageCode, {
  required String en,
  required String am,
  required String om,
}) {
  if (languageCode == 'om') return om;
  if (languageCode == 'am') return am;
  return en;
}

/// Fallback Material localizations delegate for locales (like 'om') not bundled in GlobalMaterialLocalizations.
class FallbackMaterialLocalizationsDelegate extends LocalizationsDelegate<MaterialLocalizations> {
  const FallbackMaterialLocalizationsDelegate();

  @override
  bool isSupported(Locale locale) => locale.languageCode == 'om';

  @override
  Future<MaterialLocalizations> load(Locale locale) async {
    return const DefaultMaterialLocalizations();
  }

  @override
  bool shouldReload(FallbackMaterialLocalizationsDelegate old) => false;
}

/// Fallback Cupertino localizations delegate for locales (like 'om') not bundled in GlobalCupertinoLocalizations.
class FallbackCupertinoLocalizationsDelegate extends LocalizationsDelegate<CupertinoLocalizations> {
  const FallbackCupertinoLocalizationsDelegate();

  @override
  bool isSupported(Locale locale) => locale.languageCode == 'om';

  @override
  Future<CupertinoLocalizations> load(Locale locale) async {
    return const DefaultCupertinoLocalizations();
  }

  @override
  bool shouldReload(FallbackCupertinoLocalizationsDelegate old) => false;
}
