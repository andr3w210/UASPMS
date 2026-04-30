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

Primary:
- `http://spmu-andrew.tail985047.ts.net/UASPMS/spams/`

Tailscale IP fallback:
- `http://100.84.75.22/UASPMS/spams/`

LAN fallback:
- `http://172.16.1.42/UASPMS/spams/`

Local emulator fallback:
- `http://10.0.2.2/UASPMS/spams/`

Update Tailscale Serve URL, Tailscale IP, and Local URL from the SPAMS web page: Administration > Settings > System Access URL. The Android app only uses its bundled fallback list to reach SPAMS.

## Real phone setup
For a physical device, replace `BASE_URL` with your PC LAN IP, for example:
- `http://192.168.1.10/UASPMS/spams/`

For Tailscale access, use the PC's Tailscale IP:
- `http://100.84.75.22/UASPMS/spams/`

With Tailscale Serve enabled, use the MagicDNS URL:
- `http://spmu-andrew.tail985047.ts.net/UASPMS/spams/`

Requirements:
1. For Tailscale access, both devices must be connected to the same Tailscale account/network.
2. For LAN access, phone and PC must be on same local network.
3. XAMPP Apache must be running.
4. Firewall must allow inbound HTTP to Apache.

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
