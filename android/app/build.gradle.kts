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
        versionCode = 38
        versionName = "1.0.38"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }

    lint {
        // Google Play 2026-09-05'te "Unsafe Implementation of WebView SSL
        // Error Handler" nedeniyle reddetti. Bu kurallar artik derlemeyi
        // DURDURUR — ayni hata bir daha yayina kadar gidemez.
        error += listOf(
            "WebViewClientOnReceivedSslError",
            "TrustAllX509TrustManager",
            "BadHostnameVerifier",
            "AllowAllHostnameVerifier",
            "InsecureBaseConfiguration"
        )
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
}

dependencies {
    implementation("androidx.core:core-ktx:1.12.0")
    implementation("androidx.appcompat:appcompat:1.6.1")
    implementation("androidx.activity:activity-ktx:1.8.2")
    implementation("com.google.android.material:material:1.11.0")
    implementation("androidx.constraintlayout:constraintlayout:2.1.4")
    implementation("androidx.cardview:cardview:1.0.0")

    // Lifecycle & Coroutines
    implementation("androidx.lifecycle:lifecycle-service:2.7.0")
    implementation("androidx.lifecycle:lifecycle-viewmodel-ktx:2.7.0")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.7.0")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.7.3")

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
