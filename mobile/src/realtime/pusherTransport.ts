import Pusher from 'pusher-js/react-native';
import type { RealtimeTransport, TransportFactory } from './types';

/** Production transport: the platform's existing Pusher-protocol broadcaster. Authorizes every private channel with the mobile token. */
export const createPusherTransport: TransportFactory = (cfg, authorize) => {
  const pusher = new Pusher(cfg.key as string, {
    cluster: cfg.cluster ?? 'mt1',
    ...(cfg.host ? { wsHost: cfg.host, wsPort: cfg.port ?? 443, wssPort: cfg.port ?? 443, forceTLS: cfg.tls, enabledTransports: ['ws', 'wss'] as ('ws' | 'wss')[] } : {}),
    channelAuthorization: {
      customHandler: ({ socketId, channelName }, callback) => {
        authorize(socketId, channelName)
          .then((res) => callback(null, res as never))
          .catch((e) => callback(e as Error, null as never));
      },
      endpoint: 'unused',
      transport: 'ajax',
    },
  });

  const transport: RealtimeTransport = {
    subscribe(channel, onChange) {
      const ch = pusher.subscribe(channel);
      ch.bind('conversation.changed', onChange);
      return () => { ch.unbind('conversation.changed', onChange); pusher.unsubscribe(channel); };
    },
    onConnected(cb) {
      const handler = (s: { current: string }) => { if (s.current === 'connected') cb(); };
      pusher.connection.bind('state_change', handler);
      return () => pusher.connection.unbind('state_change', handler);
    },
    disconnect() { pusher.disconnect(); },
  };
  return transport;
};
