import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { listCars, type Car } from '../../../src/api/cars';
import { listThreads, type Thread } from '../../../src/api/chats';
import { toApiError } from '../../../src/api/errors';
import { useSession } from '../../../src/auth/session';
import { Button } from '../../../src/components/Button';
import { ErrorBanner } from '../../../src/components/ErrorBanner';
import { t, useLocale } from '../../../src/i18n';
import { formatDateTime } from '../../../src/lib/format';
import { enablePush, getPushState, type PushState } from '../../../src/push/register';
import { colors, font, fontSize, radius, spacing, touchTarget } from '../../../src/theme';

function PushPrompt({ userId }: { userId: number }) {
  const [state, setState] = useState<PushState | null>(null);

  useFocusEffect(
    useCallback(() => {
      void getPushState().then(setState);
    }, []),
  );

  // Nothing to offer when push is on, or cannot work here (Expo Go, no project configured).
  if (state === null || state === 'granted' || state === 'unavailable') return null;
  return (
    <View style={styles.prompt}>
      <Text accessibilityRole="header" style={styles.promptTitle}>
        {t('chats.pushTitle')}
      </Text>
      <Text style={styles.promptBody}>{state === 'denied' ? t('chats.pushDenied') : t('chats.pushBody')}</Text>
      {state === 'undetermined' ? (
        <Button label={t('chats.pushAllow')} variant="secondary" onPress={() => void enablePush(true, userId).then(setState)} />
      ) : null}
    </View>
  );
}

export default function Chats() {
  useLocale();
  const router = useRouter();
  const { user } = useSession();
  const [threads, setThreads] = useState<Thread[] | null>(null);
  const [cars, setCars] = useState<Map<number, Car>>(new Map());
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);

  const load = useCallback(async () => {
    try {
      const [list, carList] = await Promise.all([listThreads(), listCars().catch(() => [] as Car[])]);
      setThreads(list);
      setCars(new Map(carList.map((c) => [c.id, c])));
      setError(null);
    } catch (e) {
      setError(toApiError(e).message);
    }
  }, []);

  // Reload whenever the list is shown again, so unread counts follow reading a thread.
  useFocusEffect(
    useCallback(() => {
      void load();
    }, [load]),
  );

  const refresh = async () => {
    setRefreshing(true);
    await load();
    setRefreshing(false);
  };

  const renderThread = ({ item }: { item: Thread }) => {
    const car = cars.get(item.car_id)?.model ?? t('chats.unknownCar');
    const last = item.last_message;
    const who = last ? (last.sender === 'owner' ? t('chats.you') : t('chats.visitor')) : '';
    const preview = last ? (last.hidden ? t('chat.removed') : last.body) : t('chats.noMessages');
    const unread = item.unread_count > 0 ? t('chats.unread', { count: item.unread_count }) : t('chats.unreadNone');
    return (
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={
          last
            ? t('chats.rowLabel', { car, who, body: preview, unread }) + (item.blocked ? ` ${t('chats.blocked')}.` : '')
            : `${car}. ${preview}. ${unread}`
        }
        onPress={() => router.push({ pathname: '/chats/[id]', params: { id: String(item.id), blocked: item.blocked ? '1' : '0' } })}
        style={[styles.row, item.unread_count > 0 && styles.rowUnread]}
      >
        <View style={styles.rowHead}>
          <Text style={[styles.car, item.unread_count > 0 && styles.carUnread]}>{car}</Text>
          {item.unread_count > 0 ? (
            <View style={styles.badge}>
              <Text style={styles.badgeText}>{item.unread_count}</Text>
            </View>
          ) : null}
        </View>
        <Text numberOfLines={2} style={styles.preview}>
          {who ? `${who}: ` : ''}
          {preview}
        </Text>
        <Text style={styles.meta}>
          {formatDateTime(item.updated_at)}
          {item.blocked ? `  ·  ${t('chats.blocked')}` : ''}
        </Text>
      </Pressable>
    );
  };

  return (
    <SafeAreaView style={styles.safe} edges={['top', 'left', 'right']}>
      {threads === null && !error ? (
        <View style={styles.center}>
          <ActivityIndicator size="large" color={colors.primary} accessibilityLabel={t('common.loading')} />
        </View>
      ) : (
        <FlatList
          data={threads ?? []}
          keyExtractor={(thread) => String(thread.id)}
          renderItem={renderThread}
          contentContainerStyle={styles.content}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={refresh} colors={[colors.primary]} />}
          ListHeaderComponent={
            <View>
              {error ? <ErrorBanner message={`${t('chats.loadError')} ${error}`} /> : null}
              {error && threads === null ? <Button label={t('common.retry')} onPress={load} /> : null}
              {user ? <PushPrompt userId={user.id} /> : null}
            </View>
          }
          ListEmptyComponent={threads ? <Text style={styles.empty}>{t('chats.empty')}</Text> : undefined}
        />
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.background },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center' },
  content: { padding: spacing.lg, flexGrow: 1 },
  empty: { fontFamily: font.regular, fontSize: fontSize.body, color: colors.text, marginVertical: spacing.lg },
  row: {
    minHeight: touchTarget,
    borderWidth: 1,
    borderColor: colors.divider,
    borderRadius: radius,
    padding: spacing.md,
    marginBottom: spacing.sm,
    backgroundColor: colors.surface,
  },
  rowUnread: { borderColor: colors.primary, borderWidth: 2, backgroundColor: colors.primaryTint },
  rowHead: { flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' },
  car: { fontSize: fontSize.body, fontFamily: font.semibold, color: colors.text },
  carUnread: { fontFamily: font.extrabold },
  badge: { minWidth: 24, paddingHorizontal: 8, borderRadius: 12, backgroundColor: colors.accent, alignItems: 'center' },
  badgeText: { color: colors.primary, fontSize: fontSize.small, fontFamily: font.bold },
  preview: { fontFamily: font.regular, fontSize: fontSize.body, color: colors.text, marginTop: spacing.xs },
  meta: { fontFamily: font.regular, fontSize: fontSize.small, color: colors.textMuted, marginTop: spacing.xs },
  prompt: { borderWidth: 1, borderColor: colors.primary, borderRadius: radius, padding: spacing.md, marginBottom: spacing.md, backgroundColor: colors.primaryTint },
  promptTitle: { fontSize: fontSize.body, fontFamily: font.bold, color: colors.text, marginBottom: spacing.xs },
  promptBody: { fontFamily: font.regular, fontSize: fontSize.body, color: colors.text, marginBottom: spacing.sm },
});
