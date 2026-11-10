import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/vue';
import { createI18n } from 'vue-i18n';
import BaseMultiSelect from '../../resources/js/components/ui/BaseMultiSelect.vue';
import idMessages from '../../resources/js/locales/id.json';

// 066-preorder-filter-export-columns (US1) — mode `remote` opt-in: komponen berhenti
// menyaring opsi sendiri dan hanya memberi tahu pemanggil teks yang diketik,
// supaya pemanggil (CustomerFilterSelect) bisa memuat hasil dari server.
const OPTIONS = [
  { value: 1, label: 'Artist Satu' },
  { value: 2, label: 'Artist Dua' },
];

function renderSelect(props = {}) {
  const i18n = createI18n({ legacy: false, locale: 'id', messages: { id: idMessages } });
  const searches = [];
  const utils = render(BaseMultiSelect, {
    props: {
      modelValue: [],
      options: OPTIONS,
      'onUpdate:modelValue': () => {},
      onSearch: (term) => searches.push(term),
      ...props,
    },
    global: { plugins: [i18n] },
  });

  return { ...utils, searches };
}

async function openAndType(text) {
  const { default: userEvent } = await import('@testing-library/user-event');
  const user = userEvent.setup();
  await user.click(screen.getByRole('button'));
  if (text) await user.type(screen.getByPlaceholderText('Cari…'), text);
}

describe('BaseMultiSelect — remote mode (066)', () => {
  it('default mode is unchanged: it filters locally and emits no search event', async () => {
    const { searches } = renderSelect();

    await openAndType('dua');

    await waitFor(() => {
      expect(screen.getByRole('option', { name: 'Artist Dua' })).toBeInTheDocument();
      expect(screen.queryByRole('option', { name: 'Artist Satu' })).not.toBeInTheDocument();
    });
    expect(searches).toEqual([]);
  });

  it('remote mode keeps every given option visible and reports what was typed', async () => {
    const { searches } = renderSelect({ remote: true });

    await openAndType('zzz');

    // Opsi tidak disaring di klien — server yang menentukan hasil.
    expect(screen.getByRole('option', { name: 'Artist Satu' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Artist Dua' })).toBeInTheDocument();
    expect(searches.at(-1)).toBe('zzz');
  });

  it('remote mode asks for the first page again each time the panel opens', async () => {
    const { searches } = renderSelect({ remote: true });

    await openAndType('');

    expect(searches).toEqual(['']);
  });
});
