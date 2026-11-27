import React, { useState } from 'react';
import { Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { Avatar, Button, Chip, Icon, IconButton, ListGroup, ListRow, Pill, SectionHeader, SegmentedControl, Text } from '../components';
import * as mock from '../mocks/design';
import { useTopInset } from '../screens/ui';
import { colors, radius, space } from '../theme';
import { FieldBox, FutureTabBar, NavBar, gs, type PreviewProps } from './parts';

/** Month title, Today / + buttons, week strip and the Agenda/Day switch shared by both calendar views. */
function CalendarTop({ view, onView }: { view: 'agenda' | 'day'; onView: (v: 'agenda' | 'day') => void }) {
  const top = useTopInset();
  return (
    <View style={[s.top, { paddingTop: top + space.sm }]}>
      <View style={gs.row}>
        <Text variant="largeTitle" style={gs.flex} accessibilityRole="header">November</Text>
        <Pressable accessibilityRole="button" style={s.today}><Text size={14} weight="700">Today</Text></Pressable>
        <IconButton icon="plus" filled accessibilityLabel="New appointment" onPress={() => undefined} />
      </View>
      <View style={s.week}>
        {mock.week.map((d) => (
          <View key={d.n} style={[s.dayCell, d.today && s.dayToday]} accessibilityLabel={`${d.d} ${d.n}${d.today ? ', today' : ''}`}>
            <Text size={12} weight="600" color={d.today ? colors.onBrand : colors.textSecondary}>{d.d}</Text>
            <Text size={18} weight="800" color={d.today ? colors.onBrand : colors.textPrimary}>{String(d.n)}</Text>
            <View style={[s.evDot, !d.dot && { opacity: 0 }, d.today && { backgroundColor: colors.onBrand }]} />
          </View>
        ))}
      </View>
      <SegmentedControl options={[{ value: 'agenda', label: 'Agenda' }, { value: 'day', label: 'Day' }]} value={view} onChange={onView} />
    </View>
  );
}

function Agenda() {
  return (
    <ScrollView contentContainerStyle={s.agenda}>
      {mock.agenda.map((g) => (
        <View key={g.day} style={{ gap: space.sm }}>
          <SectionHeader title={g.day} />
          {g.items.map((a) => (
            <View key={a.title} style={s.event}>
              <View style={[s.accent, { backgroundColor: a.accent }]} />
              <View style={[gs.flex, { gap: 3 }]}>
                <View style={gs.row}>
                  <Text size={13} color={colors.textSecondary} style={gs.flex}>{a.time}</Text>
                  {a.status ? <Pill label={a.status.label} tone={a.status.tone} /> : null}
                </View>
                <Text size={16} weight="700">{a.title}</Text>
                <View style={[gs.row, { gap: 4 }]}><Icon name={a.placeIcon} size={13} color={colors.textSecondary} /><Text size={13.5} color={colors.textSecondary}>{a.place}</Text></View>
              </View>
            </View>
          ))}
        </View>
      ))}
    </ScrollView>
  );
}

const HOUR = 64;

function DayView() {
  return (
    <ScrollView contentContainerStyle={{ paddingVertical: space.mid }}>
      <View style={{ height: HOUR * mock.dayHours.length }}>
        {mock.dayHours.map((h, i) => (
          <View key={h} style={[s.hour, { top: i * HOUR }]}>
            <Text size={11.5} color={colors.textSecondary} style={s.hourLabel}>{h}</Text>
            <View style={s.hourLine} />
          </View>
        ))}
        <View style={[s.block, s.blockInfo, { top: HOUR * 1.5 + 6, height: HOUR / 2 - 4 }]}>
          <Text size={13} weight="700" color={colors.info}>10:30 Discovery call · Jordan Lee</Text>
        </View>
        <View style={[s.now, { top: HOUR * 3.5 }]}><View style={s.nowDot} /><View style={s.nowLine} /></View>
        <View style={[s.block, s.blockBrand, { top: HOUR * 5 + 6, height: HOUR * 2.5 }]}>
          <Text size={14} weight="700">Martinez wedding</Text>
          <Text size={13}>2:00 – 6:00 PM · Round Rock Hall</Text>
        </View>
      </View>
    </ScrollView>
  );
}

/** Calendar with the Agenda/Day switch working locally. `start` picks the initial view. */
export function CalendarPreview({ start }: PreviewProps & { start: 'agenda' | 'day' }) {
  const [view, setView] = useState(start);
  return (
    <View style={gs.screen}>
      <CalendarTop view={view} onView={setView} />
      <View style={gs.flex}>{view === 'agenda' ? <Agenda /> : <DayView />}</View>
      <FutureTabBar active="Calendar" />
    </View>
  );
}

export const CalendarAgendaPreview = (p: PreviewProps) => <CalendarPreview {...p} start="agenda" />;
export const CalendarDayPreview = (p: PreviewProps) => <CalendarPreview {...p} start="day" />;

/** Appointment detail: status, time, place + directions, contact, notes, Reschedule / Cancel. */
export function AppointmentDetailPreview({ onBack }: PreviewProps) {
  const a = mock.appointment;
  return (
    <View style={gs.screen}>
      <NavBar onLeft={onBack} right={{ label: 'Edit' }} />
      <ScrollView contentContainerStyle={gs.pad}>
        <View style={{ gap: 6 }}>
          <Pill label={a.status} tone="success" icon="check" />
          <Text variant="title" accessibilityRole="header">{a.title}</Text>
          <Text size={15} color={colors.textSecondary}>{a.sub}</Text>
        </View>
        <ListGroup>
          <ListRow icon={{ name: 'clock', bg: colors.brandSoft, fg: colors.brandPressed }} title={a.when} />
          <ListRow icon={{ name: 'map-pin', bg: colors.infoSoft, fg: colors.info }} title={a.where} value="Directions" chevron onPress={() => undefined} />
        </ListGroup>
        <View style={{ gap: space.sm }}>
          <SectionHeader title="Contact" />
          <ListGroup>
            <ListRow leading={<Avatar name={a.contact.name} size={36} />} title={a.contact.name} subtitle={a.contact.phone} strong
              trailing={<IconButton icon="message-circle" filled size={18} accessibilityLabel="Message" onPress={() => undefined} />} />
          </ListGroup>
        </View>
        <View style={{ gap: space.sm }}>
          <SectionHeader title="Notes" />
          <View style={gs.card}><Text size={15} style={{ lineHeight: 22 }}>{a.notes}</Text></View>
        </View>
        <View style={gs.row}>
          <Button title="Reschedule" variant="secondary" style={gs.flex} onPress={() => undefined} />
          <Button title="Cancel" variant="destructive" style={gs.flex} onPress={() => undefined} />
        </View>
      </ScrollView>
    </View>
  );
}

/** New appointment form (static fields; Length chips switch locally). */
export function NewAppointmentPreview({ onBack }: PreviewProps) {
  const [length, setLength] = useState('4 hr');
  return (
    <View style={gs.screen}>
      <NavBar title="New appointment" left="close" onLeft={onBack} right={{ label: 'Save', onPress: onBack }} />
      <ScrollView contentContainerStyle={[gs.pad, { gap: 14 }]}>
        <FieldBox label="Contact" icon="user" value="Ava Martinez" />
        <FieldBox label="Title" value="Booth setup · Martinez wedding" />
        <View style={gs.row}>
          <FieldBox label="Date" icon="calendar" value="Nov 26" style={gs.flex} />
          <FieldBox label="Time" icon="clock" value="2:00 PM" style={gs.flex} />
        </View>
        <View style={{ gap: 6 }}>
          <Text size={13.5} weight="600" color={colors.textInk}>Length</Text>
          <View style={gs.row}>{['1 hr', '2 hr', '3 hr', '4 hr'].map((l) => <Chip key={l} label={l} selected={length === l} onPress={() => setLength(l)} />)}</View>
        </View>
        <FieldBox label="Location" icon="map-pin" placeholder="Add address" />
        <FieldBox label="Calendar" icon="calendar" value="Events calendar" />
        <FieldBox label="Notes" placeholder="Anything your crew should know" tall />
      </ScrollView>
    </View>
  );
}

const s = StyleSheet.create({
  top: { paddingHorizontal: space.md, paddingBottom: space.mid, gap: space.mid, backgroundColor: colors.surface, borderBottomWidth: 1, borderBottomColor: colors.border },
  today: { minHeight: 40, paddingHorizontal: 14, borderRadius: radius.sm, borderWidth: 1, borderColor: colors.borderStrong, justifyContent: 'center' },
  week: { flexDirection: 'row', justifyContent: 'space-between' },
  dayCell: { width: 44, alignItems: 'center', gap: 2, paddingVertical: 6, borderRadius: 12 },
  dayToday: { backgroundColor: colors.brand },
  evDot: { width: 5, height: 5, borderRadius: 3, backgroundColor: colors.brand },
  agenda: { padding: space.md, gap: space.md },
  event: { flexDirection: 'row', gap: 12, padding: 14, borderRadius: radius.md, backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.borderSoft },
  accent: { width: 4, borderRadius: 2 },
  hour: { position: 'absolute', left: 0, right: 0, flexDirection: 'row', alignItems: 'center', gap: space.sm, paddingHorizontal: space.md },
  hourLabel: { width: 44 },
  hourLine: { flex: 1, height: 1, backgroundColor: colors.border },
  block: { position: 'absolute', left: 68, right: space.md, borderRadius: 8, padding: 8, gap: 2 },
  blockInfo: { backgroundColor: colors.infoSoft, justifyContent: 'center' },
  blockBrand: { backgroundColor: colors.brandSoft, borderLeftWidth: 3, borderLeftColor: colors.brand },
  now: { position: 'absolute', left: 60, right: space.md, flexDirection: 'row', alignItems: 'center' },
  nowDot: { width: 9, height: 9, borderRadius: 5, backgroundColor: colors.brand },
  nowLine: { flex: 1, height: 2, backgroundColor: colors.brand },
});
