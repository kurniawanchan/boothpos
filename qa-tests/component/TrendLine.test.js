import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import TrendLine from '../../resources/js/components/page/TrendLine.vue';

const pts = (...vs) => vs.map((v, i) => ({ t: `${String(i).padStart(2, '0')}:00`, v: String(v) }));

describe('TrendLine (042)', () => {
  it('is an image whose label states first value, last value and direction', () => {
    render(TrendLine, { props: { series: pts(10, 20, 40), comparison: pts(8, 12, 15, 20, 30), label: 'Total order' } });
    const svg = screen.getByRole('img');
    const label = svg.getAttribute('aria-label');
    expect(label).toContain('Total order');
    expect(label).toContain('10');
    expect(label).toContain('40');
    expect(label).toContain('naik');
  });

  it('says "turun" for a falling series and "datar" for a constant one', () => {
    const { unmount } = render(TrendLine, { props: { series: pts(9, 5, 2), label: 'X' } });
    expect(screen.getByRole('img').getAttribute('aria-label')).toContain('turun');
    unmount();
    render(TrendLine, { props: { series: pts(4, 4, 4), label: 'X' } });
    expect(screen.getByRole('img').getAttribute('aria-label')).toContain('datar');
  });

  it('draws a solid actual line and a DASHED comparison line that spans the full width', () => {
    const { container } = render(TrendLine, { props: { series: pts(1, 2, 3), comparison: pts(1, 2, 3, 4, 5, 6) } });
    const actual = container.querySelector('[data-testid="trend-actual"]');
    const cmp = container.querySelector('[data-testid="trend-comparison"]');
    expect(actual).not.toBeNull();
    expect(actual.getAttribute('stroke-dasharray')).toBeNull();
    expect(cmp.getAttribute('stroke-dasharray')).toBeTruthy();
    // Skala x dipakai bersama: garis nyata (3 dari 6 titik) berhenti di tengah, pembanding menjangkau ujung kanan.
    const lastX = (d) => Number(d.trim().split(' ').filter((s) => s.startsWith('L')).pop().slice(1));
    expect(lastX(cmp.getAttribute('d'))).toBeGreaterThan(lastX(actual.getAttribute('d')));
  });

  it('renders a flat placeholder for an empty series without errors', () => {
    const { container } = render(TrendLine, { props: { series: [], comparison: [], label: 'Kosong' } });
    expect(container.querySelector('[data-testid="trend-placeholder"]')).not.toBeNull();
    expect(container.querySelector('[data-testid="trend-actual"]')).toBeNull();
    expect(screen.getByRole('img').getAttribute('aria-label')).toContain('belum ada data tren');
  });

  it('renders a flat placeholder for a single point', () => {
    const { container } = render(TrendLine, { props: { series: pts(5), comparison: [], label: 'Satu' } });
    expect(container.querySelector('[data-testid="trend-placeholder"]')).not.toBeNull();
    expect(container.querySelector('[data-testid="trend-actual"]')).toBeNull();
  });

  it('survives non-numeric and all-equal values (no NaN in the path)', () => {
    const { container } = render(TrendLine, { props: { series: [{ t: 'a', v: 'x' }, { t: 'b', v: null }], comparison: pts(0, 0) } });
    for (const el of container.querySelectorAll('path')) expect(el.getAttribute('d')).not.toMatch(/NaN/);
  });

  it('uses tokens/currentColor only (no hex) and the danger tone class', () => {
    const { container } = render(TrendLine, { props: { series: pts(1, 2), comparison: pts(2, 1), tone: 'danger' } });
    expect(container.innerHTML).not.toMatch(/#[0-9a-fA-F]{3,8}/);
    expect(screen.getByRole('img')).toHaveClass('text-marker-danger');
  });

  it('uses the supplied formatter in the label', () => {
    render(TrendLine, { props: { series: pts(1000, 2000), label: 'Penjualan', format: (v) => `Rp ${v}` } });
    expect(screen.getByRole('img').getAttribute('aria-label')).toContain('Rp 1000');
  });
});
