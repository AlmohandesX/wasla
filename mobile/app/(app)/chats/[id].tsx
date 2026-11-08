import { useLocalSearchParams, useRouter } from 'expo-router';
import { useRef, useState } from 'react';
import { useHeaderHeight } from 'expo-router/react-navigation';
import { ActivityIndicator, Alert, FlatList, KeyboardAvoidingView, Platform, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { Button } from '../../../src/components/Button';
import { ErrorBanner } from '../../../src/components/ErrorBanner';
import { useThread, type Pending } from '../../../src/chat/useThread';
import { t, useLocale } from '../../../src/i18n';
import { formatDateTime } from '../../../src/lib/format';
import type { Message } from '../../../src/lib/mergeMessages';
import { colors, fontSize, radius, spacing, touchTarget } from '../../../src/theme';

const MAX_LENGTH = 500;

type Row = { kind: 'message'; key: string; message: Message } | { kind: 'pending'; key: string; pending: Pending };

function Bubble({ own, label, body, status }: { own: boolean; label: string; body: string; status?: 'sending' | 'failed' }) {
  return (
    <View
      accessible
      accessibilityLabel={label}
      style={[styles.bubble, own ? styles.bubbleOwn : styles.bubbleVisitor, status === 'failed' && styles.bubbleFailed]}
    >
      <Text style={styles.who}>{own ? t('chats.you') : t('chats.visitor')}</Text>
      <Text style={styles.body}>{body}</Text>
    </View>
  );
}

export default function ChatThread() {
  useLocale();
  const router = useRouter();
  const headerHeight = useHeaderHeight();
  const { id, blocked: blockedParam } = useLocalSearchParams<{ id: string; blocked?: string }>();
  const threadId = Number(id);
  // The list passes what it already knows so the blocked state shows at once.
  const chat = useThread(threadId, blockedParam === '1');
  const [text, setText] = useState('');
  const list = useRef<FlatList<Row>>(null);

  const { meta, fatal } = chat;
  const car = meta?.car ?? null;
  const trimmed = text.trim();
  const busy = chat.pending.some((p) => p.status === 'sending');

  // Why the composer is off, if it is.
  let notice: string | null = null;
  if (fatal) notice = fatal.status === 402 ? t('chat.expiredNotice') : fatal.status === 404 ? t('chat.loadFailed') : t('errors.forbidden');
  else if (chat.blocked) notice = t('chat.blockedNotice');
  else if (car && !car.linked) notice = t('chat.unlinkedNotice');
  else if (car && !car.subscription_active) notice = t('chat.expiredNotice');
  else if (!meta && chat.metaFailed) notice = t('chat.metaFailed');
  const canReply = !!meta && notice === null;

  const submit = async (body: string) => {
    if (!body || busy) return;
    const ok = await chat.send(body);
    if (ok) setText((current) => (current.trim() === body ? '' : current));
  };

  const confirmBlock = () =>
    Alert.alert(t('chat.blockTitle'), t('chat.blockBody'), [
      { text: t('chat.cancel'), style: 'cancel' },
      { text: t('chat.blockConfirm'), style: 'destructive', onPress: () => void chat.block() },
    ]);

  const confirmReport = (message: Message) =>
    Alert.alert(t('chat.reportTitle'), t('chat.reportBody'), [
      { text: t('chat.cancel'), style: 'cancel' },
      { text: t('chat.reportConfirm'), style: 'destructive', onPress: () => void chat.report(message.id) },
    ]);

  const rows: Row[] = [
    ...chat.messages.map((message): Row => ({ kind: 'message', key: `m${message.id}`, message })),
    ...chat.pending.map((pending): Row => ({ kind: 'pending', key: `p${pending.key}`, pending })),
  ];

  const renderRow = ({ item }: { item: Row }) => {
    if (item.kind === 'pending') {
      const { pending } = item;
      const failed = pending.status === 'failed';
      return (
        <View style={styles.rowOwn}>
          <Bubble
            own
            status={pending.status}
            body={pending.body}
            label={t('chat.messageLabel', {
              who: t('chats.you'),
              time: failed ? t('chat.failedSuffix') : t('chat.sendingSuffix'),
              body: pending.body,
            })}
          />
          {failed ? (
            <View style={styles.pendingActions}>
              <Text accessibilityRole="alert" style={styles.failedText}>
                {pending.error ?? t('chat.failed')}
              </Text>
              <View style={styles.inline}>
                <Button label={t('chat.retry')} variant="secondary" disabled={pending.checking} onPress={() => void submit(pending.body)} />
                <Button label={t('chat.discard')} variant="link" onPress={() => chat.discard(pending.key)} />
              </View>
            </View>
          ) : (
            <Text style={styles.meta}>{t('chat.sending')}</Text>
          )}
        </View>
      );
    }

    const { message } = item;
    const own = message.sender === 'owner';
    const time = formatDateTime(message.created_at);
    return (
      <View style={own ? styles.rowOwn : styles.rowVisitor}>
        <Bubble
          own={own}
          body={message.body}
          label={`${t('chat.messageLabel', { who: own ? t('chats.you') : t('chats.visitor'), time, body: message.body })}${message.reported ? ` ${t('chat.reportedSuffix')}` : ''}`}
        />
        <View style={styles.inline}>
          <Text style={styles.meta}>{time}</Text>
          {!own ? (
            message.reported ? (
              <Text style={styles.meta}>{t('chat.reported')}</Text>
            ) : (
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={t('chat.reportLabel', { body: message.body })}
                onPress={() => confirmReport(message)}
                style={styles.reportButton}
              >
                <Text style={styles.reportText}>{t('chat.report')}</Text>
              </Pressable>
            )
          ) : null}
        </View>
      </View>
    );
  };

  return (
    <SafeAreaView style={styles.safe} edges={['bottom', 'left', 'right']}>
      {/* The opaque header sits above this view, so the keyboard offset must include its height or the
          composer ends up partly behind the keyboard on iOS. */}
      <KeyboardAvoidingView
        style={styles.flex}
        behavior="padding"
        keyboardVerticalOffset={Platform.OS === 'ios' ? headerHeight : 0}
      >
        <View style={styles.top}>
          <ErrorBanner message={chat.actionError} />
          {chat.pollFailing && !fatal ? <ErrorBanner tone="info" message={t('chat.pollError')} /> : null}
          {car && !fatal ? (
            <View style={styles.pausedBox}>
              {car.chats_paused ? <Text style={styles.noticeText}>{t('chat.pausedNotice')}</Text> : null}
              <Button
                label={t('chat.pauseSettings')}
                variant="secondary"
                onPress={() => router.push({ pathname: '/cars/[id]/settings', params: { id: String(car.id) } })}
              />
            </View>
          ) : null}
          <Text style={styles.retention}>{t('chat.retention')}</Text>
          {meta && !chat.blocked && !fatal ? <Button label={t('chat.block')} variant="secondary" onPress={confirmBlock} /> : null}
        </View>

        {!chat.loaded ? (
          <View style={styles.center}>
            <ActivityIndicator size="large" color={colors.primary} accessibilityLabel={t('common.loading')} />
          </View>
        ) : (
          <FlatList
            ref={list}
            data={rows}
            keyExtractor={(row) => row.key}
            renderItem={renderRow}
            accessibilityLabel={t('chat.list')}
            contentContainerStyle={styles.listContent}
            onContentSizeChange={() => list.current?.scrollToEnd({ animated: false })}
            ListEmptyComponent={<Text style={styles.empty}>{t('chat.empty')}</Text>}
          />
        )}

        {notice ? (
          <View style={styles.noticeBox}>
            <Text accessibilityRole="alert" style={styles.noticeText}>
              {notice}
            </Text>
            {car && car.linked && !car.subscription_active ? (
              <Button
                label={t('expired.renew')}
                onPress={() => router.push({ pathname: '/cars/[id]/expired', params: { id: String(car.id) } })}
              />
            ) : null}
            {fatal ? <Button label={t('common.retry')} variant="secondary" onPress={chat.clearFatal} /> : null}
            {!meta && chat.metaFailed && !fatal ? <Button label={t('common.retry')} variant="secondary" onPress={chat.retryMeta} /> : null}
          </View>
        ) : (
          <View style={styles.composer}>
            {chat.sendError ? (
              <Text accessibilityRole="alert" style={styles.sendError}>
                {chat.sendError}
              </Text>
            ) : null}
            <TextInput
              accessibilityLabel={t('chat.input')}
              accessibilityHint={t('chat.inputHint')}
              value={text}
              onChangeText={(value) => {
                setText(value);
                if (chat.sendError) chat.setSendError(null);
              }}
              maxLength={MAX_LENGTH}
              multiline
              editable={canReply}
              placeholder={t('chat.input')}
              placeholderTextColor={colors.textMuted}
              style={styles.input}
            />
            <Button label={t('chat.send')} loading={busy} disabled={!canReply || trimmed === ''} onPress={() => void submit(trimmed)} />
          </View>
        )}
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.surface },
  flex: { flex: 1 },
  pad: { padding: spacing.lg },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  top: { paddingHorizontal: spacing.md, paddingTop: spacing.sm },
  retention: { fontSize: fontSize.small, color: colors.textMuted, marginBottom: spacing.sm },
  listContent: { padding: spacing.md, flexGrow: 1 },
  empty: { fontSize: fontSize.body, color: colors.textMuted, textAlign: 'center', marginTop: spacing.xl },
  rowOwn: { alignItems: 'flex-end', marginBottom: spacing.md },
  rowVisitor: { alignItems: 'flex-start', marginBottom: spacing.md },
  bubble: { maxWidth: '85%', borderRadius: radius, padding: spacing.sm + 2, borderWidth: 1 },
  bubbleOwn: { backgroundColor: colors.primaryTint, borderColor: colors.primary },
  bubbleVisitor: { backgroundColor: colors.surface, borderColor: colors.border },
  bubbleFailed: { borderColor: colors.danger, borderStyle: 'dashed' },
  who: { fontSize: fontSize.small, fontWeight: '700', color: colors.primaryDark, marginBottom: 2 },
  body: { fontSize: fontSize.body, color: colors.text },
  inline: { flexDirection: 'row', alignItems: 'center', gap: spacing.sm, marginTop: spacing.xs },
  meta: { fontSize: fontSize.small, color: colors.textMuted },
  pendingActions: { alignItems: 'flex-end' },
  failedText: { fontSize: fontSize.small, color: colors.danger, marginTop: spacing.xs },
  reportButton: { minHeight: touchTarget, minWidth: touchTarget, justifyContent: 'center', paddingHorizontal: spacing.sm },
  reportText: { fontSize: fontSize.small, fontWeight: '600', color: colors.danger },
  pausedBox: { backgroundColor: colors.primaryTint, borderRadius: radius, padding: spacing.md, marginBottom: spacing.sm },
  noticeBox: { padding: spacing.md, backgroundColor: colors.primaryTint, borderTopWidth: 1, borderColor: colors.border },
  noticeText: { fontSize: fontSize.body, color: colors.text, marginBottom: spacing.sm },
  composer: { padding: spacing.md, borderTopWidth: 1, borderColor: colors.border, gap: spacing.sm },
  input: {
    minHeight: touchTarget,
    maxHeight: 120,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius,
    paddingHorizontal: 12,
    paddingVertical: 10,
    fontSize: fontSize.body,
    color: colors.text,
  },
  sendError: { fontSize: fontSize.small, color: colors.danger },
});
