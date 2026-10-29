# UASPMS Android Wrapper (Existing System)

This Android app is a WebView wrapper that loads the existing UASPMS web system.
No backend rewrite is required.

## What it does
- Opens your existing web app URL inside Android WebView.
- Supports file upload and camera capture for forms (for example PO scan upload).
- Keeps your current PHP/MySQL system as the single backend.

## Default URL
Configured in:
- SPAMS web settings page for system/QR access URLs
- `app/build.gradle.kts` for Android startup fallback defaults

At startup, the app attempts the office LAN address first, then uses the Tailscale address as a remote fallback. It fetches the configured addresses from:
- `/UASPMS/spams/modules/settings/access_urls_public.php`

Remote access uses the trusted Tailscale HTTPS address:
- `https://spmu-andrew.tail985047.ts.net/UASPMS/spams/`

Office network devices can also use the configured LAN address, currently `http://172.16.1.42/UASPMS/spams/`. The Android app permits cleartext only to that exact LAN IP; other HTTP URLs are rejected. LAN HTTP is unencrypted, so use it only on a trusted office network. For Tailscale access, the Tailscale Serve endpoint presents a publicly trusted certificate.

Requirements:
1. For Tailscale access, both devices must be connected to the same Tailscale network.
2. For LAN access, devices must be on the office network and Apache must be running.
3. Firewall must allow the selected network's access to the service.

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
- Cleartext HTTP is allowed only for the configured office LAN IP (network_security_config.xml).
