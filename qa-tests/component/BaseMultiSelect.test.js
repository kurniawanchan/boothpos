import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createI18n } from 'vue-i18n';
import BaseMultiSelect from '../../resources/js/components/ui/BaseMultiSelect.vue';
import idMessages from '../../resources/js/locales/id.json';

function renderMultiSelect(props = {}) {
  const i18n = createI18n({ legacy: false, locale: 'id', messages: { id: idMessages } });
  return render(BaseMultiSelect, {
    props: {
      modelValue: [],
      options: [
        { value: 1, label: 'Outlet Satu' },
        { value: 2, label: 'Outlet Dua' },
        { value: 3, label: 'Outlet Tiga' },
      ],
      'onUpdate:modelValue': () => {},
      ...props,
    },
    global: { plugins: [i18n] },
  });
}

// 005-ux-enhancements-dashboard (US1)
describe('BaseMultiSelect', () => {
  it('shows "All" as selected by default with an empty modelValue', async () => {
    renderMultiSelect();
    expect(screen.getByText('Semua')).toBeInTheDocument();
  });

  it('filters visible options case-insensitively as the user types', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    renderMultiSelect();

    await user.click(screen.getByRole('button'));
    await user.type(screen.getByPlaceholderText('Cari…'), 'dua');

    await waitFor(() => {
      expect(screen.getByRole('option', { name: 'Outlet Dua' })).toBeInTheDocument();
      expect(screen.queryByRole('option', { name: 'Outlet Satu' })).not.toBeInTheDocument();
    });
  });

  it('selecting a specific option deselects "All" and emits the updated array', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    let emitted = null;
    renderMultiSelect({ 'onUpdate:modelValue': (v) => (emitted = v) });

    await user.click(screen.getByRole('button'));
    await user.click(await screen.findByRole('option', { name: 'Outlet Satu' }));

    expect(emitted).toEqual([1]);
  });

  it('selecting "All" again clears specific selections back to an empty array', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    let emitted = null;
    renderMultiSelect({ modelValue: [1, 2], 'onUpdate:modelValue': (v) => (emitted = v) });

    await user.click(screen.getByRole('button'));
    await user.click(await screen.findByRole('option', { name: 'Semua' }));

    expect(emitted).toEqual([]);
  });

  it('shows a count when more than one specific option is selected', async () => {
    renderMultiSelect({ modelValue: [1, 2] });
    expect(screen.getByText('2 dipilih')).toBeInTheDocument();
  });
});

// 2026-10-31 (laporan pemilik: "dropdown tidak bisa di-scroll", filter kategori POS) — akar masalah sama dengan
// BaseSelect 036: listener scroll window (capture) menutup panel pada scroll di dalam daftarnya sendiri.
describe('BaseMultiSelect — scrolling (2026-10-31)', () => {
  it('stays open when its own list is scrolled', async () => {
    const { fireEvent } = await import('@testing-library/vue');
    renderMultiSelect();
    await fireEvent.click(screen.getByRole('button'));
    const list = screen.getByRole('listbox');

    await fireEvent.scroll(list.querySelector('[data-testid="multiselect-options"]'));
    expect(screen.queryByRole('listbox')).toBeInTheDocument();
  });

  it('still closes when the page outside scrolls', async () => {
    const { fireEvent } = await import('@testing-library/vue');
    renderMultiSelect();
    await fireEvent.click(screen.getByRole('button'));

    await fireEvent.scroll(document.body);
    await waitFor(() => expect(screen.queryByRole('listbox')).not.toBeInTheDocument());
  });
});
