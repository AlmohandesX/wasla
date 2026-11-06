/**
 * Decides whether the scanner may look at a code the camera just reported. The camera reports the same QR many
 * times a second, so a code is handled once: `lock()` closes the gate when a code is accepted, and only
 * `reset()` (the owner tapping "Scan again") opens it again. A code that is not ours only pauses it briefly.
 */
export function createScanGate(now: () => number = Date.now) {
  let locked = false;
  let pausedUntil = 0;
  return {
    open: () => !locked && now() >= pausedUntil,
    lock: () => {
      locked = true;
    },
    pause: (ms: number) => {
      pausedUntil = now() + ms;
    },
    reset: () => {
      locked = false;
      pausedUntil = 0;
    },
  };
}
