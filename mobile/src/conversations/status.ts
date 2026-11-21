import type { Message } from '../api/types';

/** What the bubble footer says about an outgoing message. Pure so it can be tested without rendering. */
export function statusLabel(m: Pick<Message, 'status'>): string | null {
  switch (m.status) {
    case 'sending': return 'Sending…';
    case 'sent': return '✓ Sent';
    case 'delivered': return '✓✓ Delivered';
    case 'failed': return 'Not sent';
    case 'delivery_failed': return 'Delivery failed';
    case 'ambiguous': return 'Status unknown';
    default: return null;
  }
}
export const isFailed = (s: Message['status']) => s === 'failed' || s === 'delivery_failed';
