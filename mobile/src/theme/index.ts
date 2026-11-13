// Beni Bul brand (brand/README.md): navy + signal orange, Manrope.
// Navy carries text, buttons and controls (15.6:1 on white). Signal orange is too light for white text or for
// text and icons on white (2.8:1), so it is only ever a fill behind navy content (5.6:1), like the logo's connector.
export const colors = {
  primary: '#14213D',
  primaryDark: '#0B1428',
  primaryTint: '#E8EBF2',
  accent: '#FF6B2C',
  accentPressed: '#F05A1A',
  accentTint: '#FFF0E8',
  surface: '#FFFFFF',
  background: '#F4F5F7',
  text: '#14213D',
  textMuted: '#4B5563',
  border: '#7C879A',
  /** Hairline around cards and between rows; not for input borders, which need 3:1. */
  divider: '#E3E6EC',
  danger: '#B3261E',
  dangerTint: '#FDECEA',
} as const;

/** Manrope faces, loaded in app/_layout.tsx. Use these instead of fontWeight: Android only picks a face by name. */
export const font = {
  regular: 'Manrope_400Regular',
  medium: 'Manrope_500Medium',
  semibold: 'Manrope_600SemiBold',
  bold: 'Manrope_700Bold',
  extrabold: 'Manrope_800ExtraBold',
} as const;

export const spacing = { xs: 4, sm: 8, md: 16, lg: 24, xl: 32 } as const;

export const radius = 12;

/** Minimum touch target in dp. */
export const touchTarget = 48;

export const fontSize = { small: 14, body: 16, title: 26 } as const;

/**
 * The app's header: navy with white title, back arrow and icons (15.6:1), shared by the stack screens and the
 * Chats and Account tabs. headerTransparent false keeps it opaque on iOS 26+, where the native header is
 * otherwise a see-through "Liquid Glass" bar that screens draw underneath.
 */
export const navyHeader = {
  headerTransparent: false,
  headerStyle: { backgroundColor: colors.primary },
  headerShadowVisible: false,
  headerTintColor: colors.surface,
  headerTitleStyle: { color: colors.surface, fontFamily: font.bold },
} as const;
