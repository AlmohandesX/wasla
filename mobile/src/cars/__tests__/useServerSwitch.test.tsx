import { act, create } from 'react-test-renderer';

import { ApiError } from '../../api/errors';
import type { Car } from '../../api/cars';
import { useChatsPaused, useStealth } from '../useServerSwitch';

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(async () => null),
  setItemAsync: jest.fn(async () => undefined),
  deleteItemAsync: jest.fn(async () => undefined),
}));
const mockSetStealth = jest.fn();
const mockSetChatsPaused = jest.fn();
const mockGetCar = jest.fn();
jest.mock('../../api/cars', () => ({
  setStealth: (...a: unknown[]) => mockSetStealth(...a),
  setChatsPaused: (...a: unknown[]) => mockSetChatsPaused(...a),
  getCar: (...a: unknown[]) => mockGetCar(...a),
}));

(globalThis as unknown as { IS_REACT_ACT_ENVIRONMENT: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const CAR = { id: 7, stealth_enabled: false, chats_paused: false } as Car;
const networkError = () => new ApiError(0, 'network_error', 'offline');

type Hook = ReturnType<typeof useStealth>;
let hook: Hook;
const onUpdated = jest.fn();

function mount(use: (cb: (c: Car) => void) => Hook = useStealth) {
  function Probe() {
    hook = use(onUpdated);
    return null;
  }
  act(() => void create(<Probe />));
}

beforeEach(() => jest.clearAllMocks());

test('keeps the pending value while the request is out and takes only the server answer', async () => {
  let resolve!: (c: Car) => void;
  mockSetStealth.mockReturnValue(new Promise<Car>((r) => (resolve = r)));
  mount();

  let done!: Promise<void>;
  act(() => void (done = hook.toggle(CAR)));
  expect(hook.pendingValue).toBe(true);
  expect(onUpdated).not.toHaveBeenCalled(); // nothing is claimed before the server confirms

  await act(async () => {
    resolve({ ...CAR, stealth_enabled: true });
    await done;
  });
  expect(onUpdated).toHaveBeenCalledWith({ ...CAR, stealth_enabled: true });
  expect(hook.pendingValue).toBeNull();
  expect(hook.error).toBeNull();
});

test('a refused request rolls back and shows an error', async () => {
  mockSetStealth.mockRejectedValue(new ApiError(500, 'server_error', 'boom'));
  mount();
  await act(async () => hook.toggle(CAR));
  expect(onUpdated).not.toHaveBeenCalled();
  expect(hook.pendingValue).toBeNull();
  expect(hook.error).toContain('Gizli mod');
  expect(mockGetCar).not.toHaveBeenCalled();
});

test('a second tap while a request is out is ignored', async () => {
  let resolve!: (c: Car) => void;
  mockSetStealth.mockReturnValue(new Promise<Car>((r) => (resolve = r)));
  mount();
  let first!: Promise<void>;
  act(() => void (first = hook.toggle(CAR)));
  await act(async () => hook.toggle(CAR));
  expect(mockSetStealth).toHaveBeenCalledTimes(1);
  await act(async () => {
    resolve(CAR);
    await first;
  });
});

test('after a network error the car is read again, because the request may have applied', async () => {
  mockSetStealth.mockRejectedValue(networkError());
  mockGetCar.mockResolvedValue({ ...CAR, stealth_enabled: true });
  mount();
  await act(async () => hook.toggle(CAR));
  expect(mockGetCar).toHaveBeenCalledWith(7);
  expect(onUpdated).toHaveBeenCalledWith({ ...CAR, stealth_enabled: true });
  expect(hook.error).toBeNull(); // it did apply
});

test('after a network error that did not apply, the fresh car is shown with an error', async () => {
  mockSetStealth.mockRejectedValue(networkError());
  mockGetCar.mockResolvedValue(CAR);
  mount();
  await act(async () => hook.toggle(CAR));
  expect(onUpdated).toHaveBeenCalledWith(CAR);
  expect(hook.error).not.toBeNull();
});

test('when the re-read fails too, the car stays as it was and an error shows', async () => {
  mockSetStealth.mockRejectedValue(networkError());
  mockGetCar.mockRejectedValue(networkError());
  mount();
  await act(async () => hook.toggle(CAR));
  expect(onUpdated).not.toHaveBeenCalled();
  expect(hook.error).not.toBeNull();
});

test('the chats-paused switch behaves the same way', async () => {
  mockSetChatsPaused.mockResolvedValue({ ...CAR, chats_paused: true });
  mount(useChatsPaused);
  await act(async () => hook.toggle(CAR));
  expect(mockSetChatsPaused).toHaveBeenCalledWith(7, true);
  expect(onUpdated).toHaveBeenCalledWith({ ...CAR, chats_paused: true });

  mockSetChatsPaused.mockRejectedValue(new ApiError(500, 'server_error', 'boom'));
  onUpdated.mockClear();
  await act(async () => hook.toggle(CAR));
  expect(onUpdated).not.toHaveBeenCalled();
  expect(hook.error).toContain('Sohbet');
});
