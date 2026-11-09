import { useState } from 'react';
import { Pressable, StyleSheet, Text, TextInput, View, type TextInputProps } from 'react-native';

import { t, useLocale } from '../i18n';
import { colors, font, fontSize, radius, spacing, touchTarget } from '../theme';

type Props = Omit<TextInputProps, 'style'> & {
  label: string;
  error?: string;
  hint?: string;
};

export function TextField({ label, error, hint, secureTextEntry, ...input }: Props) {
  useLocale();
  const [focused, setFocused] = useState(false);
  const [revealed, setRevealed] = useState(false);
  const isPassword = !!secureTextEntry;

  return (
    <View style={styles.wrap}>
      <Text style={styles.label}>{label}</Text>
      <View style={[styles.box, focused && styles.boxFocused, !!error && styles.boxError]}>
        <TextInput
          {...input}
          accessibilityLabel={label}
          accessibilityHint={error ?? hint}
          secureTextEntry={isPassword && !revealed}
          onFocus={(e) => {
            setFocused(true);
            input.onFocus?.(e);
          }}
          onBlur={(e) => {
            setFocused(false);
            input.onBlur?.(e);
          }}
          placeholderTextColor={colors.textMuted}
          style={styles.input}
        />
        {isPassword ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={revealed ? t('common.hide') : t('common.show')}
            onPress={() => setRevealed((r) => !r)}
            style={styles.reveal}
          >
            <Text style={styles.revealText}>{revealed ? t('common.hide') : t('common.show')}</Text>
          </Pressable>
        ) : null}
      </View>
      {error ? (
        <Text accessibilityRole="alert" style={styles.error}>
          {error}
        </Text>
      ) : hint ? (
        <Text style={styles.hint}>{hint}</Text>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { marginBottom: spacing.md },
  label: { fontSize: fontSize.small, fontFamily: font.semibold, color: colors.text, marginBottom: spacing.xs },
  box: {
    flexDirection: 'row',
    alignItems: 'center',
    minHeight: touchTarget,
    borderWidth: 1,
    borderColor: colors.border,
    borderRadius: radius,
    backgroundColor: colors.surface,
  },
  boxFocused: { borderColor: colors.primary, borderWidth: 2 },
  boxError: { borderColor: colors.danger, borderWidth: 2 },
  input: { flex: 1, minHeight: touchTarget, paddingHorizontal: 12, fontFamily: font.regular, fontSize: fontSize.body, color: colors.text },
  reveal: { minHeight: touchTarget, minWidth: touchTarget, paddingHorizontal: 12, justifyContent: 'center' },
  revealText: { color: colors.primaryDark, fontSize: fontSize.small, fontFamily: font.semibold },
  error: { color: colors.danger, fontFamily: font.regular, fontSize: fontSize.small, marginTop: spacing.xs },
  hint: { color: colors.textMuted, fontFamily: font.regular, fontSize: fontSize.small, marginTop: spacing.xs },
});
