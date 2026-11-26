import React, { useState } from 'react';
import { Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { Avatar, BrandMark, Chip, Icon, IconButton, SegmentedControl, Text, type IconName } from '../components';
import * as mock from '../mocks/design';
import { useBottomInset, useTopInset } from '../screens/ui';
import { colors, shadow, space } from '../theme';
import { FutureTabBar, gs, type PreviewProps } from './parts';

/** Call screens use a dark warm background (the design's gradient, as a solid: no gradient module is installed). */
const CALL_BG = '#2A1E1C';
const DECLINE = '#D2453A';
const ACCEPT = '#1F8A55';

/** Dialer: sender number, typed number with contact match, keypad, call button, delete. */
export function DialerPreview(_: PreviewProps) {
  const top = useTopInset();
  const [digits, setDigits] = useState('(737) 555-01');
  return (
    <View style={gs.screen}>
      <View style={[s.dialTop, { paddingTop: top + space.sm }]}>
        <Chip label={`From ${mock.business.fromNumber}`} icon="chevron-down" />
      </View>
      <View style={s.number}>
        <Text size={34} weight="500" accessibilityLabel={`Number ${digits}`}>{digits}</Text>
        <View style={[gs.row, { gap: 4 }]}><Icon name="user" size={13} color={colors.brandPressed} /><Text size={14} weight="600" color={colors.brandPressed}>Ava Martinez?</Text></View>
      </View>
      <View style={s.pad}>
        {mock.keypad.map(([k, sub]) => (
          <Pressable key={k} accessibilityRole="button" accessibilityLabel={k} onPress={() => setDigits((d) => d + k)}
            style={({ pressed }) => [s.key, pressed && { backgroundColor: colors.fill }]}>
            <Text size={30} weight="500">{k}</Text>
            <Text size={10} weight="700" color={colors.textSecondary} style={s.keySub}>{sub}</Text>
          </Pressable>
        ))}
      </View>
      <View style={s.callRow}>
        <View style={s.key0} />
        <View style={[s.round, { backgroundColor: ACCEPT }]} accessibilityRole="button" accessibilityLabel="Call"><Icon name="phone" size={26} color={colors.onBrand} /></View>
        <IconButton icon="delete" accessibilityLabel="Delete digit" onPress={() => setDigits((d) => d.slice(0, -1))} style={s.key0} />
      </View>
      <FutureTabBar active="Calls" />
    </View>
  );
}

const KIND: Record<mock.CallItem['kind'], { icon: IconName; color: string }> = {
  missed: { icon: 'phone-missed', color: colors.danger },
  incoming: { icon: 'phone-incoming', color: colors.success },
  outgoing: { icon: 'phone-outgoing', color: colors.info },
  voicemail: { icon: 'voicemail', color: colors.warning },
};

/** Calls tab header with Recent / Missed / Voicemail. */
function CallsTop({ tab, onTab }: { tab: string; onTab: (t: string) => void }) {
  const top = useTopInset();
  return (
    <View style={[s.callsTop, { paddingTop: top + space.sm }]}>
      <View style={s.titleRow}><Text variant="largeTitle" accessibilityRole="header">Calls</Text></View>
      <SegmentedControl options={[{ value: 'recent', label: 'Recent' }, { value: 'missed', label: 'Missed · 2' }, { value: 'voicemail', label: 'Voicemail · 1' }]} value={tab} onChange={onTab} />
    </View>
  );
}

function CallList({ missedOnly }: { missedOnly?: boolean }) {
  return (
    <ScrollView>
      {mock.calls.filter((c) => !missedOnly || c.kind === 'missed').map((c) => {
        const k = KIND[c.kind];
        const missed = c.kind === 'missed';
        return (
          <View key={c.name + c.time} style={s.callItem}>
            <View style={s.kindTile}><Icon name={k.icon} size={16} color={k.color} /></View>
            <View style={gs.flex}>
              <Text size={16} weight="600" color={missed ? colors.danger : colors.textPrimary}>{c.name}</Text>
              <Text size={13} color={colors.textSecondary}>{c.detail}</Text>
            </View>
            <Text size={13} color={colors.textSecondary}>{c.time}</Text>
            <IconButton icon="phone" size={17} color={colors.brand} accessibilityLabel={`Call ${c.name}`} onPress={() => undefined} />
          </View>
        );
      })}
    </ScrollView>
  );
}

function VoicemailList() {
  const [playing, setPlaying] = useState(true);
  return (
    <ScrollView>
      {mock.voicemails.map((v, i) => i === 0 ? (
        <View key={v.name} style={[s.vm, s.vmOpen]}>
          <View style={gs.row}>
            {v.unread ? <View style={s.vmDot} /> : null}
            <Text size={16} weight="700" style={gs.flex}>{v.name}</Text>
            <Text size={13} color={colors.textSecondary}>{v.meta}</Text>
          </View>
          <View style={gs.row}>
            <Pressable accessibilityRole="button" accessibilityLabel={playing ? 'Pause' : 'Play'} onPress={() => setPlaying((p) => !p)} style={s.play}>
              <Icon name={playing ? 'pause' : 'play'} size={18} color={colors.onBrand} />
            </Pressable>
            <View style={s.wave} accessibilityElementsHidden>
              {mock.waveform.map((h, j) => <View key={j} style={[s.bar, { height: 6 + h * 22, backgroundColor: j < 12 ? colors.brand : colors.borderStrong }]} />)}
            </View>
            <Text size={12.5} color={colors.textSecondary}>{v.position}</Text>
          </View>
          <Text size={14.5} color={colors.textInk}>{v.transcript}</Text>
          <View style={gs.row}>
            <Pressable style={s.vmBtn} accessibilityRole="button"><Icon name="phone" size={15} color={colors.textPrimary} /><Text size={14} weight="700">Call back</Text></Pressable>
            <Pressable style={s.vmBtn} accessibilityRole="button"><Icon name="message-circle" size={15} color={colors.textPrimary} /><Text size={14} weight="700">Text</Text></Pressable>
          </View>
        </View>
      ) : (
        <View key={v.name} style={[s.vm, gs.row, { alignItems: 'flex-start' }]}>
          <View style={s.playSmall}><Icon name="play" size={14} color={colors.brandPressed} /></View>
          <View style={gs.flex}>
            <View style={gs.row}><Text size={16} weight="600" style={gs.flex}>{v.name}</Text><Text size={13} color={colors.textSecondary}>{v.meta}</Text></View>
            <Text size={14} color={colors.textSecondary} numberOfLines={1}>{v.transcript}</Text>
          </View>
        </View>
      ))}
    </ScrollView>
  );
}

/** Call history (Recent / Missed / Voicemail switch locally), keypad FAB. */
export function CallHistoryPreview({ start = 'recent' }: PreviewProps & { start?: string }) {
  const [tab, setTab] = useState(start);
  return (
    <View style={gs.white}>
      <CallsTop tab={tab} onTab={setTab} />
      <View style={gs.flex}>{tab === 'voicemail' ? <VoicemailList /> : <CallList missedOnly={tab === 'missed'} />}</View>
      {tab !== 'voicemail' ? (
        <View style={s.fab} accessibilityRole="button" accessibilityLabel="Keypad"><Icon name="grid" size={22} color={colors.onBrand} /></View>
      ) : null}
      <FutureTabBar active="Calls" />
    </View>
  );
}

export const VoicemailPreview = (p: PreviewProps) => <CallHistoryPreview {...p} start="voicemail" />;

function CallControl({ icon, label, on, onPress }: { icon: IconName; label: string; on?: boolean; onPress?: () => void }) {
  return (
    <View style={s.control}>
      <Pressable accessibilityRole="button" accessibilityLabel={label} accessibilityState={{ selected: !!on }} onPress={onPress}
        style={[s.controlBtn, on && { backgroundColor: colors.surface }]}>
        <Icon name={icon} size={24} color={on ? colors.textPrimary : colors.onBrand} />
      </Pressable>
      <Text size={13} weight="600" color={colors.onBrand}>{label}</Text>
    </View>
  );
}

/** Incoming call: business context, caller, pipeline stage, Decline / Accept. */
export function IncomingCallPreview({ onBack }: PreviewProps) {
  const top = useTopInset();
  const bottom = useBottomInset();
  const c = mock.caller;
  return (
    <View style={[s.call, { paddingTop: top + space.lg, paddingBottom: space.xl + bottom }]}>
      <View style={[gs.row, { justifyContent: 'center' }]}>
        <BrandMark size={16} />
        <Text size={13} color="rgba(255,255,255,0.75)">MotionGrove · {mock.business.name}</Text>
      </View>
      <View style={s.caller}>
        <Avatar name={c.name} size={96} />
        <Text size={30} weight="800" color={colors.onBrand}>{c.name}</Text>
        <Text size={16} color="rgba(255,255,255,0.75)">{c.phone}</Text>
        <View style={s.context}><Text size={12.5} weight="700" color={colors.onBrand}>{c.context}</Text></View>
      </View>
      <View style={s.answerRow}>
        <View style={s.control}>
          <Pressable accessibilityRole="button" accessibilityLabel="Decline" onPress={onBack} style={[s.round, { backgroundColor: DECLINE }]}>
            <Icon name="phone-off" size={26} color={colors.onBrand} />
          </Pressable>
          <Text size={13} weight="600" color={colors.onBrand}>Decline</Text>
        </View>
        <View style={s.control}>
          <Pressable accessibilityRole="button" accessibilityLabel="Accept" style={[s.round, { backgroundColor: ACCEPT }]}>
            <Icon name="phone" size={26} color={colors.onBrand} />
          </Pressable>
          <Text size={13} weight="600" color={colors.onBrand}>Accept</Text>
        </View>
      </View>
    </View>
  );
}

/** In call: timer, mute / keypad / speaker / hold / note / contact, hang up. */
export function InCallPreview({ onBack }: PreviewProps) {
  const top = useTopInset();
  const bottom = useBottomInset();
  const [mute, setMute] = useState(true);
  const [speaker, setSpeaker] = useState(false);
  const [hold, setHold] = useState(false);
  const c = mock.caller;
  return (
    <View style={[s.call, { paddingTop: top + space.xl, paddingBottom: space.xl + bottom }]}>
      <View style={s.caller}>
        <Avatar name={c.name} size={80} />
        <Text size={26} weight="800" color={colors.onBrand}>{c.name}</Text>
        <Text size={16} color="rgba(255,255,255,0.8)">{hold ? 'On hold' : c.duration}</Text>
      </View>
      <View style={s.controls}>
        <CallControl icon="mic-off" label="Mute" on={mute} onPress={() => setMute((m) => !m)} />
        <CallControl icon="grid" label="Keypad" />
        <CallControl icon="volume-2" label="Speaker" on={speaker} onPress={() => setSpeaker((x) => !x)} />
        <CallControl icon="pause" label="Hold" on={hold} onPress={() => setHold((x) => !x)} />
        <CallControl icon="file-text" label="Note" />
        <CallControl icon="user" label="Contact" />
      </View>
      <Pressable accessibilityRole="button" accessibilityLabel="Hang up" onPress={onBack} style={[s.round, s.hangup]}>
        <Icon name="phone-off" size={26} color={colors.onBrand} />
      </Pressable>
    </View>
  );
}

const s = StyleSheet.create({
  dialTop: { alignItems: 'flex-end', paddingHorizontal: space.md },
  number: { alignItems: 'center', gap: 6, paddingVertical: space.lg },
  pad: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'center', columnGap: 28, rowGap: 14, paddingHorizontal: 40 },
  key: { width: 76, height: 76, borderRadius: 38, backgroundColor: colors.surface, alignItems: 'center', justifyContent: 'center', borderBottomWidth: 1, borderBottomColor: colors.border },
  keySub: { letterSpacing: 1.2, height: 12 },
  callRow: { flexDirection: 'row', justifyContent: 'center', alignItems: 'center', gap: 28, paddingVertical: space.lg },
  key0: { width: 76, height: 76, alignItems: 'center', justifyContent: 'center' },
  round: { width: 70, height: 70, borderRadius: 35, alignItems: 'center', justifyContent: 'center' },
  callsTop: { paddingHorizontal: space.md, paddingBottom: space.sm, gap: space.mid, backgroundColor: colors.surface },
  titleRow: { minHeight: 44, justifyContent: 'center' },
  callItem: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingLeft: space.md, paddingRight: space.xs, minHeight: 62, borderBottomWidth: 1, borderBottomColor: colors.divider },
  kindTile: { width: 32, height: 32, borderRadius: 16, backgroundColor: colors.neutralSoft, alignItems: 'center', justifyContent: 'center' },
  fab: { position: 'absolute', right: space.md, bottom: 104, width: 58, height: 58, borderRadius: 29, backgroundColor: colors.brand, alignItems: 'center', justifyContent: 'center', ...shadow.fab },
  vm: { paddingHorizontal: space.md, paddingVertical: 14, gap: 10, borderBottomWidth: 1, borderBottomColor: colors.divider },
  vmOpen: { backgroundColor: '#FBF4F2' },
  vmDot: { width: 8, height: 8, borderRadius: 4, backgroundColor: colors.brand },
  play: { width: 44, height: 44, borderRadius: 22, backgroundColor: colors.brand, alignItems: 'center', justifyContent: 'center' },
  playSmall: { width: 32, height: 32, borderRadius: 16, backgroundColor: colors.brandSoft, alignItems: 'center', justifyContent: 'center' },
  wave: { flex: 1, flexDirection: 'row', alignItems: 'center', gap: 2, height: 32 },
  bar: { flex: 1, borderRadius: 2 },
  vmBtn: { flex: 1, flexDirection: 'row', alignItems: 'center', justifyContent: 'center', gap: 6, minHeight: 40, borderRadius: 10, borderWidth: 1, borderColor: colors.borderStrong, backgroundColor: colors.surface },
  call: { flex: 1, backgroundColor: CALL_BG, paddingHorizontal: space.lg, justifyContent: 'space-between' },
  caller: { alignItems: 'center', gap: 8 },
  context: { paddingHorizontal: 10, height: 26, borderRadius: 13, backgroundColor: 'rgba(255,255,255,0.14)', justifyContent: 'center', marginTop: 4 },
  answerRow: { flexDirection: 'row', justifyContent: 'space-between', paddingHorizontal: space.md },
  controls: { flexDirection: 'row', flexWrap: 'wrap', justifyContent: 'space-between', rowGap: space.lg },
  control: { width: '33%', alignItems: 'center', gap: 8 },
  controlBtn: { width: 70, height: 70, borderRadius: 35, backgroundColor: 'rgba(255,255,255,0.14)', alignItems: 'center', justifyContent: 'center' },
  hangup: { alignSelf: 'center', backgroundColor: DECLINE },
});
