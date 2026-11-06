import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';
import * as ImagePicker from 'expo-image-picker';

const MAX_SIDE = 1600;

export type PickedPhoto = { uri: string; name: string; type: string };

/**
 * Lets the owner choose a photo and returns it ready to upload: no wider than 1600 px and re-encoded as JPEG,
 * far below the backend's 10 MB limit (JPEG, PNG or WebP accepted). Null when the picker was cancelled.
 * Re-encoding also turns formats the backend does not take (HEIC) into JPEG.
 */
export async function pickPhoto(): Promise<PickedPhoto | null> {
  const picked = await ImagePicker.launchImageLibraryAsync({ mediaTypes: ['images'], allowsEditing: false, quality: 1 });
  if (picked.canceled || !picked.assets[0]) return null;

  const asset = picked.assets[0];
  const context = ImageManipulator.manipulate(asset.uri);
  if (asset.width > MAX_SIDE || asset.height > MAX_SIDE) {
    context.resize(asset.width >= asset.height ? { width: MAX_SIDE } : { height: MAX_SIDE });
  }
  const image = await context.renderAsync();
  const saved = await image.saveAsync({ format: SaveFormat.JPEG, compress: 0.8 });
  return { uri: saved.uri, name: 'car.jpg', type: 'image/jpeg' };
}
