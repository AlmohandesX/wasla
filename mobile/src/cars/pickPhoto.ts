import { ImageManipulator, SaveFormat } from 'expo-image-manipulator';
import * as ImagePicker from 'expo-image-picker';
import { Platform } from 'react-native';

/** Every car photo is this shape, so it fills its frame on any screen without stretching. */
export const PHOTO_ASPECT: [number, number] = [16, 9];

/** Width of the uploaded photo; the height follows the aspect (1024 x 576). The server keeps it at most this. */
export const PHOTO_WIDTH = 1024;

export type PickedPhoto = { uri: string; name: string; type: string };

export type PhotoSource = 'library' | 'camera';

/** The largest centred rectangle of the photo aspect that fits in a width x height image. */
export function centerCrop(width: number, height: number, [aw, ah]: [number, number] = PHOTO_ASPECT) {
  const target = aw / ah;
  if (width / height > target) {
    const cropWidth = Math.round(height * target);
    return { originX: Math.floor((width - cropWidth) / 2), originY: 0, width: cropWidth, height };
  }
  const cropHeight = Math.round(width / target);
  return { originX: 0, originY: Math.floor((height - cropHeight) / 2), width, height: cropHeight };
}

/**
 * Lets the owner choose or take a photo and returns it ready to upload: cropped to 16:9, 1024 x 576 at most and
 * re-encoded as JPEG (which also turns HEIC into something the backend accepts). Null when cancelled.
 *
 * Android's editor crops to 16:9 itself. iOS's editor only crops squares, so there the editor is off and the
 * photo is centre-cropped here instead; on Android that step is a no-op.
 */
export async function pickPhoto(source: PhotoSource = 'library'): Promise<PickedPhoto | null> {
  const options: ImagePicker.ImagePickerOptions = {
    mediaTypes: ['images'],
    allowsEditing: Platform.OS === 'android',
    aspect: PHOTO_ASPECT,
    quality: 1,
  };

  let picked: ImagePicker.ImagePickerResult;
  if (source === 'camera') {
    const permission = await ImagePicker.requestCameraPermissionsAsync();
    if (!permission.granted) throw new CameraPermissionDenied();
    picked = await ImagePicker.launchCameraAsync(options);
  } else {
    picked = await ImagePicker.launchImageLibraryAsync(options);
  }
  if (picked.canceled || !picked.assets[0]) return null;

  const asset = picked.assets[0];
  const crop = centerCrop(asset.width, asset.height);
  const context = ImageManipulator.manipulate(asset.uri).crop(crop);
  if (crop.width > PHOTO_WIDTH) context.resize({ width: PHOTO_WIDTH });
  const image = await context.renderAsync();
  const saved = await image.saveAsync({ format: SaveFormat.JPEG, compress: 0.8 });
  return { uri: saved.uri, name: 'car.jpg', type: 'image/jpeg' };
}

/** The owner said no to the camera; the screen explains how to allow it. */
export class CameraPermissionDenied extends Error {
  constructor() {
    super('camera permission denied');
    this.name = 'CameraPermissionDenied';
  }
}
