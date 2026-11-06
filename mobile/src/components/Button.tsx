import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text } from 'react-native';

import { colors, fontSize, radius, touchTarget } from '../theme';

type Props = {
  label: string;
  onPress: () => void;
  variant?: 'primary' | 'secondary' | 'link' | 'danger';
  loading?: boolean;
  disabled?: boolean;
  accessibilityLabel?: string;
};

export function Button({ label, onPress, variant = 'primary', loading, disabled, accessibilityLabel }: Props) {
  const [focused, setFocused] = useState(false);
  const inactive = disabled || loading;
  const filled = variant === 'primary';

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? label}
      accessibilityState={{ disabled: !!inactive, busy: !!loading }}
      disabled={inactive}
      onPress={onPress}
      onFocus={() => setFocused(true)}
      onBlur={() => setFocused(false)}
      style={({ pressed }) => [
        styles.base,
        variant === 'primary' && styles.primary,
        variant === 'secondary' && styles.secondary,
        variant === 'danger' && styles.danger,
        pressed && (filled ? styles.primaryPressed : variant === 'danger' ? styles.dangerPressed : styles.tintPressed),
        focused && styles.focused,
        inactive && styles.inactive,
      ]}
    >
      {loading ? (
        <ActivityIndicator color={filled ? colors.surface : colors.primary} />
      ) : (
        <Text style={[styles.label, filled ? styles.labelFilled : variant === 'danger' ? styles.labelDanger : styles.labelPlain]}>{label}</Text>
      )}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  base: {
    minHeight: touchTarget,
    minWidth: touchTarget,
    paddingHorizontal: 16,
    borderRadius: radius,
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 2,
    borderColor: 'transparent',
  },
  primary: { backgroundColor: colors.primary },
  dangerPressed: { backgroundColor: colors.dangerTint },
  danger: { backgroundColor: colors.surface, borderColor: colors.danger },
  secondary: { backgroundColor: colors.surface, borderColor: colors.primary },
  primaryPressed: { backgroundColor: colors.primaryDark },
  tintPressed: { backgroundColor: colors.primaryTint },
  focused: { borderColor: colors.text },
  inactive: { opacity: 0.55 },
  label: { fontSize: fontSize.body, fontWeight: '600', textAlign: 'center' },
  labelFilled: { color: colors.surface },
  labelPlain: { color: colors.primaryDark },
  labelDanger: { color: colors.danger },
});
