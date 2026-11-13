import { Image } from 'react-native';

// Rendered from the locked lockup by brand/scripts/render-icons.mjs (288 x 216, so 4:3).
const sources = {
  navy: require('../../assets/images/logo.png'),
  white: require('../../assets/images/logo-white.png'),
} as const;

type Props = {
  /** Width in dp; the height follows the 4:3 lockup. */
  width?: number;
  /** navy on light backgrounds, white on navy. */
  tone?: keyof typeof sources;
};

export function Logo({ width = 132, tone = 'navy' }: Props) {
  return (
    <Image
      source={sources[tone]}
      accessibilityRole="image"
      accessibilityLabel="Beni Bul"
      resizeMode="contain"
      style={{ width, height: (width * 3) / 4 }}
    />
  );
}
