# MotionGrove Mobile

Expo (SDK 58) + TypeScript client for the MotionGrove Laravel platform. Architecture, API contract and decisions:
[`docs/mobile/ARCHITECTURE.md`](../docs/mobile/ARCHITECTURE.md). Running on a real phone:
[`docs/mobile/PHYSICAL-PHONE.md`](../docs/mobile/PHYSICAL-PHONE.md).

```bash
cd mobile
npm install
npm run typecheck && npm test      # 16 Jest tests, no network
EXPO_PUBLIC_API_URL=https://your-staging-host.example npx expo start --dev-client
```

Screens: Login · Business selector · Conversations · Conversation detail · Contact profile · Calls (placeholder).
The app holds only a Sanctum token (Keychain/Keystore). It never holds provider keys or SIP credentials.
