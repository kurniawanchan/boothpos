import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import AvailabilityChips from '../../resources/js/components/product/AvailabilityChips.vue';

// 043 (T019) — chip ketersediaan: teks + warna (tidak pernah warna saja), satu chip per entri.
describe('AvailabilityChips', () => {
  it('shows a single "Ada" chip for the whole install (Stage A: no outlet code)', () => {
    render(AvailabilityChips, { props: { availability: [{ outlet_id: null, outlet_code: null, state: 'available', reason: null }] } });
    const chips = screen.getAllByTestId('availability-chip');
    expect(chips).toHaveLength(1);
    expect(chips[0]).toHaveTextContent(/^Ada$/);
    expect(chips[0]).toHaveClass('bg-success-bg');
  });

  it('shows "86" in danger tokens for a sold-out entry, with the reason as hover text', () => {
    render(AvailabilityChips, { props: { availability: [{ outlet_id: null, outlet_code: null, state: 'sold_out', reason: 'Stok habis' }] } });
    const chip = screen.getByTestId('availability-chip');
    expect(chip).toHaveTextContent(/^86$/);
    expect(chip).toHaveClass('bg-danger-bg');
    expect(chip).toHaveAttribute('title', 'Stok habis');
  });

  it('never relies on colour alone: each state carries a different icon next to the text', () => {
    render(AvailabilityChips, {
      props: { availability: [
        { outlet_id: 1, outlet_code: 'WJM1', state: 'available', reason: null },
        { outlet_id: 2, outlet_code: 'WJM2', state: 'sold_out', reason: null },
      ] },
    });
    const [ok, soldOut] = screen.getAllByTestId('availability-chip');
    expect(ok.querySelector('.ph-check-circle')).not.toBeNull();
    expect(soldOut.querySelector('.ph-prohibit')).not.toBeNull();
  });

  it('renders one chip per outlet, prefixed with the outlet code (Stage B)', () => {
    render(AvailabilityChips, {
      props: { availability: [
        { outlet_id: 1, outlet_code: 'WJM1', state: 'available', reason: null },
        { outlet_id: 2, outlet_code: 'WJM2', state: 'sold_out', reason: null },
      ] },
    });
    const chips = screen.getAllByTestId('availability-chip');
    expect(chips.map((c) => c.textContent.replace(/\s+/g, ' ').trim())).toEqual(['WJM1 Ada', 'WJM2 86']);
  });

  it('shows an explicit "unavailable" marker (not an empty cell) when there are no entries', () => {
    render(AvailabilityChips, { props: { availability: [] } });
    expect(screen.queryByTestId('availability-chip')).not.toBeInTheDocument();
    expect(screen.getByTestId('availability-none')).toHaveTextContent('Tidak tersedia');
  });
});
