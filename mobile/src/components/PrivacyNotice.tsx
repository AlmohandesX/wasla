import { StyleSheet, Text, View } from 'react-native';

import { t } from '../i18n';
import { colors, font, fontSize, radius, spacing } from '../theme';

/** The privacy notice a new owner accepts, whether they register with email or with Google. */
export function PrivacyNotice() {
  return (
    <View style={styles.notice}>
      <Text accessibilityRole="header" style={styles.title}>
        {t('legal.privacyTitle')}
      </Text>
      <Text style={styles.draft}>{t('legal.draftBadge')}</Text>
      <Text style={styles.body}>{t('legal.privacyBody')}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  notice: {
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius,
    padding: spacing.md,
    marginBottom: spacing.md,
  },
  title: { fontSize: fontSize.body, fontFamily: font.bold, color: colors.text },
  draft: { fontSize: fontSize.small, fontFamily: font.bold, color: colors.danger, marginVertical: spacing.xs },
  body: { fontFamily: font.regular, fontSize: fontSize.small, color: colors.text, lineHeight: 20 },
});
