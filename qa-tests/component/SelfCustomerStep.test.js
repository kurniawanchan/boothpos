import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import SelfCustomerStep from '../../resources/js/components/selfservice/SelfCustomerStep.vue';
import { emptyCustomer } from '../../resources/js/utils/selfServiceCustomer';

describe('SelfCustomerStep', () => {
  it('defaults to guest with only the privacy note and no fields', () => {
    render(SelfCustomerStep, { props: { modelValue: emptyCustomer() } });

    expect(screen.getByTestId('customer-guest')).toHaveAttribute('aria-checked', 'true');
    expect(screen.queryByTestId('customer-name')).not.toBeInTheDocument();
    expect(screen.getByTestId('customer-privacy')).toHaveTextContent('hanya dipakai untuk pesanan ini');
  });

  it('shows the three fields when the customer chooses to enter details, with email marked optional', async () => {
    const user = userEvent.setup();
    const { emitted } = render(SelfCustomerStep, { props: { modelValue: emptyCustomer() } });

    await user.click(screen.getByTestId('customer-details'));

    expect(emitted()['update:modelValue'][0][0]).toMatchObject({ mode: 'details' });
  });

  it('emits every keystroke as a new value without mutating the prop', async () => {
    const user = userEvent.setup();
    const model = { mode: 'details', name: '', phone: '', email: '' };
    const { emitted } = render(SelfCustomerStep, { props: { modelValue: model } });

    await user.type(screen.getByTestId('customer-name'), 'B');

    expect(emitted()['update:modelValue'].at(-1)[0]).toMatchObject({ mode: 'details', name: 'B' });
    expect(model.name).toBe('');
  });

  it('marks required fields, the right input types, and shows the errors it is given', () => {
    render(SelfCustomerStep, { props: { modelValue: { mode: 'details', name: '', phone: 'abc', email: 'x' }, errors: { name: 'name', phone: 'phone', email: 'email' } } });

    expect(screen.getByTestId('customer-phone')).toHaveAttribute('type', 'tel');
    expect(screen.getByTestId('customer-email')).toHaveAttribute('type', 'email');
    expect(screen.getByText('Nama wajib diisi.')).toBeInTheDocument();
    expect(screen.getByText('Nomor telepon tidak valid. Contoh: 081234567890.')).toBeInTheDocument();
    expect(screen.getByText('Format surel tidak valid.')).toBeInTheDocument();
    expect(screen.getByTestId('customer-phone')).toHaveAttribute('aria-invalid', 'true');
  });
});
