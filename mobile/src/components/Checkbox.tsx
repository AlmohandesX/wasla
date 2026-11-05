import { useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';

import { colors, fontSize, radius, spacing, touchTarget } from '../theme';

type Props = { label: string; checked: boolean; onChange: (checked: boolean) => void; error?: string };

export function Checkbox({ label, checked, onChange, error }: Props) {
  const [focused, setFocused] = useState(false);
  return (
    <View style={styles.wrap}>
      <Pressable
        accessibilityRole="checkbox"
        accessibilityState={{ checked }}
        accessibilityLabel={label}
        onPress={() => onChange(!checked)}
        onFocus={() => setFocused(true)}
        onBlur={() => setFocused(false)}
        style={[styles.row, focused && styles.rowFocused]}
      >
        <View style={[styles.box, checked && styles.boxChecked, !!error && !checked && styles.boxError]}>
          {checked ? <View style={styles.tick} /> : null}
        </View>
        <Text style={styles.label}>{label}</Text>
      </Pressable>
      {error ? (
        <Text accessibilityRole="alert" style={styles.error}>
          {error}
        </Text>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { marginBottom: spacing.md },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    minHeight: touchTarget,
    borderWidth: 2,
    borderColor: 'transparent',
    borderRadius: radius,
  },
  rowFocused: { borderColor: colors.primary },
  box: {
    width: 28,
    height: 28,
    borderWidth: 2,
    borderColor: colors.border,
    borderRadius: 6,
    marginRight: spacing.sm,
    alignItems: 'center',
    justifyContent: 'center',
  },
  boxChecked: { backgroundColor: colors.primary, borderColor: colors.primary },
  boxError: { borderColor: colors.danger },
  // A check mark drawn from two borders, so no icon font or emoji is needed.
  tick: {
    width: 8,
    height: 14,
    borderRightWidth: 3,
    borderBottomWidth: 3,
    borderColor: colors.surface,
    transform: [{ rotate: '45deg' }, { translateY: -1 }],
  },
  label: { flex: 1, fontSize: fontSize.body, color: colors.text },
  error: { color: colors.danger, fontSize: fontSize.small, marginTop: spacing.xs },
});
