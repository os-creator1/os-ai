# Running MotionGrove Mobile on a physical phone

The app talks to a Laravel deployment you choose (**staging or a local dev server — never production data**) at
`EXPO_PUBLIC_API_URL`. It needs `https` (a local `http://<LAN-ip>:8000` is accepted only by a *development* bundle).

## Backend prerequisites (once)
1. Deploy/serve this branch on a **non-production** host and run the migration `2026_11_21_100001_create_mobile_devices_table`.
2. A test user that: is enabled, has **no two-factor**, belongs to a Workspace with an Active Business, holds the `chat_box`
   and `view_contact` permissions, and whose plan entitles `conversations` and `crm`. Use seeded/fake data only.
3. Reachable from the phone (same Wi-Fi for a LAN server, or a public staging URL). Android cleartext http is **off**; use https.

## Android (any OS, USB cable or file transfer)
Tooling: Node 22, JDK **17**, Android SDK (platform 37, build-tools 37.0.0, NDK 27.1.12297006, CMake 3.22.1).
```bash
cd mobile && npm install
npx expo prebuild --platform android --no-install
cd android && ANDROID_HOME=$HOME/android-sdk JAVA_HOME=<jdk17> ./gradlew assembleDebug -PreactNativeArchitectures=arm64-v8a
```
APK: `mobile/android/app/build/outputs/apk/debug/app-debug.apk`. On the phone enable *Developer options → USB debugging*, then
`adb install app-debug.apk`. Start Metro on the computer: `EXPO_PUBLIC_API_URL=https://… npx expo start --dev-client`
and open the app (same network, or `adb reverse tcp:8081 tcp:8081`).
Cloud alternative: `npx eas-cli build --profile development --platform android` (needs access to the `getmotiongrove` Expo
account, which owns the EAS project in `app.config.ts`). Push additionally needs the `GOOGLE_SERVICES_JSON` EAS file variable
(see ARCHITECTURE.md, "Enabling real-time and push"). An EAS build is signed with a different key than a local debug APK, so
**uninstall the local build first**: Android refuses to install over it, and the old app keeps running without push.

## iPhone (needs a Mac and Apple Developer membership)
* **Mac with Xcode 16+**, CocoaPods, Node 22; an **Apple Developer Program** account ($99/yr) — required to sign for a device.
* iPhone with **Developer Mode** on (Settings → Privacy & Security), connected by cable and trusted.
* Register the device and build: `npx eas-cli device:create` then `npx eas-cli build --profile development --platform ios`
  (installs via QR/ad-hoc), **or** locally `npx expo prebuild --platform ios && npx expo run:ios --device`.
* Bundle id `com.motiongrove.mobile` (change in `mobile/app.config.ts` to one you own). Push needs the *Push Notifications*
  capability and an APNs key uploaded to Expo (not to this repo). `eas.json` profile `development-simulator` builds for the Simulator.
* iOS builds cannot be produced on Linux; this branch ships the configuration only.

## Verify on the phone
Sign in → pick the Business → conversations list shows only that Business → open a thread → contact profile → Calls shows
"coming in the next milestone". Sign out and confirm the token is rejected (`401`) on the server.
