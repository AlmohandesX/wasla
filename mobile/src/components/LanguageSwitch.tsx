import { Pressable, StyleSheet, Text, View } from 'react-native';

import { LOCALES, setLocale, t, useLocale } from '../i18n';
import { colors, fontSize, radius, spacing, touchTarget } from '../theme';

export function LanguageSwitch() {
  const current = useLocale();
  return (
    <View accessibilityRole="radiogroup" accessibilityLabel={t('language.label')} style={styles.row}>
      {LOCALES.map((locale) => {
        const selected = locale === current;
        return (
          <Pressable
            key={locale}
            accessibilityRole="radio"
            accessibilityState={{ selected }}
            onPress={() => setLocale(locale)}
            style={[styles.option, selected && styles.selected]}
          >
            <Text style={[styles.text, selected && styles.selectedText]}>{t(`language.${locale}`)}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: 'row', gap: spacing.sm, marginBottom: spacing.lg },
  option: {
    minHeight: touchTarget,
    minWidth: touchTarget,
    paddingHorizontal: 16,
    justifyContent: 'center',
    borderRadius: radius,
    borderWidth: 1,
    borderColor: colors.border,
  },
  selected: { backgroundColor: colors.primary, borderColor: colors.primary },
  text: { fontSize: fontSize.body, color: colors.text },
  selectedText: { color: colors.surface, fontWeight: '600' },
});
