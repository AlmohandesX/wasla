import Ionicons from '@expo/vector-icons/Ionicons';
import { useRouter } from 'expo-router';
import type { ComponentProps } from 'react';
import { Pressable, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { useSession } from '../../../src/auth/session';
import { Logo } from '../../../src/components/Logo';
import { t, useLocale } from '../../../src/i18n';
import { firstName } from '../../../src/lib/firstName';
import { colors, font, fontSize, radius, spacing } from '../../../src/theme';

type DashboardCardProps = {
  title: string;
  hint: string;
  icon: ComponentProps<typeof Ionicons>['name'];
  onPress: () => void;
};

/** A large tappable card: the title and a short hint on the left, the icon on the right. */
function DashboardCard({ title, hint, icon, onPress }: DashboardCardProps) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={title}
      accessibilityHint={hint}
      onPress={onPress}
      style={({ pressed }) => [styles.card, pressed && styles.cardPressed]}
    >
      <View style={styles.cardText}>
        <Text style={styles.cardTitle}>{title}</Text>
        <Text style={styles.cardHint}>{hint}</Text>
      </View>
      <View style={styles.cardIcon}>
        <Ionicons name={icon} size={36} color={colors.primary} />
      </View>
    </Pressable>
  );
}

/** Home: a greeting on the navy header band, then one card per area of the app. */
export default function Home() {
  useLocale();
  const router = useRouter();
  const { user } = useSession();
  const name = firstName(user?.name);

  return (
    <View style={styles.page}>
      <SafeAreaView edges={['top', 'left', 'right']} style={styles.header}>
        <Logo width={88} tone="white" />
        <Text accessibilityRole="header" style={styles.greeting}>
          {name ? t('home.title', { name }) : t('home.titleNoName')}
        </Text>
      </SafeAreaView>
      <ScrollView contentContainerStyle={styles.content}>
        <DashboardCard
          title={t('home.cars')}
          hint={t('home.carsHint')}
          icon="car-sport-outline"
          onPress={() => router.push('/cars')}
        />
        <DashboardCard
          title={t('home.services')}
          hint={t('home.servicesHint')}
          icon="construct-outline"
          onPress={() => router.push('/services')}
        />
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: colors.background },
  header: {
    backgroundColor: colors.primary,
    paddingHorizontal: spacing.lg,
    paddingBottom: spacing.lg,
    borderBottomLeftRadius: radius * 2,
    borderBottomRightRadius: radius * 2,
  },
  greeting: { marginTop: spacing.sm, fontSize: fontSize.title, fontFamily: font.bold, color: colors.surface },
  // A readable column on tablets and the web; the full width on a phone.
  content: { padding: spacing.lg, gap: spacing.md, width: '100%', maxWidth: 560, alignSelf: 'center' },
  card: {
    minHeight: 112,
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    padding: spacing.lg,
    borderRadius: radius * 1.5,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.divider,
  },
  cardPressed: { backgroundColor: colors.primaryTint },
  cardText: { flex: 1 },
  cardTitle: { fontSize: 22, fontFamily: font.extrabold, color: colors.text },
  cardHint: { marginTop: spacing.xs, fontSize: fontSize.small, fontFamily: font.regular, color: colors.textMuted },
  cardIcon: {
    width: 64,
    height: 64,
    borderRadius: 32,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.accentTint,
  },
});
