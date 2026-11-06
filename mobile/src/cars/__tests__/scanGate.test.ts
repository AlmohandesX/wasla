import { createScanGate } from '../scanGate';

test('lets the first code in, then ignores the rest until released', () => {
  const gate = createScanGate();
  expect(gate.open()).toBe(true);
  gate.lock();
  expect(gate.open()).toBe(false);
  expect(gate.open()).toBe(false);
});

test('stays closed after a refused claim until the owner scans again', () => {
  const gate = createScanGate();
  gate.lock(); // a claim started
  // The claim was refused: nothing reopens the gate by itself, so the same QR in view cannot claim again.
  for (let i = 0; i < 20; i++) expect(gate.open()).toBe(false);
  gate.reset(); // "Scan again"
  expect(gate.open()).toBe(true);
});

test('a rejected code pauses the camera for the given time', () => {
  let clock = 1000;
  const gate = createScanGate(() => clock);
  gate.pause(2500);
  expect(gate.open()).toBe(false);
  clock += 2499;
  expect(gate.open()).toBe(false);
  clock += 1;
  expect(gate.open()).toBe(true);
});
