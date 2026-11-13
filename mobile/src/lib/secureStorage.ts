// Native (iOS/Android): the device keychain / keystore via expo-secure-store. The web build resolves
// secureStorage.web.ts instead, because expo-secure-store has no web implementation.
export { deleteItemAsync, getItemAsync, setItemAsync } from 'expo-secure-store';
