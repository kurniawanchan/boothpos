import { describe, it, expect } from 'vitest';
import { formatIDR, toMoneyString, parseMoney } from '../../resources/js/utils/money';

describe('formatIDR', () => {
  it('formats a Money string with thousands separators and Rp prefix', () => {
    expect(formatIDR('150000.00')).toBe('Rp 150.000');
  });

  it('rounds fractional amounts for display', () => {
    expect(formatIDR('1999.60')).toBe('Rp 2.000');
  });

  it('handles a plain number as well as a string', () => {
    expect(formatIDR(45000)).toBe('Rp 45.000');
  });

  it('falls back to Rp 0 for non-numeric input', () => {
    expect(formatIDR(undefined)).toBe('Rp 0');
    expect(formatIDR('')).toBe('Rp 0');
  });
});

describe('toMoneyString', () => {
  it('serializes a number to the API two-decimal shape', () => {
    expect(toMoneyString(150000)).toBe('150000.00');
  });

  it('serializes a numeric string to the API two-decimal shape', () => {
    expect(toMoneyString('45000')).toBe('45000.00');
  });

  it('falls back to 0.00 for non-numeric input', () => {
    expect(toMoneyString(undefined)).toBe('0.00');
  });
});

describe('parseMoney', () => {
  it('parses a Money string into a JS number', () => {
    expect(parseMoney('150000.00')).toBe(150000);
  });

  it('passes through a number unchanged', () => {
    expect(parseMoney(42)).toBe(42);
  });

  it('falls back to 0 for non-numeric input', () => {
    expect(parseMoney('not-a-number')).toBe(0);
  });
});
