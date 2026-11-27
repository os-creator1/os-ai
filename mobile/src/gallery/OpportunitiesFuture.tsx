import React, { useState } from 'react';
import { Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { Avatar, Button, Chip, Icon, IconButton, ListGroup, ListRow, SectionHeader, Text } from '../components';
import * as mock from '../mocks/design';
import { useTopInset } from '../screens/ui';
import { colors, space } from '../theme';
import { FieldBox, FutureTabBar, NavBar, gs, type PreviewProps } from './parts';

/** Pipeline board: stage chips with counts, opportunity cards for the selected stage. */
export function PipelinePreview(_: PreviewProps) {
  const top = useTopInset();
  const [stage, setStage] = useState('Quote sent');
  return (
    <View style={gs.screen}>
      <View style={[s.top, { paddingTop: top + space.sm }]}>
        <View style={gs.row}>
          <View style={[gs.row, gs.flex, { gap: 6 }]}><Text size={15} weight="600" color={colors.textTertiary}>{mock.opportunity.pipeline}</Text><Icon name="chevron-down" size={16} color={colors.textTertiary} /></View>
          <IconButton icon="search" filled accessibilityLabel="Search" onPress={() => undefined} />
          <IconButton icon="plus" filled accessibilityLabel="New opportunity" onPress={() => undefined} />
        </View>
        <Text variant="largeTitle" accessibilityRole="header">Pipeline</Text>
      </View>
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ flexGrow: 0 }} contentContainerStyle={s.stages}>
        {mock.stages.map((st) => (
          <Pressable key={st.name} accessibilityRole="button" accessibilityState={{ selected: stage === st.name }} onPress={() => setStage(st.name)}
            style={[s.stage, stage === st.name && s.stageOn]}>
            <Text size={14} weight="600" color={stage === st.name ? colors.onBrand : colors.textPrimary}>{st.name}</Text>
            <Text size={14} weight="600" color={stage === st.name ? '#D9D2CB' : colors.textSecondary}>{String(st.count)}</Text>
          </Pressable>
        ))}
      </ScrollView>
      <View style={[gs.row, s.summary]}>
        <Text size={13} color={colors.textSecondary} style={gs.flex}>{mock.opportunities.length + 3} opportunities</Text>
        <Text size={14} weight="700">$9,840</Text>
      </View>
      <ScrollView contentContainerStyle={s.cards}>
        {mock.opportunities.map((o) => (
          <View key={o.sub} style={gs.card}>
            <View style={gs.row}>
              <Avatar name={o.name} size={36} />
              <View style={gs.flex}>
                <Text size={16} weight="700">{o.name ?? o.phone}</Text>
                <Text size={13} color={colors.textSecondary}>{o.sub}</Text>
              </View>
              <Text size={17} weight="800">{o.value}</Text>
            </View>
            <View style={gs.row}>
              <View style={[s.age, o.stale && s.ageStale]}>
                <Icon name="clock" size={11} color={o.stale ? colors.warning : colors.textInk} />
                <Text size={12} weight="700" color={o.stale ? colors.warning : colors.textInk}>{o.age}</Text>
              </View>
              <View style={gs.flex} />
              <Icon name="message-circle" size={13} color={colors.textSecondary} />
              <Text size={12.5} color={colors.textSecondary}>{String(o.notes)}</Text>
            </View>
          </View>
        ))}
      </ScrollView>
      <FutureTabBar active="Pipeline" />
    </View>
  );
}

/** Opportunity detail: value, stage progress + Move, fields, linked conversation, Mark lost / won. */
export function OpportunityDetailPreview({ onBack }: PreviewProps) {
  const o = mock.opportunity;
  return (
    <View style={gs.screen}>
      <NavBar onLeft={onBack} right={{ icon: 'more-horizontal', label: 'More' }} />
      <ScrollView contentContainerStyle={gs.pad}>
        <View style={{ gap: 2 }}>
          <Text size={13} weight="600" color={colors.textSecondary}>{o.pipeline}</Text>
          <Text variant="title" accessibilityRole="header">{o.title}</Text>
          <Text size={22} weight="800" color={colors.brandPressed}>{o.value}</Text>
        </View>
        <View style={{ gap: space.sm }}>
          <SectionHeader title="Stage" />
          <View style={s.progress} accessibilityLabel={`Stage ${o.stageIndex + 1} of ${mock.stages.length}`}>
            {mock.stages.map((st, i) => <View key={st.name} style={[s.seg, i <= o.stageIndex && s.segOn]} />)}
          </View>
          <ListGroup>
            <ListRow title={o.stage} strong trailing={(
              <View style={[gs.row, { gap: 4 }]}><Text size={15} weight="700" color={colors.brandPressed}>Move</Text><Icon name="chevron-down" size={16} color={colors.brandPressed} /></View>
            )} onPress={() => undefined} />
          </ListGroup>
        </View>
        <ListGroup>
          <ListRow icon={{ name: 'dollar-sign', bg: colors.successSoft, fg: colors.success }} title="Value" value={o.value} chevron onPress={() => undefined} />
          <ListRow icon={{ name: 'calendar', bg: colors.infoSoft, fg: colors.info }} title="Event date" value={o.eventDate} chevron onPress={() => undefined} />
          <ListRow icon={{ name: 'user', bg: colors.violetSoft, fg: colors.violet }} title="Owner" value={o.owner} chevron onPress={() => undefined} />
        </ListGroup>
        <View style={{ gap: space.sm }}>
          <SectionHeader title="Conversation" />
          <ListGroup>
            <ListRow leading={<Avatar name={mock.thread.name} size={36} />} title={mock.thread.name} subtitle={o.lastMessage} strong chevron onPress={() => undefined} />
          </ListGroup>
        </View>
        <View style={gs.row}>
          <Button title="Mark lost" variant="secondary" style={gs.flex} onPress={() => undefined} />
          <Button title="Mark won" style={gs.flex} onPress={() => undefined} />
        </View>
      </ScrollView>
    </View>
  );
}

/** New opportunity form. */
export function NewOpportunityPreview({ onBack }: PreviewProps) {
  const [stage, setStage] = useState('New lead');
  return (
    <View style={gs.screen}>
      <NavBar title="New opportunity" left="close" onLeft={onBack} right={{ label: 'Save', onPress: onBack }} />
      <ScrollView contentContainerStyle={[gs.pad, { gap: 14 }]}>
        <FieldBox label="Contact" icon="search" placeholder="Search or add a contact" />
        <FieldBox label="Name" placeholder="e.g. Martinez wedding" />
        <View style={gs.row}>
          <FieldBox label="Value" icon="dollar-sign" placeholder="0.00" style={gs.flex} />
          <FieldBox label="Event date" icon="calendar" placeholder="Pick a date" style={gs.flex} />
        </View>
        <FieldBox label="Pipeline" value={mock.opportunity.pipeline} />
        <View style={{ gap: 6 }}>
          <Text size={13.5} weight="600" color={colors.textInk}>Stage</Text>
          <View style={gs.wrap}>{mock.stages.slice(0, 4).map((st) => <Chip key={st.name} label={st.name} selected={stage === st.name} onPress={() => setStage(st.name)} />)}</View>
        </View>
        <FieldBox label="Owner" icon="user" value={mock.me.name} />
      </ScrollView>
    </View>
  );
}

const s = StyleSheet.create({
  top: { paddingHorizontal: space.md, gap: space.xs, paddingBottom: space.sm },
  stages: { gap: space.sm, paddingHorizontal: space.md, paddingBottom: space.mid },
  stage: { flexDirection: 'row', alignItems: 'center', gap: 6, minHeight: 36, paddingHorizontal: 14, borderRadius: 18, borderWidth: 1, borderColor: colors.borderStrong, backgroundColor: colors.surface },
  stageOn: { backgroundColor: colors.textPrimary, borderColor: colors.textPrimary },
  summary: { paddingHorizontal: space.md, paddingBottom: space.sm },
  cards: { paddingHorizontal: space.md, paddingBottom: space.md, gap: space.mid },
  age: { flexDirection: 'row', alignItems: 'center', gap: 4, height: 24, paddingHorizontal: 8, borderRadius: 12, backgroundColor: colors.neutralSoft },
  ageStale: { backgroundColor: colors.warningSoft },
  progress: { flexDirection: 'row', gap: 4 },
  seg: { flex: 1, height: 6, borderRadius: 3, backgroundColor: colors.dayChip },
  segOn: { backgroundColor: colors.brand },
});

