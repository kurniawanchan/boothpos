import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import ViewToggle from '../../resources/js/components/page/ViewToggle.vue';

// 043 (T019) — saklar Grid | Tabel: radiogroup bersegmen yang bisa dioperasikan keyboard.
const OPTIONS = [
  { value: 'grid', label: 'Grid' },
  { value: 'table', label: 'Tabel' },
];

function renderToggle(modelValue = 'grid') {
  const updates = [];
  const view = render(ViewToggle, {
    props: { options: OPTIONS, modelValue, label: 'Tampilan daftar', 'onUpdate:modelValue': (v) => updates.push(v) },
  });
  return { ...view, updates };
}

describe('ViewToggle', () => {
  it('is a radiogroup with one checked radio per option and visible text labels', () => {
    renderToggle('table');
    expect(screen.getByRole('radiogroup', { name: 'Tampilan daftar' })).toBeInTheDocument();
    const radios = screen.getAllByRole('radio');
    expect(radios.map((r) => r.textContent.trim())).toEqual(['Grid', 'Tabel']);
    expect(screen.getByRole('radio', { name: 'Tabel' })).toHaveAttribute('aria-checked', 'true');
    expect(screen.getByRole('radio', { name: 'Grid' })).toHaveAttribute('aria-checked', 'false');
  });

  it('keeps only the checked radio in the tab order (roving tabindex)', () => {
    renderToggle('grid');
    expect(screen.getByRole('radio', { name: 'Grid' })).toHaveAttribute('tabindex', '0');
    expect(screen.getByRole('radio', { name: 'Tabel' })).toHaveAttribute('tabindex', '-1');
  });

  it('emits the clicked value', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const { updates } = renderToggle('grid');
    await user.click(screen.getByRole('radio', { name: 'Tabel' }));
    expect(updates).toEqual(['table']);
  });

  it('moves and selects with the arrow keys, wrapping around, and Home/End jump to the ends', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    const { updates } = renderToggle('grid');

    screen.getByRole('radio', { name: 'Grid' }).focus();
    await user.keyboard('{ArrowRight}');
    expect(updates.at(-1)).toBe('table');

    // ArrowLeft dari yang pertama melingkar ke yang terakhir.
    screen.getByRole('radio', { name: 'Grid' }).focus();
    await user.keyboard('{ArrowLeft}');
    expect(updates.at(-1)).toBe('table');

    screen.getByRole('radio', { name: 'Grid' }).focus();
    await user.keyboard('{End}');
    expect(updates.at(-1)).toBe('table');
    await user.keyboard('{Home}');
    expect(updates.at(-1)).toBe('grid');
  });
});
