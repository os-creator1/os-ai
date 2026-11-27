import type React from 'react';
import { CallHistoryPreview, DialerPreview, InCallPreview, IncomingCallPreview, VoicemailPreview } from './CallingFuture';
import { AppointmentDetailPreview, CalendarAgendaPreview, CalendarDayPreview, NewAppointmentPreview } from './CalendarFuture';
import {
  AttachmentsPreview, EmailMessagePreview, InboxFiltersPreview, InternalNotesPreview, NewConversationPreview, TemplatesPreview, ThreadActionsPreview,
} from './ConversationsFuture';
import { AboutPreview, ActivityCentrePreview, NotificationPreferencesPreview, NotificationsExplainerPreview } from './NotificationsFuture';
import { NewOpportunityPreview, OpportunityDetailPreview, PipelinePreview } from './OpportunitiesFuture';
import type { PreviewProps } from './parts';

export type GalleryEntry = {
  id: string;
  title: string;
  group: 'Conversations' | 'Notifications & settings' | 'Calendar' | 'Opportunities' | 'Calling';
  component: React.ComponentType<PreviewProps>;
  /** The web feature this screen mirrors. */
  mirrors: string;
  /** What the mobile API needs before this screen can show real data. */
  api: string;
};

/** Every planned pre-release screen from the design, as static UI with fake data. */
export const GALLERY: GalleryEntry[] = [
  { id: 'new-conversation', title: 'New conversation', group: 'Conversations', component: NewConversationPreview,
    mirrors: 'Conversations → New message (contact picker, channel, sender number)',
    api: 'Contact search, sender numbers per business, start conversation (first SMS/email) endpoint' },
  { id: 'attachments', title: 'Attachments + photo messages', group: 'Conversations', component: AttachmentsPreview,
    mirrors: 'Conversation composer attachments / MMS',
    api: 'Media upload (signed URL or multipart), MMS send, media URLs on messages (exists: media_url), size limits; needs expo-image-picker (native)' },
  { id: 'templates', title: 'Templates sheet', group: 'Conversations', component: TemplatesPreview,
    mirrors: 'Conversation composer → Templates',
    api: 'List templates (with tags) per business, placeholder rendering' },
  { id: 'thread-actions', title: 'Thread actions', group: 'Conversations', component: ThreadActionsPreview,
    mirrors: 'Conversation header actions (unread, assign, tags, notes, close)',
    api: 'Mark unread, assign to teammate (+ team list), tags, close/reopen conversation' },
  { id: 'internal-notes', title: 'Internal notes in thread', group: 'Conversations', component: InternalNotesPreview,
    mirrors: 'Conversation internal notes + assignment events',
    api: 'Notes and system events in the thread payload, create note endpoint' },
  { id: 'email-message', title: 'Email message', group: 'Conversations', component: EmailMessagePreview,
    mirrors: 'Email channel in Conversations',
    api: 'Email messages in threads (subject, HTML/text body, attachments), reply / forward by email' },
  { id: 'inbox-filters', title: 'Inbox filters', group: 'Conversations', component: InboxFiltersPreview,
    mirrors: 'Conversations list filters (status, channel, location)',
    api: 'Filters: assigned to me, closed, channel, location; filtered result count; channel per conversation' },
  { id: 'activity', title: 'Activity centre', group: 'Notifications & settings', component: ActivityCentrePreview,
    mirrors: 'Automation activity / notifications feed',
    api: 'Activity feed (automations, bookings, payments, failures) with read state + "read all"' },
  { id: 'notification-preferences', title: 'Notification preferences', group: 'Notifications & settings', component: NotificationPreferencesPreview,
    mirrors: 'User notification settings',
    api: 'Per-category push preferences (get / update) honoured by the push sender' },
  { id: 'notifications-explainer', title: 'Notifications explainer', group: 'Notifications & settings', component: NotificationsExplainerPreview,
    mirrors: 'n/a (mobile onboarding)',
    api: 'None — uses the existing permission + device registration' },
  { id: 'about', title: 'Settings — About', group: 'Notifications & settings', component: AboutPreview,
    mirrors: 'Legal pages, help, account deletion on the website',
    api: 'None — needs the public URLs (privacy, terms, support, account deletion) as config' },
  { id: 'calendar-agenda', title: 'Calendar — agenda', group: 'Calendar', component: CalendarAgendaPreview,
    mirrors: 'Calendar / appointments',
    api: 'Appointments by date range per business (status, location, calendar colour)' },
  { id: 'calendar-day', title: 'Calendar — day', group: 'Calendar', component: CalendarDayPreview,
    mirrors: 'Calendar day view',
    api: 'Same as agenda (time-positioned)' },
  { id: 'appointment-detail', title: 'Appointment detail', group: 'Calendar', component: AppointmentDetailPreview,
    mirrors: 'Appointment detail',
    api: 'Appointment by id (contact, notes, location), reschedule, cancel' },
  { id: 'new-appointment', title: 'New appointment', group: 'Calendar', component: NewAppointmentPreview,
    mirrors: 'Create appointment',
    api: 'Create appointment, list calendars, contact search, availability' },
  { id: 'pipeline', title: 'Opportunities — pipeline', group: 'Opportunities', component: PipelinePreview,
    mirrors: 'Opportunities pipeline board',
    api: 'Pipelines, stages with counts/totals, opportunities by stage (value, time in stage)' },
  { id: 'opportunity-detail', title: 'Opportunity detail', group: 'Opportunities', component: OpportunityDetailPreview,
    mirrors: 'Opportunity detail',
    api: 'Opportunity by id, move stage, update value/date/owner, mark won/lost, linked conversation' },
  { id: 'new-opportunity', title: 'New opportunity', group: 'Opportunities', component: NewOpportunityPreview,
    mirrors: 'Create opportunity',
    api: 'Create opportunity, pipelines + stages, owners (team), contact search' },
  { id: 'dialer', title: 'Dialer', group: 'Calling', component: DialerPreview,
    mirrors: 'Web dialer',
    api: 'Calling capability (exists), sender numbers, contact match by number, Telnyx WebRTC/SIP token; needs a native calling SDK' },
  { id: 'call-history', title: 'Call history', group: 'Calling', component: CallHistoryPreview,
    mirrors: 'Call log',
    api: 'Call log per business (direction, status, duration), missed count' },
  { id: 'incoming-call', title: 'Incoming call', group: 'Calling', component: IncomingCallPreview,
    mirrors: 'Inbound call routing',
    api: 'VoIP push + CallKit / ConnectionService (native), caller lookup with pipeline context' },
  { id: 'in-call', title: 'In call', group: 'Calling', component: InCallPreview,
    mirrors: 'Active call controls',
    api: 'Call control via the calling SDK (mute, hold, DTMF, speaker), call notes endpoint' },
  { id: 'voicemail', title: 'Voicemail', group: 'Calling', component: VoicemailPreview,
    mirrors: 'Voicemail inbox',
    api: 'Voicemails (audio URL, duration, transcript, read state); needs an audio player module (expo-audio)' },
];

export const galleryEntry = (id: string) => GALLERY.find((e) => e.id === id) ?? null;
