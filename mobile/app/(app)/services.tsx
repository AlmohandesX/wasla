import Ionicons from '@expo/vector-icons/Ionicons';
import { StyleSheet, Text, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { t, useLocale } from '../../src/i18n';
import { colors, font, fontSize, spacing } from '../../src/theme';

/** Placeholder for the services area, opened from the Services card on Home. */
export default function Services() {
  useLocale();
  return (
    <SafeAreaView edges={['bottom', 'left', 'right']} style={styles.page}>
      <View style={styles.center}>
        <Ionicons name="construct-outline" size={56} color={colors.textMuted} />
        <Text accessibilityRole="header" style={styles.title}>
          {t('services.comingSoon')}
        </Text>
        <Text style={styles.body}>{t('services.body')}</Text>
      </View>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  page: { flex: 1, backgroundColor: colors.background },
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: spacing.lg, gap: spacing.sm },
  title: { fontSize: fontSize.title, fontFamily: font.bold, color: colors.text, textAlign: 'center' },
  body: { maxWidth: 320, fontSize: fontSize.body, fontFamily: font.regular, color: colors.textMuted, textAlign: 'center' },
});
