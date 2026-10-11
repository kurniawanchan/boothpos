import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import ChangeChip from '../../resources/js/components/page/ChangeChip.vue';

const chip = () => screen.getByTestId('change-chip');

describe('ChangeChip (042)', () => {
  it('shows an up arrow, a signed percentage and hidden "naik" text, green when up is good', () => {
    render(ChangeChip, { props: { value: 6.8, kind: 'percent', goodWhen: 'up' } });
    expect(chip()).toHaveTextContent('+6,8%');
    expect(chip().querySelector('.ph-arrow-up')).not.toBeNull();
    expect(screen.getByText('naik')).toHaveClass('sr-only');
    expect(chip().dataset.tone).toBe('good');
  });

  it('shows a down arrow and a minus sign, red when up is good', () => {
    render(ChangeChip, { props: { value: -2.14, kind: 'percent', goodWhen: 'up' } });
    expect(chip()).toHaveTextContent('-2,1%');
    expect(chip().querySelector('.ph-arrow-down')).not.toBeNull();
    expect(screen.getByText('turun')).toHaveClass('sr-only');
    expect(chip().dataset.tone).toBe('bad');
  });

  it('inverts the colour for low stock: a rise in items is BAD, a fall is good', () => {
    const { unmount } = render(ChangeChip, { props: { value: 3, kind: 'items', goodWhen: 'down' } });
    expect(chip()).toHaveTextContent('+3 item');
    expect(chip().querySelector('.ph-arrow-up')).not.toBeNull(); // arah tetap naik
    expect(chip().dataset.tone).toBe('bad');
    unmount();

    render(ChangeChip, { props: { value: -2, kind: 'items', goodWhen: 'down' } });
    expect(chip()).toHaveTextContent('-2 item');
    expect(chip().dataset.tone).toBe('good');
  });

  it('shows the neutral no-comparison chip for null, never NaN or 0%', () => {
    render(ChangeChip, { props: { value: null, kind: 'percent', goodWhen: 'up' } });
    expect(chip()).toHaveTextContent('Tidak ada pembanding');
    expect(chip().dataset.tone).toBe('neutral');
    expect(chip().textContent).not.toMatch(/NaN|0%/);
  });

  it('uses the supplied no-comparison text', () => {
    render(ChangeChip, { props: { value: null, noComparisonText: 'Belum ada data kemarin' } });
    expect(chip()).toHaveTextContent('Belum ada data kemarin');
  });

  it('shows a neutral flat chip for a real zero change (and for a rise that rounds to zero)', () => {
    const { unmount } = render(ChangeChip, { props: { value: 0, kind: 'percent', goodWhen: 'up' } });
    expect(chip().dataset.tone).toBe('neutral');
    expect(chip()).toHaveTextContent('0,0%');
    expect(screen.getByText('tidak berubah')).toBeInTheDocument();
    unmount();

    render(ChangeChip, { props: { value: 0.04, kind: 'percent', goodWhen: 'up' } });
    expect(chip().dataset.tone).toBe('neutral');
  });

  it('never relies on colour alone: arrow glyph and screen-reader text are present for every direction', () => {
    for (const value of [5, -5]) {
      const { unmount } = render(ChangeChip, { props: { value, kind: 'percent', goodWhen: 'up' } });
      expect(chip().querySelector('i[aria-hidden="true"]')).not.toBeNull();
      expect(chip().querySelector('.sr-only')).not.toBeNull();
      unmount();
    }
  });
});
