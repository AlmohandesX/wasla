// Extends app.json. Cleartext http is allowed on Android only in a local test build (EAS "preview" profile sets
// EXPO_PUBLIC_ALLOW_LOCAL_HTTP=1) so the APK can reach a server on the LAN. Store builds stay https-only.
module.exports = ({ config }) => {
  if (process.env.EXPO_PUBLIC_ALLOW_LOCAL_HTTP !== '1') return config;

  return {
    ...config,
    plugins: [...(config.plugins ?? []), ['expo-build-properties', { android: { usesCleartextTraffic: true } }]],
  };
};
