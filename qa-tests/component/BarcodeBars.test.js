import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import BarcodeBars from '../../resources/js/components/catalog/BarcodeBars.vue';
import { barRuns, totalModules, moduleWidthMm, integerModulePx, PDF_RASTER_SCALE, QUIET_ZONE_MODULES, MIN_MODULE_MM } from '../../resources/js/utils/barcode';

// Pola NYATA hasil App\Support\Code128::pattern('A') (dicetak lewat php -r; sudah dicocokkan dengan library pembanding di Code128Test): 11*(1+2)+13 = 46 modul.
const PATTERN_A = '1101001000010100011000100010110001100011101011';

describe('barcode geometry (049)', () => {
  it('merges consecutive bar modules into runs and shifts them by the quiet zone', () => {
    expect(barRuns('0110100', 10)).toEqual([{ x: 11, w: 2 }, { x: 14, w: 1 }]);
    expect(barRuns('111', 0)).toEqual([{ x: 0, w: 3 }]);
    expect(barRuns('000', 10)).toEqual([]);
  });

  it('covers the whole pattern: total width of the runs equals the number of bar modules', () => {
    const runs = barRuns(PATTERN_A);
    expect(runs.reduce((sum, r) => sum + r.w, 0)).toBe([...PATTERN_A].filter((c) => c === '1').length);
    expect(runs[0].x).toBe(QUIET_ZONE_MODULES); // Start B diawali batang tepat setelah zona hening
  });

  it('counts both quiet zones in the total width', () => {
    expect(totalModules(PATTERN_A)).toBe(PATTERN_A.length + 2 * QUIET_ZONE_MODULES);
    expect(PATTERN_A.length).toBe(46);
  });

  it('derives the printed module width in millimetres', () => {
    // 275 modul (20 karakter) dalam 317 px CSS ~ 0,305 mm per modul: di atas batas 0,25 mm.
    const longPattern = '1'.repeat(255);
    expect(moduleWidthMm(longPattern, 317)).toBeGreaterThan(MIN_MODULE_MM);
  });
});

describe('BarcodeBars (049)', () => {
  it('draws one path from the server pattern and prints the readable code beneath', () => {
    render(BarcodeBars, { props: { pattern: PATTERN_A, text: 'A', width: 200 } });

    const svg = screen.getByRole('img', { name: 'A' });
    expect(svg.querySelectorAll('path')).toHaveLength(1);
    expect(svg).toHaveAttribute('viewBox', `0 0 ${PATTERN_A.length + 2 * QUIET_ZONE_MODULES} 1`);
    expect(svg).toHaveAttribute('shape-rendering', 'crispEdges');
    expect(screen.getByText('A')).toBeInTheDocument();
  });

  it('is always dark ink on white through tokens, never a raw colour or an accent', () => {
    const { container } = render(BarcodeBars, { props: { pattern: PATTERN_A, text: 'A' } });

    expect(container.querySelector('[data-testid="barcode"]').className).toMatch(/\bbg-white\b/);
    expect(container.querySelector('[data-testid="barcode"]').className).toMatch(/\btext-ink\b/);
    expect(container.querySelector('path')).toHaveAttribute('fill', 'currentColor');
    expect(container.innerHTML).not.toMatch(/#[0-9a-fA-F]{3,8}\b/); // tanpa hex mentah
  });

  it('shows only the readable code when the code cannot be encoded', () => {
    const { container } = render(BarcodeBars, { props: { pattern: null, text: 'セラー-1' } });

    expect(container.querySelector('svg')).toBeNull();
    expect(screen.getByText('セラー-1')).toBeInTheDocument();
  });

  it('draws every module a WHOLE number of device pixels wide, so bars never blur between 2 and 3 px', () => {
    const longest = '10'.repeat(127) + '1'; // 255 modul (kode 20 karakter) + 20 modul zona hening = 275
    const { container } = render(BarcodeBars, { props: { pattern: longest, text: 'SPFABC-KC-MCYTABC-12', width: 312, scale: PDF_RASTER_SCALE } });
    const svg = container.querySelector('svg');

    expect(integerModulePx(longest, 312, PDF_RASTER_SCALE)).toBe(2); // 624 / 275 = 2,27 -> turun ke 2 px bulat
    const devicePx = Number(svg.getAttribute('width')) * PDF_RASTER_SCALE;
    expect(devicePx / totalModules(longest)).toBe(2);
    expect(Number(svg.getAttribute('width'))).toBeLessThanOrEqual(312);
  });

  it('keeps even the longest code at or above the minimum printable module width', () => {
    const longest = '10'.repeat(127) + '1';
    const { container } = render(BarcodeBars, { props: { pattern: longest, text: 'SPFABC-KC-MCYTABC-12', width: 312, scale: PDF_RASTER_SCALE } });

    const mm = Number(container.querySelector('[data-testid="barcode"]').getAttribute('data-module-mm'));
    expect(mm).toBeGreaterThanOrEqual(MIN_MODULE_MM);
    expect(mm).toBeCloseTo(0.265, 2); // 2 px perangkat pada 192 dpi
  });

  it('gives shorter codes wider whole-pixel modules instead of stretching them to fractions', () => {
    const short = PATTERN_A; // 46 + 20 = 66 modul
    expect(integerModulePx(short, 312, PDF_RASTER_SCALE)).toBe(9); // 624 / 66 = 9,45 -> 9
    const { container } = render(BarcodeBars, { props: { pattern: short, text: 'A', width: 312, scale: PDF_RASTER_SCALE } });
    expect((Number(container.querySelector('svg').getAttribute('width')) * PDF_RASTER_SCALE) % totalModules(short)).toBe(0);
  });

  it('exposes no module width when there is no pattern', () => {
    const { container } = render(BarcodeBars, { props: { pattern: null, text: 'セラー-1' } });

    expect(container.querySelector('[data-testid="barcode"]').hasAttribute('data-module-mm')).toBe(false);
  });
});
