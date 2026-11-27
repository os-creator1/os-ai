import React, { useState } from 'react';
import { KeyboardAvoidingView, Platform, StyleSheet, Text, TextInput, View } from 'react-native';
import { ApiError } from '../api/client';
import type { TwoFactorChallenge, TwoFactorMethod } from '../api/types';
import { useAuth } from '../auth/AuthContext';
import { colors, space } from '../theme';
import { Button } from './ui';

const message = (e: unknown) => (e instanceof ApiError ? e.message : 'Something went wrong.');

export function LoginScreen() {
  const { signIn } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [challenge, setChallenge] = useState<TwoFactorChallenge | null>(null);

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      const outcome = await signIn(email, password);
      if (outcome.status === 'two_factor') {
        setPassword('');
        setChallenge(outcome.challenge);
      }
    } catch (e) {
      setError(message(e));
    } finally {
      setBusy(false);
    }
  }

  function backToLogin(reason: string | null) {
    setChallenge(null);
    setError(reason);
  }

  return (
    <KeyboardAvoidingView style={s.wrap} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Text style={s.title}>MotionGrove</Text>
      {challenge ? (
        <TwoFactorStep challenge={challenge} onBack={backToLogin} />
      ) : (
        <View style={s.form}>
          <TextInput style={s.input} placeholder="Email" accessibilityLabel="Email" autoCapitalize="none" autoCorrect={false}
            keyboardType="email-address" textContentType="username" value={email} onChangeText={setEmail} />
          <TextInput style={s.input} placeholder="Password" accessibilityLabel="Password" secureTextEntry textContentType="password"
            value={password} onChangeText={setPassword} onSubmitEditing={submit} />
          {error ? <Text style={s.error} accessibilityRole="alert">{error}</Text> : null}
          <Button title={busy ? 'Signing in…' : 'Sign in'} onPress={submit} disabled={busy || !email || !password} />
        </View>
      )}
    </KeyboardAvoidingView>
  );
}

/** Second sign-in step for a two-factor account: the 6-digit code sent by email/SMS, or one of the backup codes. */
function TwoFactorStep({ challenge, onBack }: { challenge: TwoFactorChallenge; onBack: (reason: string | null) => void }) {
  const { completeTwoFactor, resendTwoFactor } = useAuth();
  const [method, setMethod] = useState<TwoFactorMethod>('code');
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const backupAllowed = challenge.methods.includes('backup_code');
  const ready = /^\d{6}$/.test(code);

  function failed(e: unknown) {
    // The challenge is gone (expired, used up, account disabled): only a fresh sign-in can continue.
    if (e instanceof ApiError && e.code === 'two_factor_challenge_invalid') {
      onBack(e.message);
      return;
    }
    setCode('');
    setError(message(e));
  }

  async function verify() {
    if (!ready || busy) return;
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      await completeTwoFactor(challenge, code, method);
    } catch (e) {
      failed(e);
    } finally {
      setBusy(false);
    }
  }

  async function resend() {
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      await resendTwoFactor(challenge);
      setCode('');
      setNotice('A new code is on its way. The previous code no longer works.');
    } catch (e) {
      failed(e);
    } finally {
      setBusy(false);
    }
  }

  function switchMethod() {
    setMethod((m) => (m === 'code' ? 'backup_code' : 'code'));
    setCode('');
    setError(null);
    setNotice(null);
  }

  const isBackup = method === 'backup_code';

  return (
    <View style={s.form}>
      <Text style={s.heading} accessibilityRole="header">Two-step verification</Text>
      <Text style={s.body}>
        {isBackup
          ? 'Enter one of your 6-digit backup codes. Each backup code works once.'
          : 'Enter the 6-digit code we sent to your email or phone. It expires in 10 minutes.'}
      </Text>
      <TextInput
        style={[s.input, s.code]}
        placeholder="123456"
        accessibilityLabel={isBackup ? 'Backup code' : 'Verification code'}
        keyboardType="number-pad"
        inputMode="numeric"
        textContentType="oneTimeCode"
        autoComplete={isBackup ? 'off' : 'one-time-code'}
        maxLength={6}
        autoFocus
        value={code}
        onChangeText={(t) => setCode(t.replace(/\D/g, '').slice(0, 6))}
        onSubmitEditing={verify}
      />
      {error ? <Text style={s.error} accessibilityRole="alert">{error}</Text> : null}
      {notice ? <Text style={s.body} accessibilityLiveRegion="polite">{notice}</Text> : null}
      <Button title={busy ? 'Verifying…' : 'Verify'} onPress={verify} disabled={busy || !ready} />
      {!isBackup ? <Button title="Send a new code" variant="plain" onPress={resend} disabled={busy} /> : null}
      {backupAllowed ? (
        <Button title={isBackup ? 'Use the code we sent instead' : 'Use a backup code'} variant="plain" onPress={switchMethod} disabled={busy} />
      ) : null}
      <Button title="Back to sign in" variant="plain" onPress={() => onBack(null)} disabled={busy} />
    </View>
  );
}

const s = StyleSheet.create({
  wrap: { flex: 1, justifyContent: 'center', padding: space.lg, backgroundColor: colors.bg },
  title: { fontSize: 32, fontWeight: '700', color: colors.brand, textAlign: 'center', marginBottom: space.lg },
  form: { gap: space.md },
  heading: { fontSize: 20, fontWeight: '600', color: colors.text },
  body: { color: colors.muted, fontSize: 15 },
  input: { backgroundColor: colors.card, borderColor: colors.line, borderWidth: 1, borderRadius: 10, padding: 12, fontSize: 16 },
  code: { fontSize: 22, letterSpacing: 6, textAlign: 'center' },
  error: { color: colors.danger },
});
