import { Pressable, StyleSheet, Text, type PressableProps } from 'react-native';

import { colors, font, fontSize, touchTarget } from '../theme';

type Props = Omit<PressableProps, 'children'> & { label: string };

/** A text link that meets the touch target size. Works as the child of expo-router's Link asChild (ref arrives in props). */
export function LinkText({ label, ...rest }: Props) {
  return (
    <Pressable accessibilityRole="link" {...rest} style={styles.link}>
      <Text style={styles.text}>{label}</Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  link: { minHeight: touchTarget, justifyContent: 'center' },
  text: { color: colors.primaryDark, fontFamily: font.regular, fontSize: fontSize.body, textDecorationLine: 'underline' },
});
