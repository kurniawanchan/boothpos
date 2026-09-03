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
        { value: 1, label: 'Artist Satu' },
        { value: 2, label: 'Artist Dua' },
        { value: 3, label: 'Artist Tiga' },
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
      expect(screen.getByRole('option', { name: 'Artist Dua' })).toBeInTheDocument();
      expect(screen.queryByRole('option', { name: 'Artist Satu' })).not.toBeInTheDocument();
    });
  });

  it('selecting a specific option deselects "All" and emits the updated array', async () => {
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();
    let emitted = null;
    renderMultiSelect({ 'onUpdate:modelValue': (v) => (emitted = v) });

    await user.click(screen.getByRole('button'));
    await user.click(await screen.findByRole('option', { name: 'Artist Satu' }));

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
