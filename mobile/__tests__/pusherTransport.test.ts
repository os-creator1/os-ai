import type { RealtimeConfig } from '../src/api/types';
import type { TransportFactory } from '../src/realtime/types';

// The real pusher-js React Native build needs NetInfo's native module.
jest.mock('@react-native-community/netinfo', () => require('@react-native-community/netinfo/jest/netinfo-mock.js'));

const cfg: RealtimeConfig = {
  enabled: true, provider: 'pusher', key: 'app-key', cluster: 'mt1', host: '192.0.2.10', port: 6001, tls: false,
} as RealtimeConfig;

class FakePusher {
  static instances: unknown[][] = [];
  connection = { bind: jest.fn(), unbind: jest.fn() };
  subscribe = jest.fn(() => ({ bind: jest.fn(), unbind: jest.fn() }));
  unsubscribe = jest.fn();
  disconnect = jest.fn();
  constructor(...args: unknown[]) { FakePusher.instances.push(args); }
}

function loadTransportWith(moduleShape: object): TransportFactory {
  let factory: TransportFactory | undefined;
  jest.isolateModules(() => {
    jest.doMock('pusher-js/react-native', () => moduleShape);
    factory = require('../src/realtime/pusherTransport').createPusherTransport;
  });
  return factory as TransportFactory;
}

describe('createPusherTransport', () => {
  beforeEach(() => { FakePusher.instances = []; });
  afterEach(() => { jest.dontMock('pusher-js/react-native'); });

  it('matches the module shape pusher-js actually ships for React Native: a named `Pusher`, no default export', () => {
    const actual = jest.requireActual('pusher-js/react-native');
    expect(typeof actual.Pusher).toBe('function');
    expect(actual.__esModule).toBeFalsy();
  });

  // Regression: `import Pusher from 'pusher-js/react-native'` resolved to the wrapper object on a device, so
  // `new Pusher()` threw "Object cannot be used as a constructor", RealtimeProvider swallowed it, and the phone
  // never received live updates (open-thread replies and reconnect resync only showed after pull-to-refresh).
  it.each([
    ['React Native runtime shape (module.exports.Pusher)', { Pusher: FakePusher }],
    ['default-export shape (as the typings declare)', { __esModule: true, default: FakePusher }],
  ])('constructs a working client with the %s', (_label, shape) => {
    const transport = loadTransportWith(shape)(cfg, async () => ({ auth: 'sig' }));

    expect(FakePusher.instances).toHaveLength(1);
    const [key, options] = FakePusher.instances[0] as [string, Record<string, unknown>];
    expect(key).toBe('app-key');
    expect(options).toMatchObject({ wsHost: '192.0.2.10', wsPort: 6001, forceTLS: false });

    const off = transport.subscribe('private-mobile.business.b1234567.user.u1234567', jest.fn());
    off();
    transport.disconnect();
  });
});
