// Extends app.json.
//
// Cleartext http is allowed on Android only in a local test build (EAS "preview" profile sets
// EXPO_PUBLIC_ALLOW_LOCAL_HTTP=1) so the APK can reach a server on the LAN. Store builds stay https-only.
//
// Google Sign-In needs no config plugin on Android (the native module autolinks; Google matches the app by package
// name and signing SHA-1). iOS needs the reversed iOS client ID as a URL scheme, so the plugin is added only when
// GOOGLE_IOS_URL_SCHEME (com.googleusercontent.apps.…) is set.
module.exports = ({ config }) => {
  const plugins = [...(config.plugins ?? [])];

  if (process.env.GOOGLE_IOS_URL_SCHEME) {
    plugins.push(['@react-native-google-signin/google-signin', { iosUrlScheme: process.env.GOOGLE_IOS_URL_SCHEME }]);
  }
  if (process.env.EXPO_PUBLIC_ALLOW_LOCAL_HTTP === '1') {
    plugins.push(['expo-build-properties', { android: { usesCleartextTraffic: true } }]);
  }

  return { ...config, plugins };
};
