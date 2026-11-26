import React, { useState } from 'react';
import { ScrollView, StyleSheet, View } from 'react-native';
import {
  Avatar, Button, Chip, Icon, IconButton, ListGroup, ListRow, SearchField, SectionHeader, SegmentedControl, Text, UnreadDot,
} from '../components';
import * as mock from '../mocks/design';
import { useBottomInset, useTopInset } from '../screens/ui';
import { colors, radius, space } from '../theme';
import { FutureTabBar, MockComposer, MockThread, NavBar, Sheet, gs, type PreviewProps } from './parts';

const ThreadUnderlay = ({ onBack, messages = mock.thread.messages }: PreviewProps & { messages?: mock.MockMessage[] }) => (
  <View style={gs.screen}>
    <NavBar white title={mock.thread.name} sub={mock.thread.sub} onLeft={onBack} right={{ icon: 'phone', label: 'Call' }} />
    <MockThread messages={messages} />
    <MockComposer />
  </View>
);

/** New conversation: recipient field, channel + sender number, contact suggestions. */
export function NewConversationPreview({ onBack }: PreviewProps) {
  const [channel, setChannel] = useState<'SMS' | 'Email'>('SMS');
  return (
    <View style={gs.white}>
      <NavBar title="New message" left="close" onLeft={onBack} style={{ backgroundColor: colors.surface }} />
      <View style={s.toBlock}>
        <View style={s.to}><Text color={colors.textSecondary}>To:</Text><Text>A</Text></View>
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={gs.row}>
          <Chip label="SMS" icon="message-circle" selected={channel === 'SMS'} onPress={() => setChannel('SMS')} />
          <Chip label="Email" icon="mail" selected={channel === 'Email'} onPress={() => setChannel('Email')} />
          <Chip label={`From ${mock.business.fromNumber}`} icon="chevron-down" />
        </ScrollView>
      </View>
      <SectionHeader title="Contacts" style={s.sectionPad} />
      <ScrollView style={gs.flex}>
        {mock.contacts.map((c) => (
          <View key={c.name} style={s.divider}>
            <ListRow leading={<Avatar name={c.name} size={36} />} title={c.name} subtitle={c.detail} onPress={() => undefined} />
          </View>
        ))}
        <View style={s.divider}>
          <ListRow leading={<View style={s.plus}><Icon name="plus" size={18} color={colors.brandPressed} /></View>} title="Text a new number" accent onPress={() => undefined} />
        </View>
      </ScrollView>
      <MockComposer />
    </View>
  );
}

/** Attachments sheet over a thread with photo messages (MMS). */
export function AttachmentsPreview({ onBack }: PreviewProps) {
  return (
    <Sheet title="Add to message" onClose={onBack} underlay={<ThreadUnderlay onBack={onBack} messages={mock.thread.photoMessages} />}>
      <View style={gs.row}>
        {mock.recentPhotos.map((c) => <View key={c} style={[s.thumb, { backgroundColor: c }]} accessibilityLabel="Recent photo" />)}
      </View>
      <ListGroup>
        <ListRow icon={{ name: 'image', bg: colors.infoSoft, fg: colors.info }} title="Photo library" chevron onPress={() => undefined} />
        <ListRow icon={{ name: 'camera', bg: colors.successSoft, fg: colors.success }} title="Take a photo" chevron onPress={() => undefined} />
        <ListRow icon={{ name: 'file' }} title="File" chevron onPress={() => undefined} />
      </ListGroup>
      <Text size={13} color={colors.textSecondary}>Photos send as MMS. Up to 5 MB.</Text>
    </Sheet>
  );
}

/** Templates sheet: search, tag filter, template list. */
export function TemplatesPreview({ onBack }: PreviewProps) {
  const [tag, setTag] = useState('All');
  const shown = mock.templates.filter((t) => tag === 'All' || t.tag === tag);
  return (
    <Sheet title="Templates" onClose={onBack} underlay={<ThreadUnderlay onBack={onBack} />}>
      <SearchField placeholder="Search templates" accessibilityLabel="Search templates" />
      <View style={gs.row}>
        {['All', 'Booking', 'Follow-up'].map((t) => <Chip key={t} label={t} selected={tag === t} onPress={() => setTag(t)} />)}
      </View>
      <ListGroup>
        {shown.map((t) => <ListRow key={t.title} title={t.title} subtitle={t.body} strong onPress={() => undefined} />)}
      </ListGroup>
    </Sheet>
  );
}

/** Thread actions: unread, assign, tag, note, close. */
export function ThreadActionsPreview({ onBack }: PreviewProps) {
  return (
    <Sheet title={mock.thread.name} onClose={onBack} underlay={<ThreadUnderlay onBack={onBack} />}>
      <ListGroup>
        <ListRow icon={{ name: 'disc', bg: colors.brandSoft, fg: colors.brandPressed }} title="Mark as unread" onPress={() => undefined} />
        <ListRow icon={{ name: 'user', bg: colors.infoSoft, fg: colors.info }} title="Assign to teammate" value="Unassigned" chevron onPress={() => undefined} />
        <ListRow icon={{ name: 'tag', bg: colors.successSoft, fg: colors.success }} title="Add tag" value="Wedding" chevron onPress={() => undefined} />
        <ListRow icon={{ name: 'file-text', bg: colors.warningSoft, fg: colors.warning }} title="Add internal note" onPress={() => undefined} />
      </ListGroup>
      <ListGroup>
        <ListRow icon={{ name: 'archive' }} title="Close conversation" onPress={() => undefined} />
      </ListGroup>
    </Sheet>
  );
}

/** Internal notes inline in a thread + Reply/Note composer switch. */
export function InternalNotesPreview({ onBack }: PreviewProps) {
  const bottom = useBottomInset();
  const [mode, setMode] = useState<'reply' | 'note'>('note');
  const question = mock.thread.messages[2];
  const answer = mock.thread.messages[3];
  return (
    <View style={gs.screen}>
      <NavBar white title={mock.thread.name} sub={mock.thread.sub} onLeft={onBack} right={{ icon: 'phone', label: 'Call' }} />
      <MockThread messages={[question]}>
        <View style={s.note}>
          <Icon name="file-text" size={16} color={colors.warning} />
          <View style={gs.flex}>
            <Text size={12} weight="700" color={colors.warning} style={s.noteHead}>INTERNAL NOTE · {mock.me.name.toUpperCase()}</Text>
            <Text size={14.5} color={colors.warning}>Bride wants sage backdrop. Check stock before quoting. Only your team sees this.</Text>
          </View>
        </View>
        <View style={s.assigned}>
          <Icon name="user" size={12} color={colors.textSecondary} />
          <Text size={12} color={colors.textSecondary}>Assigned to Jamie · 10:05 AM</Text>
        </View>
        <View style={s.answer}>
          <View style={s.outBubble}><Text color={colors.onBrand}>{answer.body}</Text></View>
          <Text size={12} color={colors.textSecondary} style={s.end}>{answer.time} · ✓✓ Delivered</Text>
        </View>
      </MockThread>
      <View style={[s.noteComposer, { paddingBottom: space.sm + bottom }]}>
        <View style={{ width: 160 }}>
          <SegmentedControl options={[{ value: 'reply', label: 'Reply' }, { value: 'note', label: 'Note' }]} value={mode} onChange={setMode} />
        </View>
        <View style={gs.row}>
          <View style={[s.noteBox, mode === 'reply' && s.replyBox]}>
            <Text color={mode === 'note' ? colors.warning : colors.textSecondary}>{mode === 'note' ? 'Write a note for your team…' : 'Message'}</Text>
          </View>
          <View style={[s.noteSend, mode === 'reply' && { backgroundColor: colors.brand }]}>
            <Icon name={mode === 'note' ? 'check' : 'send'} size={19} color={colors.onBrand} />
          </View>
        </View>
      </View>
    </View>
  );
}

/** An email in the conversation: subject, sender, body, attachment, Reply / Forward. */
export function EmailMessagePreview({ onBack }: PreviewProps) {
  const bottom = useBottomInset();
  const e = mock.email;
  return (
    <View style={gs.white}>
      <NavBar white title={e.from} sub="Email" onLeft={onBack} right={{ icon: 'more-horizontal', label: 'More' }} />
      <ScrollView contentContainerStyle={[gs.pad, { gap: 14 }]}>
        <Text size={21} weight="800" accessibilityRole="header">{e.subject}</Text>
        <View style={gs.row}>
          <Avatar name={e.from} size={36} />
          <View style={gs.flex}>
            <Text size={15} weight="700">{e.from}</Text>
            <Text size={13} color={colors.textSecondary}>{e.meta}</Text>
          </View>
          <View style={s.emailPill}><Icon name="mail" size={12} color={colors.info} /><Text size={12.5} weight="700" color={colors.info}>Email</Text></View>
        </View>
        {e.body.map((p) => <Text key={p} style={{ lineHeight: 24 }}>{p}</Text>)}
        <View style={s.attachment}>
          <Icon name="file" size={20} color={colors.danger} />
          <View>
            <Text size={15} weight="600">{e.attachment.name}</Text>
            <Text size={13} color={colors.textSecondary}>{e.attachment.size}</Text>
          </View>
        </View>
        <View style={[gs.row, s.earlier]}>
          <Icon name="mail" size={14} color={colors.textSecondary} />
          <Text size={13.5} color={colors.textSecondary}>{e.earlier} earlier emails</Text>
        </View>
      </ScrollView>
      <View style={[s.emailActions, { paddingBottom: space.sm + bottom }]}>
        <Button title="Reply" icon="chevron-left" variant="secondary" style={gs.flex} onPress={() => undefined} />
        <Button title="Forward" variant="secondary" style={gs.flex} onPress={() => undefined} />
      </View>
    </View>
  );
}

/** Inbox filters sheet over the Conversations list (show / channel / location). */
export function InboxFiltersPreview({ onBack }: PreviewProps) {
  const top = useTopInset();
  const [show, setShow] = useState('All open');
  const [channels, setChannels] = useState<string[]>([]);
  const [location, setLocation] = useState('All locations');
  const underlay = (
    <View style={gs.white}>
      <View style={[s.listTop, { paddingTop: top + space.sm }]}>
        <View style={gs.row}>
          <View style={[gs.row, gs.flex]}><Text size={15} weight="600" color={colors.textTertiary}>{mock.business.name}</Text><Icon name="chevron-down" size={16} color={colors.textTertiary} /></View>
          <IconButton icon="bell" filled accessibilityLabel="Activity" onPress={() => undefined} />
          <IconButton icon="edit-2" filled accessibilityLabel="New conversation" onPress={() => undefined} />
        </View>
        <Text variant="largeTitle">Conversations</Text>
        <SearchField placeholder="Search names, numbers, messages" editable={false} />
      </View>
      {mock.conversationsPreview.map((c) => (
        <View key={c.time} style={s.cv}>
          <Avatar name={c.name} />
          <View style={gs.flex}>
            <View style={gs.row}><Text weight="800" style={gs.flex}>{c.name ?? c.phone}</Text><Text size={13} weight="700" color={colors.brandPressed}>{c.time}</Text></View>
            <View style={gs.row}><Text size={14.5} weight="500" numberOfLines={1} style={gs.flex}>{c.preview}</Text>{c.unread ? <UnreadDot /> : null}</View>
          </View>
        </View>
      ))}
      <View style={gs.flex} />
      <FutureTabBar active="Inbox" />
    </View>
  );
  const toggle = (c: string) => setChannels((x) => (x.includes(c) ? x.filter((y) => y !== c) : [...x, c]));
  return (
    <Sheet title="Filter conversations" onClose={onBack} underlay={underlay}>
      <SectionHeader title="Show" />
      <View style={gs.wrap}>{mock.filters.show.map((o) => <Chip key={o} label={o} selected={show === o} onPress={() => setShow(o)} />)}</View>
      <SectionHeader title="Channel" />
      <View style={gs.wrap}>{mock.filters.channels.map((c) => <Chip key={c.label} label={c.label} icon={c.icon} selected={channels.includes(c.label)} onPress={() => toggle(c.label)} />)}</View>
      <SectionHeader title="Location" />
      <ListGroup>
        {mock.filters.locations.map((l) => (
          <ListRow key={l} title={l} onPress={() => setLocation(l)} trailing={location === l ? <Icon name="check" size={18} color={colors.brand} /> : null} />
        ))}
      </ListGroup>
      <View style={gs.row}>
        <Button title="Reset" variant="secondary" style={{ flex: 1 }} onPress={() => { setShow('All open'); setChannels([]); setLocation('All locations'); }} />
        <Button title={`Show ${mock.filters.results} results`} style={{ flex: 1.4 }} onPress={onBack} />
      </View>
    </Sheet>
  );
}

const s = StyleSheet.create({
  toBlock: { paddingHorizontal: space.md, gap: space.mid, paddingBottom: space.mid },
  to: { flexDirection: 'row', alignItems: 'center', gap: space.sm, minHeight: 48, borderRadius: radius.field, borderWidth: 1.5, borderColor: colors.brand, paddingHorizontal: 14 },
  sectionPad: { paddingHorizontal: space.md, paddingVertical: space.sm },
  divider: { borderBottomWidth: 1, borderBottomColor: colors.divider },
  plus: { width: 36, height: 36, borderRadius: 18, backgroundColor: colors.brandSoft, alignItems: 'center', justifyContent: 'center' },
  thumb: { flex: 1, aspectRatio: 1, borderRadius: radius.field },
  note: { flexDirection: 'row', gap: 10, padding: 12, borderRadius: radius.md, borderWidth: 1, borderStyle: 'dashed', borderColor: '#E7C98F', backgroundColor: colors.warningSoft },
  noteHead: { letterSpacing: 0.6, marginBottom: 2 },
  assigned: { flexDirection: 'row', alignItems: 'center', gap: 4, alignSelf: 'center' },
  answer: { gap: 4 },
  end: { alignSelf: 'flex-end' },
  outBubble: { alignSelf: 'flex-end', maxWidth: '76%', backgroundColor: colors.brand, borderRadius: 20, borderBottomRightRadius: 6, paddingVertical: 10, paddingHorizontal: 14 },
  noteComposer: { paddingTop: space.sm, paddingHorizontal: 10, gap: space.sm, backgroundColor: colors.surface, borderTopWidth: 1, borderTopColor: colors.border },
  noteBox: { flex: 1, minHeight: 44, borderRadius: 22, borderWidth: 1, borderColor: '#E7C98F', backgroundColor: colors.warningSoft, paddingHorizontal: 16, justifyContent: 'center' },
  replyBox: { borderColor: colors.borderStrong, backgroundColor: colors.composerFill },
  noteSend: { width: 44, height: 44, borderRadius: 22, backgroundColor: colors.warning, alignItems: 'center', justifyContent: 'center' },
  emailPill: { flexDirection: 'row', alignItems: 'center', gap: 4, height: 26, paddingHorizontal: 10, borderRadius: 13, backgroundColor: colors.infoSoft },
  attachment: { flexDirection: 'row', alignItems: 'center', gap: 12, padding: 14, borderRadius: radius.md, borderWidth: 1, borderColor: colors.borderSoft },
  earlier: { borderTopWidth: 1, borderTopColor: colors.divider, paddingTop: space.mid },
  emailActions: { flexDirection: 'row', gap: space.sm, paddingHorizontal: space.md, paddingTop: space.sm, borderTopWidth: 1, borderTopColor: colors.border },
  listTop: { paddingHorizontal: space.md, gap: space.mid, paddingBottom: space.sm },
  cv: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: space.md, paddingVertical: 14, borderBottomWidth: 1, borderBottomColor: colors.divider },
});

