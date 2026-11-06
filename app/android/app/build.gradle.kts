import java.io.FileInputStream
import java.util.Properties

plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

val keystoreProperties = Properties()
val keystorePropertiesFile = rootProject.file("key.properties")
if (keystorePropertiesFile.exists()) {
    keystoreProperties.load(FileInputStream(keystorePropertiesFile))
}

val customVersionCode = project.findProperty("versionCode")?.toString()?.toIntOrNull()
    ?: System.getenv("BUILD_NUMBER")?.toIntOrNull()
    ?: flutter.versionCode

val customVersionName = project.findProperty("versionName")?.toString()
    ?: System.getenv("VERSION_NAME")
    ?: flutter.versionName

gradle.taskGraph.whenReady {
    val releaseRequested = allTasks.any { it.name.contains("Release", ignoreCase = true) }
    if (releaseRequested) {
        require(!keystoreProperties.getProperty("keyAlias").isNullOrBlank() || !System.getenv("KEY_ALIAS").isNullOrBlank()) {
            "Release signing requires keyAlias in key.properties or KEY_ALIAS."
        }
        require(!keystoreProperties.getProperty("keyPassword").isNullOrBlank() || !System.getenv("KEY_PASSWORD").isNullOrBlank()) {
            "Release signing requires keyPassword in key.properties or KEY_PASSWORD."
        }
        require(!keystoreProperties.getProperty("storePassword").isNullOrBlank() || !System.getenv("STORE_PASSWORD").isNullOrBlank()) {
            "Release signing requires storePassword in key.properties or STORE_PASSWORD."
        }
        val keystore = keystoreProperties.getProperty("storeFile") ?: System.getenv("STORE_FILE")
        require(!keystore.isNullOrBlank() && file(keystore).exists()) {
            "Release signing requires an existing storeFile in key.properties or STORE_FILE."
        }
    }
}

android {
    namespace = "com.yenesuq.inventory"
    compileSdk = 36
    ndkVersion = flutter.ndkVersion

    compileOptions {
        isCoreLibraryDesugaringEnabled = true
        sourceCompatibility = JavaVersion.VERSION_11
        targetCompatibility = JavaVersion.VERSION_11
    }

    kotlinOptions {
        jvmTarget = JavaVersion.VERSION_11.toString()
    }

    signingConfigs {
        create("release") {
            val keyAliasProp = keystoreProperties.getProperty("keyAlias") ?: System.getenv("KEY_ALIAS")
            val keyPasswordProp = keystoreProperties.getProperty("keyPassword") ?: System.getenv("KEY_PASSWORD")
            val storeFileProp = keystoreProperties.getProperty("storeFile") ?: System.getenv("STORE_FILE")
            val storePasswordProp = keystoreProperties.getProperty("storePassword") ?: System.getenv("STORE_PASSWORD")

            if (!keyAliasProp.isNullOrBlank() && !keyPasswordProp.isNullOrBlank() &&
                !storePasswordProp.isNullOrBlank() && !storeFileProp.isNullOrBlank() && file(storeFileProp).exists()
            ) {
                keyAlias = keyAliasProp
                keyPassword = keyPasswordProp
                storeFile = file(storeFileProp)
                storePassword = storePasswordProp
            }
        }
    }

    defaultConfig {
        applicationId = "com.yenesuq.inventory"
        // local_auth_android 2.0.8 declares minSdk 24; preserve Flutter's default if it rises later.
        minSdk = maxOf(flutter.minSdkVersion, 24)
        targetSdk = 36
        versionCode = customVersionCode
        versionName = customVersionName
        multiDexEnabled = true
        resourceConfigurations.addAll(listOf("en", "am"))
    }

    buildTypes {
        release {
            signingConfig = signingConfigs.getByName("release")
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }
}

dependencies {
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
}

flutter {
    source = "../.."
}
