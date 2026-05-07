plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

import java.util.Properties

val keystorePropertiesFile = rootProject.file("key.properties")
val keystoreProperties = Properties()
val hasReleaseSigning = keystorePropertiesFile.exists()

if (hasReleaseSigning) {
    keystorePropertiesFile.inputStream().use { keystoreProperties.load(it) }
}

// Load network endpoint config from local.properties (gitignored).
// Copy local.properties.example to local.properties and set your own values.
val localPropsFile = rootProject.file("local.properties")
val localProps = Properties()
if (localPropsFile.exists()) {
    localPropsFile.inputStream().use { localProps.load(it) }
}
fun localUrl(key: String, fallback: String): String =
    "\"${localProps.getProperty(key, fallback)}\""

android {
    namespace = "com.uaspms.mobile"
    compileSdk = 34

    defaultConfig {
        applicationId = "com.uaspms.mobile"
        minSdk = 24
        targetSdk = 34
        versionCode = 2
        versionName = "1.1"

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"

        // Network endpoints — read from local.properties (gitignored); fallback to localhost-only defaults.
        buildConfigField("String", "BASE_URL",             localUrl("BASE_URL",             "http://10.0.2.2/UASPMS/spams/"))
        buildConfigField("String", "TAILSCALE_IP_BASE_URL", localUrl("TAILSCALE_IP_BASE_URL", "http://10.0.2.2/UASPMS/spams/"))
        buildConfigField("String", "LAN_BASE_URL",          localUrl("LAN_BASE_URL",          "http://10.0.2.2/UASPMS/spams/"))
        buildConfigField("String", "LOCAL_BASE_URL",        localUrl("LOCAL_BASE_URL",        "http://10.0.2.2/UASPMS/spams/"))
    }

    signingConfigs {
        if (hasReleaseSigning) {
            create("release") {
                storeFile = file(keystoreProperties["storeFile"] as String)
                storePassword = keystoreProperties["storePassword"] as String
                keyAlias = keystoreProperties["keyAlias"] as String
                keyPassword = keystoreProperties["keyPassword"] as String
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            if (hasReleaseSigning) {
                signingConfig = signingConfigs.getByName("release")
            }
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
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
        buildConfig = true
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.activity:activity-ktx:1.9.1")
    implementation("androidx.swiperefreshlayout:swiperefreshlayout:1.1.0")
    implementation("androidx.webkit:webkit:1.11.0")
    implementation("androidx.camera:camera-core:1.3.1")
    implementation("androidx.camera:camera-camera2:1.3.1")
    implementation("androidx.camera:camera-lifecycle:1.3.1")
    implementation("androidx.camera:camera-view:1.3.1")
    implementation("com.google.mlkit:barcode-scanning:17.3.0")
    implementation("com.google.android.gms:play-services-mlkit-barcode-scanning:18.3.0")
}
