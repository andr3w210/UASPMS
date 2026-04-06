# UASPMS Android Wrapper (Existing System)

This Android app is a WebView wrapper that loads the existing UASPMS web system.
No backend rewrite is required.

## What it does
- Opens your existing web app URL inside Android WebView.
- Supports file upload and camera capture for forms (for example PO scan upload).
- Keeps your current PHP/MySQL system as the single backend.

## Default URL
Configured in:
- `app/build.gradle.kts` via `BuildConfig.BASE_URL`

Current default:
- `http://172.16.1.42/UASPMS/spams/`

If needed, you can still use `http://10.0.2.2/UASPMS/spams/` for emulator-to-localhost mapping.

## Real phone setup
For a physical device, replace `BASE_URL` with your PC LAN IP, for example:
- `http://192.168.1.10/UASPMS/spams/`

Requirements:
1. Phone and PC must be on same network.
2. XAMPP Apache must be running.
3. Firewall must allow inbound HTTP to Apache.

## Build and run
1. Open the android folder in Android Studio.
2. Let Gradle sync.
3. Run on emulator or connected device.

## Install on phone (debug)
1. Connect your phone via USB and enable USB debugging.
2. In Android Studio, choose your device and click Run.
3. Android Studio will build and install automatically.

## Build signed release APK/AAB
1. Create folder: android/keystore
2. Create your keystore (example command):

```powershell
keytool -genkeypair -v -keystore android/keystore/uaspms-release.jks -keyalg RSA -keysize 2048 -validity 10000 -alias uaspms
```

3. Copy android/key.properties.example to android/key.properties and set real values.
4. Build signed APK:

```powershell
cd android
.\gradlew.bat assembleRelease
```

5. Build signed AAB (for Play Store):

```powershell
cd android
.\gradlew.bat bundleRelease
```

Output locations:
- APK: android/app/build/outputs/apk/release/
- AAB: android/app/build/outputs/bundle/release/

## Install release APK manually on phone
1. Copy app-release.apk to the phone.
2. Enable Install unknown apps permission for the app used to open APK.
3. Tap APK and install.

## Notes
- Cleartext HTTP is allowed for local network use (network_security_config.xml).
- For production, use HTTPS and tighten network security config.
