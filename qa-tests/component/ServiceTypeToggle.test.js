import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import ServiceTypeToggle from '../../resources/js/components/pos/ServiceTypeToggle.vue';

describe('ServiceTypeToggle (090 US5)', () => {
  it('shows Dine-In and Take Away with exactly one active', () => {
    render(ServiceTypeToggle, { props: { modelValue: 'dine_in' } });

    expect(screen.getByRole('radiogroup', { name: 'Cara layanan' })).toBeInTheDocument();
    expect(screen.getByRole('radio', { name: 'Dine-In' })).toHaveAttribute('aria-checked', 'true');
    expect(screen.getByRole('radio', { name: 'Take Away' })).toHaveAttribute('aria-checked', 'false');
  });

  it('emits the server value when the other option is tapped', async () => {
    const user = userEvent.setup();
    const { emitted } = render(ServiceTypeToggle, { props: { modelValue: 'dine_in' } });

    await user.click(screen.getByRole('radio', { name: 'Take Away' }));

    expect(emitted()['update:modelValue'][0]).toEqual(['takeaway']);
  });
});
