import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import AvailabilitySection from '../../resources/js/components/product/AvailabilitySection.vue';

// 045 Stage 3 — halaman item: satu baris per outlet, termasuk "tidak dijual" dan catatan 86 per outlet.
describe('AvailabilitySection (per outlet)', () => {
  const rows = [
    { outlet_id: 1, outlet_code: 'WJ1', outlet_name: 'Warkop 1', state: 'available' },
    { outlet_id: 2, outlet_code: 'WJ2', outlet_name: 'Warkop 2', state: 'sold_out', reason: 'Stok habis', marked_by: 'Ani' },
    { outlet_id: 3, outlet_code: 'WJ3', outlet_name: 'Warkop 3', state: 'not_carried' },
  ];

  it('renders one row per outlet with its name and state', () => {
    render(AvailabilitySection, { props: { availability: rows } });

    expect(screen.getAllByTestId('availability-row')).toHaveLength(3);
    expect(screen.getAllByTestId('availability-outlet-name').map((e) => e.textContent)).toEqual(['Warkop 1', 'Warkop 2', 'Warkop 3']);
    const chips = screen.getAllByTestId('availability-chip').map((c) => c.textContent.replace(/\s+/g, ' ').trim());
    expect(chips).toEqual(['WJ1 Ada', 'WJ2 86', 'WJ3 Tidak dijual']);
  });

  it('puts the 86 note only under the sold-out outlet', () => {
    render(AvailabilitySection, { props: { availability: rows } });

    const notes = screen.getAllByTestId('sold-out-note');
    expect(notes).toHaveLength(1);
    expect(notes[0]).toHaveTextContent('Stok habis');
  });
});
