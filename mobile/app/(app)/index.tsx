import { Redirect } from 'expo-router';

// The signed-in home is the car list.
export default function Home() {
  return <Redirect href="/cars" />;
}
