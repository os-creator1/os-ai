import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import { Text } from 'react-native';
import { AuthProvider, useAuth } from '../src/auth/AuthContext';
import { ApiError, createApi } from '../src/api/client';
import { LoginScreen } from '../src/screens/LoginScreen';

jest.mock('expo-secure-store', () => {
  const m = new Map<string, string>();
  return {
    getItemAsync: async (k: string) => m.get(k) ?? null,
    setItemAsync: async (k: string, v: string) => { m.set(k, v); },
    deleteItemAsync: async (k: string) => { m.delete(k); },
    __clear: () => m.clear(),
  };
});
jest.mock('expo-notifications', () => ({}));
jest.mock('expo-device', () => ({ isDevice: false }));
jest.mock('expo-constants', () => ({ __esModule: true, default: { expoConfig: { version: '0.1.0', extra: {} }, deviceName: 'Test phone' } }));

const business = { workspace_uid: 'ws-1', workspace_name: 'Alpha WS', business_uid: 'biz-1', name: 'Alpha Studio', features: { conversations: true, contacts: true } };
const me = { user: { uid: 'u1', name: 'Pat', email: 'pat@example.test' }, businesses: [business] };
const CHALLENGE = 'c'.repeat(64);
const challengeBody = { two_factor_required: true, challenge: CHALLENGE, expires_at: new Date(Date.now() + 6e5).toISOString(), methods: ['code', 'backup_code'] };
const token = { token: 't-2fa', token_type: 'Bearer', expires_at: new Date(Date.now() + 1e9).toISOString(), user: me.user };

function fakeApi(over: Record<string, any> = {}): any {
  return {
    login: jest.fn(async () => challengeBody),
    verifyTwoFactor: jest.fn(async () => token),
    resendTwoFactor: jest.fn(async () => ({ sent: true, expires_at: challengeBody.expires_at })),
    logout: jest.fn(async () => ({ signed_out: true })),
    me: jest.fn(async () => me),
    registerPush: jest.fn(),
    ...over,
  };
}

function SignedInProbe() {
  const { signedIn } = useAuth();
  return signedIn ? <Text>signed in</Text> : null;
}

async function toCodeStep(api: any) {
  await render(<AuthProvider apiOverride={api}><LoginScreen /><SignedInProbe /></AuthProvider>);
  await waitFor(() => screen.getByLabelText('Email'));
  await fireEvent.changeText(screen.getByLabelText('Email'), 'pat@example.test');
  await fireEvent.changeText(screen.getByLabelText('Password'), 'correct-pass');
  await fireEvent.press(screen.getByText('Sign in'));
  await waitFor(() => screen.getByLabelText('Verification code'));
}

// Every test starts signed out: no session saved by an earlier test.
beforeEach(() => { (require('expo-secure-store') as { __clear: () => void }).__clear(); });

describe('two-factor sign-in', () => {
  it('asks for the code instead of signing in, with a numeric one-time-code field', async () => {
    const api = fakeApi();
    await toCodeStep(api);

    expect(screen.getByText('Two-step verification')).toBeTruthy();
    const field = screen.getByLabelText('Verification code');
    expect(field.props.keyboardType).toBe('number-pad');
    expect(field.props.textContentType).toBe('oneTimeCode');
    expect(field.props.maxLength).toBe(6);
    expect(api.me).not.toHaveBeenCalled();
    expect(screen.queryByText('signed in')).toBeNull();
  });

  it('keeps Verify disabled until six digits are entered and strips non-digits', async () => {
    const api = fakeApi();
    await toCodeStep(api);

    expect(screen.getByRole('button', { name: 'Verify' })).toBeDisabled();
    await fireEvent.changeText(screen.getByLabelText('Verification code'), '12a 34');
    expect(screen.getByLabelText('Verification code').props.value).toBe('1234');
    expect(screen.getByRole('button', { name: 'Verify' })).toBeDisabled();
    await fireEvent.changeText(screen.getByLabelText('Verification code'), '123456');
    expect(screen.getByRole('button', { name: 'Verify' })).not.toBeDisabled();
  });

  it('exchanges the challenge and code for a session', async () => {
    const api = fakeApi();
    await toCodeStep(api);

    await fireEvent.changeText(screen.getByLabelText('Verification code'), '123456');
    await fireEvent.press(screen.getByText('Verify'));

    await waitFor(() => screen.getByText('signed in'));
    expect(api.verifyTwoFactor).toHaveBeenCalledWith(CHALLENGE, '123456', 'code');
    expect(api.me).toHaveBeenCalled();
  });

  it('shows a wrong code as an error, clears the field and stays on the step', async () => {
    const api = fakeApi({ verifyTwoFactor: jest.fn(async () => { throw new ApiError(422, 'invalid_two_factor_code', 'That code is not correct.'); }) });
    await toCodeStep(api);

    await fireEvent.changeText(screen.getByLabelText('Verification code'), '000000');
    await fireEvent.press(screen.getByText('Verify'));

    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('That code is not correct.'));
    expect(screen.getByLabelText('Verification code').props.value).toBe('');
    expect(screen.queryByText('signed in')).toBeNull();
  });

  it('returns to the sign-in form when the challenge has expired or been used up', async () => {
    const api = fakeApi({ verifyTwoFactor: jest.fn(async () => { throw new ApiError(401, 'two_factor_challenge_invalid', 'This sign-in has expired. Sign in again.'); }) });
    await toCodeStep(api);

    await fireEvent.changeText(screen.getByLabelText('Verification code'), '123456');
    await fireEvent.press(screen.getByText('Verify'));

    await waitFor(() => screen.getByLabelText('Password'));
    expect(screen.getByRole('alert')).toHaveTextContent('This sign-in has expired. Sign in again.');
    // The password is not kept around for a silent retry.
    expect(screen.getByLabelText('Password').props.value).toBe('');
  });

  it('shows rate limiting without leaving the step', async () => {
    const api = fakeApi({ verifyTwoFactor: jest.fn(async () => { throw new ApiError(429, 'too_many_attempts', 'Too many attempts. Try again shortly.'); }) });
    await toCodeStep(api);

    await fireEvent.changeText(screen.getByLabelText('Verification code'), '123456');
    await fireEvent.press(screen.getByText('Verify'));

    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Too many attempts. Try again shortly.'));
    expect(screen.getByLabelText('Verification code')).toBeTruthy();
  });

  it('goes back to sign in without calling the server', async () => {
    const api = fakeApi();
    await toCodeStep(api);

    await fireEvent.press(screen.getByText('Back to sign in'));

    await waitFor(() => screen.getByLabelText('Password'));
    expect(screen.queryByRole('alert')).toBeNull();
    expect(api.verifyTwoFactor).not.toHaveBeenCalled();
  });

  it('accepts a backup code with the backup_code method', async () => {
    const api = fakeApi();
    await toCodeStep(api);

    await fireEvent.press(screen.getByText('Use a backup code'));
    await fireEvent.changeText(screen.getByLabelText('Backup code'), '222222');
    await fireEvent.press(screen.getByText('Verify'));

    await waitFor(() => screen.getByText('signed in'));
    expect(api.verifyTwoFactor).toHaveBeenCalledWith(CHALLENGE, '222222', 'backup_code');
  });

  it('can send a new code', async () => {
    const api = fakeApi();
    await toCodeStep(api);

    await fireEvent.press(screen.getByText('Send a new code'));

    await waitFor(() => screen.getByText('A new code is on its way. The previous code no longer works.'));
    expect(api.resendTwoFactor).toHaveBeenCalledWith(CHALLENGE);
  });

  it('accounts without two-factor still sign in in one step', async () => {
    const api = fakeApi({ login: jest.fn(async () => token) });
    await render(<AuthProvider apiOverride={api}><LoginScreen /><SignedInProbe /></AuthProvider>);
    await waitFor(() => screen.getByLabelText('Email'));
    await fireEvent.changeText(screen.getByLabelText('Email'), 'pat@example.test');
    await fireEvent.changeText(screen.getByLabelText('Password'), 'correct-pass');
    await fireEvent.press(screen.getByText('Sign in'));

    await waitFor(() => screen.getByText('signed in'));
    expect(api.verifyTwoFactor).not.toHaveBeenCalled();
  });
});

describe('two-factor api calls', () => {
  function mk(status: number, body: unknown) {
    const calls: { url: string; init: any }[] = [];
    const onUnauthorized = jest.fn();
    const fetchImpl = jest.fn(async (url: any, init: any) => {
      calls.push({ url: String(url), init });
      return { ok: status < 400, status, json: async () => body } as Response;
    });
    const api = createApi({ baseUrl: 'https://api.example.test', getToken: () => 'stale', onUnauthorized, fetchImpl: fetchImpl as any });
    return { api, calls, onUnauthorized };
  }

  it('posts challenge, code and method without a bearer token', async () => {
    const { api, calls } = mk(200, { data: token });
    await expect(api.verifyTwoFactor(CHALLENGE, '123456', 'backup_code')).resolves.toMatchObject({ token: 't-2fa' });
    expect(calls[0].url).toBe('https://api.example.test/api/mobile/v1/auth/two-factor');
    expect(JSON.parse(calls[0].init.body)).toEqual({ challenge: CHALLENGE, code: '123456', method: 'backup_code' });
    expect(calls[0].init.headers.Authorization).toBeUndefined();
  });

  it('does not treat an expired challenge 401 as losing a session', async () => {
    const { api, onUnauthorized } = mk(401, { error: { code: 'two_factor_challenge_invalid', message: 'x' } });
    await expect(api.verifyTwoFactor(CHALLENGE, '123456')).rejects.toMatchObject({ code: 'two_factor_challenge_invalid' });
    await expect(api.resendTwoFactor(CHALLENGE)).rejects.toMatchObject({ status: 401 });
    expect(onUnauthorized).not.toHaveBeenCalled();
  });
});
