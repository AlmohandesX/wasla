import type { ReactNode } from 'react';
import { KeyboardAvoidingView, ScrollView, StyleSheet, Text, View } from 'react-native';
import { SafeAreaView, type Edge } from 'react-native-safe-area-context';

import { colors, font, fontSize, spacing } from '../theme';
import { Logo } from './Logo';

type Props = {
  title?: string;
  children: ReactNode;
  /** True inside the tab bar: the tab bar already keeps clear of the bottom of the phone. */
  tabbed?: boolean;
  /** Extra room at the end of the scroll so a floating button never covers the last item. */
  bottomSpace?: number;
  /** Show the Beni Bul logo above the title (auth screens and home). */
  logo?: boolean;
};

const ALL_EDGES: Edge[] = ['top', 'right', 'bottom', 'left'];
const TAB_EDGES: Edge[] = ['top', 'right', 'left'];

export function Screen({ title, children, tabbed = false, bottomSpace = 0, logo = false }: Props) {
  return (
    <SafeAreaView style={styles.safe} edges={tabbed ? TAB_EDGES : ALL_EDGES}>
      <KeyboardAvoidingView style={styles.flex} behavior="padding">
        <ScrollView
          contentContainerStyle={[styles.content, bottomSpace ? { paddingBottom: spacing.xl + bottomSpace } : null]}
          keyboardShouldPersistTaps="handled"
        >
          {logo ? (
            <View style={styles.logo}>
              <Logo width={tabbed ? 96 : 132} />
            </View>
          ) : null}
          {title ? (
            <Text accessibilityRole="header" style={styles.title}>
              {title}
            </Text>
          ) : null}
          {children}
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.background },
  logo: { marginBottom: spacing.md },
  flex: { flex: 1 },
  content: { padding: spacing.lg, paddingBottom: spacing.xl },
  title: { fontSize: fontSize.title, fontFamily: font.bold, color: colors.text, marginBottom: spacing.lg },
});
