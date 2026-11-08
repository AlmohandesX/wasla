import type { ReactNode } from 'react';
import { KeyboardAvoidingView, ScrollView, StyleSheet, Text } from 'react-native';
import { SafeAreaView, type Edge } from 'react-native-safe-area-context';

import { colors, fontSize, spacing } from '../theme';

type Props = {
  title?: string;
  children: ReactNode;
  /** True inside the tab bar: the tab bar already keeps clear of the bottom of the phone. */
  tabbed?: boolean;
  /** Extra room at the end of the scroll so a floating button never covers the last item. */
  bottomSpace?: number;
};

const ALL_EDGES: Edge[] = ['top', 'right', 'bottom', 'left'];
const TAB_EDGES: Edge[] = ['top', 'right', 'left'];

export function Screen({ title, children, tabbed = false, bottomSpace = 0 }: Props) {
  return (
    <SafeAreaView style={styles.safe} edges={tabbed ? TAB_EDGES : ALL_EDGES}>
      <KeyboardAvoidingView style={styles.flex} behavior="padding">
        <ScrollView
          contentContainerStyle={[styles.content, bottomSpace ? { paddingBottom: spacing.xl + bottomSpace } : null]}
          keyboardShouldPersistTaps="handled"
        >
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
  safe: { flex: 1, backgroundColor: colors.surface },
  flex: { flex: 1 },
  content: { padding: spacing.lg, paddingBottom: spacing.xl },
  title: { fontSize: fontSize.title, fontWeight: '700', color: colors.text, marginBottom: spacing.lg },
});
