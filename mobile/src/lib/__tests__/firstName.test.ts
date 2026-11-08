import { firstName } from '../firstName';

describe('firstName', () => {
  it('takes the first word of a full name', () => {
    expect(firstName('Ayşe Nur Yılmaz')).toBe('Ayşe');
    expect(firstName('Admin')).toBe('Admin');
  });

  it('ignores surrounding and repeated spaces', () => {
    expect(firstName('   Mehmet    Demir ')).toBe('Mehmet');
  });

  it('is null when there is no name', () => {
    expect(firstName('')).toBeNull();
    expect(firstName('   ')).toBeNull();
    expect(firstName(null)).toBeNull();
    expect(firstName(undefined)).toBeNull();
  });
});
