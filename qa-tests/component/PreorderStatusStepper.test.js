import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import PreorderStatusStepper from '../../resources/js/components/preorder/PreorderStatusStepper.vue';

describe('PreorderStatusStepper', () => {
  it('renders every step label for an in-progress preorder', () => {
    render(PreorderStatusStepper, { props: { status: 'dp_paid' } });
    ['Dipesan', 'DP dibayar', 'Barang tiba', 'Lunas', 'Diserahkan'].forEach((label) => {
      expect(screen.getByText(label)).toBeInTheDocument();
    });
  });

  it('marks the current step for screen readers via aria-current', () => {
    render(PreorderStatusStepper, { props: { status: 'arrived' } });
    expect(screen.getByText('3')).toHaveAttribute('aria-current', 'step');
  });

  it('renders a cancelled banner instead of the stepper when cancelled', () => {
    render(PreorderStatusStepper, { props: { status: 'cancelled' } });
    expect(screen.getByText(/dibatalkan/i)).toBeInTheDocument();
    expect(screen.queryByText('Dipesan')).not.toBeInTheDocument();
  });
});
