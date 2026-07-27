import { DurationPipe } from './duration.pipe';

describe('DurationPipe', () => {
  let pipe: DurationPipe;

  beforeEach(() => {
    pipe = new DurationPipe();
  });

  it('should format minutes under 60 as plain number', () => {
    expect(pipe.transform(0)).toBe('0');
    expect(pipe.transform(1)).toBe('1');
    expect(pipe.transform(45)).toBe('45');
    expect(pipe.transform(59)).toBe('59');
  });

  it('should format exact hours without trailing minutes', () => {
    expect(pipe.transform(60)).toBe('1h');
    expect(pipe.transform(120)).toBe('2h');
    expect(pipe.transform(300)).toBe('5h');
  });

  it('should format hours and minutes when remainder exists', () => {
    expect(pipe.transform(61)).toBe('1h 1m');
    expect(pipe.transform(90)).toBe('1h 30m');
    expect(pipe.transform(150)).toBe('2h 30m');
  });

  it('should round fractional minutes below 60', () => {
    expect(pipe.transform(45.6)).toBe('46');
    expect(pipe.transform(45.4)).toBe('45');
  });

  it('should handle null and undefined', () => {
    expect(pipe.transform(null)).toBe('');
    expect(pipe.transform(undefined)).toBe('');
  });

  it('should handle NaN', () => {
    expect(pipe.transform(NaN)).toBe('');
  });
});
