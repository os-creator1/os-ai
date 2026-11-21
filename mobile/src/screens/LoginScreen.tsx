import React, { useState } from 'react';
import { KeyboardAvoidingView, Platform, StyleSheet, Text, TextInput, View } from 'react-native';
import { ApiError } from '../api/client';
import { useAuth } from '../auth/AuthContext';
import { colors, space } from '../theme';
import { Button } from './ui';

export function LoginScreen() {
  const { signIn } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      await signIn(email, password);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'Something went wrong.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <KeyboardAvoidingView style={s.wrap} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      <Text style={s.title}>MotionGrove</Text>
      <View style={s.form}>
        <TextInput style={s.input} placeholder="Email" accessibilityLabel="Email" autoCapitalize="none" autoCorrect={false}
          keyboardType="email-address" textContentType="username" value={email} onChangeText={setEmail} />
        <TextInput style={s.input} placeholder="Password" accessibilityLabel="Password" secureTextEntry textContentType="password"
          value={password} onChangeText={setPassword} onSubmitEditing={submit} />
        {error ? <Text style={s.error} accessibilityRole="alert">{error}</Text> : null}
        <Button title={busy ? 'Signing in…' : 'Sign in'} onPress={submit} disabled={busy || !email || !password} />
      </View>
    </KeyboardAvoidingView>
  );
}
const s = StyleSheet.create({
  wrap: { flex: 1, justifyContent: 'center', padding: space.lg, backgroundColor: colors.bg },
  title: { fontSize: 32, fontWeight: '700', color: colors.brand, textAlign: 'center', marginBottom: space.lg },
  form: { gap: space.md },
  input: { backgroundColor: colors.card, borderColor: colors.line, borderWidth: 1, borderRadius: 10, padding: 12, fontSize: 16 },
  error: { color: colors.danger },
});
