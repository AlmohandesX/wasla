import { StyleSheet, Text, View } from 'react-native';

import { colors, fontSize, radius, spacing } from '../theme';

type Props = { message?: string | null; tone?: 'error' | 'info' };

export function ErrorBanner({ message, tone = 'error' }: Props) {
  if (!message) return null;
  return (
    <View
      accessibilityRole={tone === 'error' ? 'alert' : undefined}
      accessibilityLiveRegion="polite"
      style={[styles.box, tone === 'error' ? styles.error : styles.info]}
    >
      <Text style={[styles.text, tone === 'error' ? styles.errorText : styles.infoText]}>{message}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  box: { borderRadius: radius, borderWidth: 1, padding: spacing.md, marginBottom: spacing.md },
  error: { backgroundColor: colors.dangerTint, borderColor: colors.danger },
  info: { backgroundColor: colors.primaryTint, borderColor: colors.primary },
  text: { fontSize: fontSize.body },
  errorText: { color: colors.danger },
  infoText: { color: colors.text },
});
