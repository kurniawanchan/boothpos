import { describe, it, expect } from 'vitest';
import { customerPayload, emptyCustomer, phoneDigits, validateCustomer } from '../../resources/js/utils/selfServiceCustomer';
import { buildOrderPayload } from '../../resources/js/utils/selfServiceCart';

describe('selfServiceCustomer', () => {
  it('a guest is always valid and sends no customer block', () => {
    expect(validateCustomer(emptyCustomer())).toEqual({});
    expect(customerPayload(emptyCustomer())).toBeNull();
    const payload = buildOrderPayload({ lines: [], serviceType: 'dine_in', proofToken: 'p', localRef: 'r', customer: null });
    expect(Object.keys(payload)).not.toContain('customer');
  });

  it('needs a name and a believable phone once details are chosen; email is optional', () => {
    const c = { mode: 'details', name: '', phone: '', email: '' };
    expect(validateCustomer(c)).toEqual({ name: 'name', phone: 'phone' });
    expect(validateCustomer({ ...c, name: 'Budi', phone: '081234567890' })).toEqual({});
    expect(validateCustomer({ ...c, name: 'Budi', phone: '12' })).toEqual({ phone: 'phone' });
    expect(validateCustomer({ ...c, name: 'Budi', phone: '081234567890', email: 'bukan-surel' })).toEqual({ email: 'email' });
    expect(validateCustomer({ ...c, name: 'x'.repeat(101), phone: '081234567890' })).toEqual({ name: 'name' });
  });

  it('reads every common way of writing the same number', () => {
    for (const raw of ['081234567890', '+6281234567890', '6281234567890', '0812-3456-7890', ' 812 3456 7890 ']) expect(phoneDigits(raw), raw).toBe('081234567890');
    for (const bad of ['', 'abc', '0812', '08123456789012345678']) expect(phoneDigits(bad), bad).toBeNull();
  });

  it('builds a trimmed payload and leaves a blank email out', () => {
    expect(customerPayload({ mode: 'details', name: ' Budi ', phone: ' 0812 ', email: '' })).toEqual({ name: 'Budi', phone: '0812' });
    expect(customerPayload({ mode: 'details', name: 'Budi', phone: '0812', email: ' b@x.test ' })).toEqual({ name: 'Budi', phone: '0812', email: 'b@x.test' });
  });
});
