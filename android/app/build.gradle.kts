import java.util.Properties
import java.io.FileInputStream

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "com.mhrgl.aipbx"
    compileSdk = 36

    defaultConfig {
        applicationId = "com.mhrgl.AiPBX"
        minSdk = 26
        targetSdk = 36
        // The app has the PBX's version (repo VERSION file, e.g. 1.50.0):
        // versionCode = MAJOR*1_000_000 + MINOR*10_000 + PATCH*100 + androidBuild.
        // androidBuild (0..99, -PandroidBuild=N) is only for an Android-only
        // rebuild of the same release: Play takes every versionCode once.
        val pbxVersion = rootProject.file("../VERSION").readText().trim()
        val (major, minor, patch) = pbxVersion.split(".").map { it.toInt() }
        val androidBuild = (project.findProperty("androidBuild") as String?)?.toInt() ?: 0
        require(androidBuild in 0..99) { "androidBuild must be 0..99" }
        require(minor < 100 && patch < 100) { "VERSION $pbxVersion does not fit the versionCode scheme" }
        versionCode = major * 1_000_000 + minor * 10_000 + patch * 100 + androidBuild
        versionName = pbxVersion

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }

    lint {
        // Google Play rejected the app on 2026-09-05 for "Unsafe Implementation
        // of WebView SSL Error Handler". These rules now STOP the build — the
        // same bug can never reach a release again.
        error += listOf(
            "WebViewClientOnReceivedSslError",
            "TrustAllX509TrustManager",
            "BadHostnameVerifier",
            "AllowAllHostnameVerifier",
            "InsecureBaseConfiguration"
        )
        // New texts are added in English first and translated later; a
        // missing translation shows the English text.
        warning += "MissingTranslation"
        abortOnError = true
        checkReleaseBuilds = true
    }

    signingConfigs {
        create("release") {
            val keystorePropsFile = rootProject.file("keystore.properties")
            if (keystorePropsFile.exists()) {
                val props = Properties()
                FileInputStream(keystorePropsFile).use { fis ->
                    props.load(fis)
                }
                storeFile = rootProject.file("release-key.jks")
                storePassword = props.getProperty("storePassword")
                keyAlias = props.getProperty("keyAlias")
                keyPassword = props.getProperty("keyPassword")
            } else {
                throw GradleException("keystore.properties not found! Cannot sign release build.")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            signingConfig = signingConfigs.getByName("release")
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
        debug {
            applicationIdSuffix = ".debug"
            isDebuggable = true
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    buildFeatures {
        viewBinding = true
        buildConfig = true
    }

    bundle {
        language {
            // Keep all 23 translations in the base APK so the in-app language
            // switcher works even when Google Play installs from an App Bundle.
            enableSplit = false
        }
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("androidx.activity:activity-ktx:1.10.1")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.constraintlayout:constraintlayout:2.2.0")
    implementation("androidx.cardview:cardview:1.0.0")

    // Lifecycle & Coroutines
    implementation("androidx.lifecycle:lifecycle-service:2.8.7")
    implementation("androidx.lifecycle:lifecycle-viewmodel-ktx:2.8.7")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.7")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.8.1")

    // Networking & JSON
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("com.squareup.okhttp3:logging-interceptor:4.12.0")
    implementation("com.google.code.gson:gson:2.10.1")

    // Security for stored credentials
    implementation("androidx.security:security-crypto:1.1.0-alpha06")

    // WebView & WebRTC Asset Loader (for Secure Context HTTPS)
    implementation("androidx.webkit:webkit:1.10.0")

    // Optional Runtime FCM (Faz 3 - initialized dynamically only when push_provider=fcm)
    implementation("com.google.firebase:firebase-messaging:24.0.0")

    // QR Code / Barcode Scanning for Fast Login
    implementation("com.journeyapps:zxing-android-embedded:4.3.0")

    // Unit Testing (M24)
    testImplementation("junit:junit:4.13.2")
}
