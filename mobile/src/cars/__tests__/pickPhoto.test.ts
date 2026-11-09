import { Platform } from 'react-native';

import { CameraPermissionDenied, centerCrop, pickPhoto } from '../pickPhoto';

const mockLibrary = jest.fn();
const mockCamera = jest.fn();
const mockPermission = jest.fn();
const mockCrop = jest.fn();
const mockResize = jest.fn();
const mockSave = jest.fn(async () => ({ uri: 'file:///out.jpg' }));

jest.mock('expo-image-picker', () => ({
  launchImageLibraryAsync: (...a: unknown[]) => mockLibrary(...a),
  launchCameraAsync: (...a: unknown[]) => mockCamera(...a),
  requestCameraPermissionsAsync: () => mockPermission(),
}));
jest.mock('expo-image-manipulator', () => ({
  SaveFormat: { JPEG: 'jpeg' },
  ImageManipulator: {
    manipulate: () => {
      const context = {
        crop: (rect: unknown) => (mockCrop(rect), context),
        resize: (size: unknown) => (mockResize(size), context),
        renderAsync: async () => ({ saveAsync: mockSave }),
      };
      return context;
    },
  },
}));

const asset = (width: number, height: number) => ({ canceled: false, assets: [{ uri: 'file:///in.heic', width, height }] });

beforeEach(() => jest.clearAllMocks());

describe('centerCrop', () => {
  test('trims the sides of a wide photo and the top and bottom of a tall one, to 16:9', () => {
    expect(centerCrop(4000, 2000)).toEqual({ originX: 222, originY: 0, width: 3556, height: 2000 });
    expect(centerCrop(3024, 4032)).toEqual({ originX: 0, originY: 1165, width: 3024, height: 1701 });
    expect(centerCrop(1920, 1080)).toEqual({ originX: 0, originY: 0, width: 1920, height: 1080 });
  });
});

describe('pickPhoto', () => {
  test('crops a portrait iPhone photo to 16:9, scales it to 1024 wide and saves a JPEG', async () => {
    mockLibrary.mockResolvedValue(asset(3024, 4032));

    await expect(pickPhoto()).resolves.toEqual({ uri: 'file:///out.jpg', name: 'car.jpg', type: 'image/jpeg' });
    expect(mockLibrary).toHaveBeenCalledWith(expect.objectContaining({ aspect: [16, 9], allowsEditing: Platform.OS === 'android' }));
    expect(mockCrop).toHaveBeenCalledWith({ originX: 0, originY: 1165, width: 3024, height: 1701 });
    expect(mockResize).toHaveBeenCalledWith({ width: 1024 });
    expect(mockSave).toHaveBeenCalledWith({ format: 'jpeg', compress: 0.8 });
  });

  test('does not enlarge a small photo', async () => {
    mockLibrary.mockResolvedValue(asset(800, 450));
    await pickPhoto();
    expect(mockResize).not.toHaveBeenCalled();
  });

  test('takes a photo with the camera once allowed, and refuses without permission', async () => {
    mockPermission.mockResolvedValueOnce({ granted: true });
    mockCamera.mockResolvedValue(asset(4032, 3024));
    await expect(pickPhoto('camera')).resolves.not.toBeNull();
    expect(mockCamera).toHaveBeenCalledWith(expect.objectContaining({ aspect: [16, 9] }));

    mockPermission.mockResolvedValueOnce({ granted: false });
    await expect(pickPhoto('camera')).rejects.toBeInstanceOf(CameraPermissionDenied);
  });

  test('returns null when cancelled', async () => {
    mockLibrary.mockResolvedValue({ canceled: true, assets: null });
    await expect(pickPhoto()).resolves.toBeNull();
  });
});
