/**
 * Fake data for the dev-only Design gallery (src/gallery). Static, invented content that mirrors the approved design;
 * nothing here is ever sent to or read from the API. Each screen gets real data per feature later.
 */
import type { IconName } from '../components';

export const business = { name: 'Kaleb Hill Photo Booth', fromNumber: '(512) 555-0100' };
export const me = { name: 'Mako' };

export type MockMessage = { id: string; dir: 'in' | 'out'; body?: string; photo?: string; time: string; status?: string; day?: string };

export const thread = {
  name: 'Ava Martinez',
  sub: 'SMS · +1 (737) 555-0192',
  messages: [
    { id: 'm1', day: 'Yesterday', dir: 'in', body: 'Hi! We loved your booth at the Thompson wedding. Are you free on Dec 12 in Round Rock?', time: '4:12 PM' },
    { id: 'm2', dir: 'out', body: 'Hi Ava! Yes, Dec 12 is open 🎉 Our Classic package is 3 hours with unlimited prints.', time: '4:20 PM', status: 'Delivered' },
    { id: 'm3', day: 'Today', dir: 'in', body: 'Perfect! Can we add the guest book too?', time: '10:02 AM' },
    { id: 'm4', dir: 'out', body: "Absolutely, it's $95 extra. Want me to send the updated quote?", time: '10:08 AM', status: 'Delivered' },
  ] as MockMessage[],
  photoMessages: [
    { id: 'p1', day: 'Today', dir: 'in', photo: '#C9A99A', body: 'This is the vibe for the backdrop!', time: '10:14 AM' },
    { id: 'p2', dir: 'out', photo: '#A7C4B5', time: '10:16 AM', status: 'Delivered' },
  ] as MockMessage[],
};

export const contacts = [
  { name: 'Ava Martinez', detail: '+1 (737) 555-0192' },
  { name: 'Alex Brooks', detail: '+1 (512) 555-0177' },
  { name: 'Amara Okafor', detail: 'amara@email.com' },
  { name: 'Andre Silva', detail: '+1 (512) 555-0123' },
];

export const recentPhotos = ['#D8B4A6', '#C3A6C9', '#A9CDB8', '#E2CFA0'];

export const templates = [
  { title: 'Booking confirmation', body: "Hi {name}! You're booked for {date} 🎉 We'll arrive 60 min early…", tag: 'Booking' },
  { title: 'Quote follow-up', body: 'Just checking in on the quote I sent. Happy to answer any…', tag: 'Follow-up' },
  { title: 'Deposit reminder', body: 'Friendly reminder: your deposit secures {date}. Pay here…', tag: 'Booking' },
  { title: 'Gallery ready', body: 'Your photos are ready! View and download the gallery…', tag: 'Follow-up' },
];

export const conversationsPreview = [
  { name: 'Ava Martinez', preview: 'Perfect! Can we add the guest book too?', time: '2m', unread: true },
  { name: null, phone: '+1 (512) 555-0148', preview: 'Hi, is the 360 booth free on Dec 12?', time: '18m', unread: true },
  { name: 'Jordan Lee', preview: 'Sent the deposit just now 👍', time: '1h', unread: false },
];

export const email = {
  from: 'Priya Shah',
  subject: 'Quote for corporate holiday party',
  meta: 'to you · Tue 3:41 PM',
  body: [
    'Hi Kaleb,',
    "We're planning our holiday party for about 120 people on Dec 18 at the Driskill. Could you send pricing for the 360 booth and the mirror booth, 4 hours each?",
    'Thanks!\nPriya',
  ],
  attachment: { name: 'Event-brief.pdf', size: '240 KB' },
  earlier: 2,
};

export const filters = {
  show: ['All open', 'Unread', 'Assigned to me', 'Closed'],
  channels: [{ label: 'SMS', icon: 'message-circle' as IconName }, { label: 'Email', icon: 'mail' as IconName }, { label: 'Facebook', icon: 'facebook' as IconName }],
  locations: ['All locations', 'Austin', 'San Antonio'],
  results: 24,
};

export type ActivityItem = { title: string; sub: string; time: string; icon: IconName; tone: 'brand' | 'success' | 'info' | 'warning' | 'danger' | 'violet'; unread?: boolean };
export const activity: { section: string; items: ActivityItem[] }[] = [
  { section: 'Today', items: [
    { title: 'Follow-up text sent', sub: 'to Ava Martinez · Quote follow-up', time: '2m', icon: 'zap', tone: 'brand', unread: true },
    { title: 'Deposit received · $250', sub: 'Jordan Lee · moved to Booked', time: '1h', icon: 'dollar-sign', tone: 'success', unread: true },
    { title: 'New booking', sub: 'Emily Chen · Dec 20, 6:00 PM', time: '3h', icon: 'calendar', tone: 'info' },
  ] },
  { section: 'Earlier', items: [
    { title: 'Review request sent', sub: 'to Marcus Bell after his event', time: 'Yesterday', icon: 'star', tone: 'warning' },
    { title: 'Text failed to send', sub: 'to +1 (512) 555-0148 · Tap to see why', time: 'Yesterday', icon: 'alert-circle', tone: 'danger' },
    { title: 'New lead from your website', sub: 'Sofia Rossi · Wedding', time: 'Mon', icon: 'user', tone: 'violet' },
  ] },
];

export const notificationPrefs: { section: string; items: { key: string; title: string; sub?: string; on: boolean }[] }[] = [
  { section: 'Messages', items: [
    { key: 'messages', title: 'New messages', sub: 'Texts and emails from contacts', on: true },
    { key: 'assigned', title: 'Only assigned to me', sub: 'Skip chats assigned to teammates', on: false },
  ] },
  { section: 'Automations', items: [
    { key: 'automation', title: 'Automation activity', sub: 'Follow-ups, reminders, review requests', on: true },
    { key: 'failures', title: 'Failures', sub: "When a text or email doesn't send", on: true },
  ] },
  { section: 'Business', items: [
    { key: 'bookings', title: 'New bookings', on: true },
    { key: 'payments', title: 'Payments & deposits', on: true },
    { key: 'leads', title: 'New leads', sub: 'From forms, website and ads', on: true },
  ] },
];

export const week = [
  { d: 'Mon', n: 23 }, { d: 'Tue', n: 24, dot: true }, { d: 'Wed', n: 25 }, { d: 'Thu', n: 26, today: true, dot: true },
  { d: 'Fri', n: 27 }, { d: 'Sat', n: 28, dot: true }, { d: 'Sun', n: 29, dot: true },
];

export type Appointment = { time: string; title: string; place: string; placeIcon: IconName; status?: { label: string; tone: 'success' | 'warning' }; accent: string };
export const agenda: { day: string; items: Appointment[] }[] = [
  { day: 'Thursday, Nov 26', items: [
    { time: '10:30 AM – 11:00 AM', title: 'Discovery call · Jordan Lee', place: 'Phone', placeIcon: 'phone', status: { label: 'Confirmed', tone: 'success' }, accent: '#1F5682' },
    { time: '2:00 PM – 6:00 PM', title: 'Martinez wedding · Classic booth', place: 'Round Rock Hall', placeIcon: 'map-pin', status: { label: 'Confirmed', tone: 'success' }, accent: '#B4514A' },
  ] },
  { day: 'Saturday, Nov 28', items: [
    { time: '5:00 PM – 9:00 PM', title: 'Bell birthday · 360 booth', place: 'The Venue ATX', placeIcon: 'map-pin', status: { label: 'Unpaid', tone: 'warning' }, accent: '#17693F' },
    { time: '7:30 PM – 8:00 PM', title: 'Setup check · Snapshot crew', place: 'Studio', placeIcon: 'map-pin', accent: '#5B3A7A' },
  ] },
];

export const dayHours = ['9 AM', '10 AM', '11 AM', '12 PM', '1 PM', '2 PM', '3 PM', '4 PM'];

export const appointment = {
  status: 'Confirmed',
  title: 'Martinez wedding',
  sub: 'Classic booth · 4 hours',
  when: 'Thu, Nov 26 · 2:00 – 6:00 PM',
  where: 'Round Rock Hall',
  contact: { name: 'Ava Martinez', phone: '+1 (737) 555-0192' },
  notes: 'Guest book add-on. Sage backdrop. Load-in through the side door.',
};

export const stages = [
  { name: 'New lead', count: 12 }, { name: 'Quote sent', count: 7 }, { name: 'Deposit paid', count: 5 }, { name: 'Booked', count: 9 }, { name: 'Done', count: 31 },
];

export const opportunities = [
  { name: 'Ava Martinez', sub: 'Wedding · Dec 12', value: '$1,545', age: '2d in stage', stale: false, notes: 2 },
  { name: 'Priya Shah', sub: 'Corporate · Dec 18', value: '$2,400', age: '1d in stage', stale: false, notes: 2 },
  { name: 'Emily Chen', sub: 'Birthday · Dec 20', value: '$895', age: '6d in stage', stale: true, notes: 2 },
  { name: null, phone: '+1 (512) 555-0148', sub: '360 booth · Dec 12', value: '$1,200', age: '4h in stage', stale: false, notes: 2 },
];

export const opportunity = {
  pipeline: 'Event bookings',
  title: 'Ava Martinez · Wedding',
  value: '$1,545',
  stage: 'Quote sent',
  stageIndex: 1,
  eventDate: 'Dec 12, 2026',
  owner: 'Mako',
  lastMessage: 'Perfect! Can we add the guest book too?',
};

export const keypad: [string, string][] = [
  ['1', ''], ['2', 'ABC'], ['3', 'DEF'], ['4', 'GHI'], ['5', 'JKL'], ['6', 'MNO'], ['7', 'PQRS'], ['8', 'TUV'], ['9', 'WXYZ'], ['*', ''], ['0', '+'], ['#', ''],
];

export type CallItem = { name: string; kind: 'missed' | 'incoming' | 'outgoing' | 'voicemail'; detail: string; time: string };
export const calls: CallItem[] = [
  { name: '+1 (512) 555-0148', kind: 'missed', detail: 'Missed · 0:00', time: '10:41 AM' },
  { name: 'Jordan Lee', kind: 'incoming', detail: 'Incoming · 4:12', time: '9:15 AM' },
  { name: 'Ava Martinez', kind: 'outgoing', detail: 'Outgoing · 2:03', time: 'Yesterday' },
  { name: 'Emily Chen', kind: 'voicemail', detail: 'Voicemail · 0:38', time: 'Yesterday' },
  { name: 'Glow Booth Austin', kind: 'outgoing', detail: 'Outgoing · 11:20', time: 'Mon' },
  { name: 'Marcus Bell', kind: 'missed', detail: 'Missed', time: 'Mon' },
];

export const caller = { name: 'Ava Martinez', phone: '+1 (737) 555-0192', context: 'Quote sent · $1,545', duration: '04:12' };

export const voicemails = [
  { name: 'Emily Chen', meta: 'Yesterday · 0:38', position: '0:19', transcript: '“Hi! Calling about the sage backdrop for the 20th, can you call me back…”', unread: true },
  { name: '+1 (512) 555-0177', meta: 'Mon · 1:05', transcript: 'Hey, this is Alex from the Driskill events team. We have a couple…' },
  { name: 'Marcus Bell', meta: 'Nov 19 · 0:22', transcript: 'Just wanted to say thanks again, everyone loved the prints!' },
];

/** Relative bar heights of the voicemail waveform (0–1). */
export const waveform = [0.3, 0.5, 0.4, 0.8, 0.6, 0.9, 0.5, 0.7, 0.4, 0.6, 0.9, 0.5, 0.3, 0.7, 0.5, 0.8, 0.4, 0.6, 0.3, 0.5, 0.7, 0.4, 0.6, 0.3, 0.5, 0.4, 0.6, 0.3];

export const about = { version: '1.4.0 (58)', links: ['Privacy policy', 'Terms of service', 'Help & support'] };
