# Flutter Play Store Split / Deferred Components (not used in this app)
-dontwarn com.google.android.play.core.**
-dontwarn io.flutter.embedding.android.FlutterPlayStoreSplitApplication
-dontwarn io.flutter.embedding.engine.deferredcomponents.**

# Flutter R8 / Proguard Rules for Release Builds

# Flutter Engine & Plugins
-keep class io.flutter.app.** { *; }
-keep class io.flutter.plugin.** { *; }
-keep class io.flutter.util.** { *; }
-keep class io.flutter.view.** { *; }
-keep class io.flutter.** { *; }
-keep class io.flutter.plugins.** { *; }

# Drift & SQLite (sqlite3_flutter_libs)
# Prevent shrinking/obfuscating native JNI and reflection hooks for sqlite3
-keep class org.sqlite.** { *; }
-keep class com.simonhmartin.drift.** { *; }
-keep class eu.simonbinder.sqlite3.** { *; }
-keepclassmembers class * extends eu.simonbinder.sqlite3.** { *; }
-keepclassmembers class eu.simonbinder.sqlite3.** {
    native <methods>;
}
-dontwarn eu.simonbinder.sqlite3.**

# Flutter Secure Storage
-keep class de.julianassmann.flutter_secure_storage.** { *; }

# Local Auth & Biometrics
-keep class androidx.biometric.** { *; }
-keep class io.flutter.plugins.localauth.** { *; }

# Local Notifications
-keep class com.dexterous.flutterlocalnotifications.** { *; }
-keep class androidx.core.app.NotificationCompat** { *; }

# Keep native methods across all libraries
-keepclasseswithmembernames class * {
    native <methods>;
}
