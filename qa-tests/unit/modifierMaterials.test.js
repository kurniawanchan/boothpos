import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { createI18n } from 'vue-i18n';
import id from '../../resources/js/locales/id.json';
import OptionMaterialsEditor from '../../resources/js/components/restaurant/OptionMaterialsEditor.vue';
import { cleanMaterialRows, materialsChanged, materialsPayload, materialsSummary } from '../../resources/js/utils/optionMaterials';

// 087 — bahan per porsi sebuah pilihan modifier.
const i18n = createI18n({ legacy: false, locale: 'id', messages: { id } });
const t = i18n.global.t;
const TELUR = { id: 7, name: 'Telur', unit: 'butir' };
const CABAI = { id: 12, name: 'Cabai Bubuk', unit: 'gram' };

describe('optionMaterials payload', () => {
  it('drops rows without a material or with a non-positive quantity and casts numbers', () => {
    expect(cleanMaterialRows([
      { material_id: '7', quantity: '1' },
      { material_id: '', quantity: '2' },
      { material_id: 12, quantity: '0' },
      { material_id: 12, quantity: '0.125' },
    ])).toEqual([{ material_id: 7, quantity: 1 }, { material_id: 12, quantity: 0.125 }]);
  });

  it('treats the same rows in another order or with server formatting as unchanged', () => {
    const original = [{ material_id: 7, quantity: '1.0000' }, { material_id: 12, quantity: '2.0000' }];
    expect(materialsChanged(original, [{ material_id: 12, quantity: '2' }, { material_id: 7, quantity: 1 }])).toBe(false);
    expect(materialsChanged(original, [{ material_id: 7, quantity: '1.5' }, { material_id: 12, quantity: '2' }])).toBe(true);
  });

  it('sends materials only for new options with rows or for changed options (old clients keep mappings)', () => {
    expect(materialsPayload(undefined, [])).toEqual({});
    expect(materialsPayload(undefined, [{ material_id: 7, quantity: '1' }])).toEqual({ materials: [{ material_id: 7, quantity: 1 }] });
    expect(materialsPayload([{ material_id: 7, quantity: '1.0000' }], [{ material_id: 7, quantity: '1' }])).toEqual({});
    // Mengosongkan pemetaan harus mengirim [] (penggantian penuh), bukan menghilangkan kunci.
    expect(materialsPayload([{ material_id: 7, quantity: '1.0000' }], [])).toEqual({ materials: [] });
  });

  it('summarises the number of materials', () => {
    expect(materialsSummary([{}, {}], t)).toBe('2 bahan');
    expect(materialsSummary([], t)).toBe('');
  });
});

describe('OptionMaterialsEditor', () => {
  const draw = (props) => render(OptionMaterialsEditor, { props: { materials: [TELUR, CABAI], ...props }, global: { plugins: [i18n] } });

  it('shows the unit of the chosen material next to the quantity', () => {
    draw({ modelValue: [{ material_id: 12, quantity: '2' }] });
    expect(screen.getByTestId('option-material-unit')).toHaveTextContent('gram');
  });

  it('adds and removes rows through update:modelValue', async () => {
    const { emitted } = draw({ modelValue: [{ material_id: 7, quantity: '1' }] });
    const { default: userEvent } = await import('@testing-library/user-event');
    const user = userEvent.setup();

    await user.click(screen.getByRole('button', { name: id.dining_setup.option_materials_add }));
    expect(emitted()['update:modelValue'][0][0]).toEqual([{ material_id: 7, quantity: '1' }, { material_id: '', quantity: '1' }]);

    await user.click(screen.getByRole('button', { name: id.common.delete }));
    expect(emitted()['update:modelValue'][1][0]).toEqual([]);
  });

  it('marks a mapped material that is no longer active instead of hiding it', () => {
    draw({ modelValue: [{ material_id: 99, quantity: '1', name: 'Kornet', unit: 'kaleng' }] });
    expect(screen.getByTestId('option-material-unit')).toHaveTextContent('kaleng');
    expect(screen.getByTestId('option-material-unit')).toHaveTextContent(id.dining_setup.option_materials_inactive);
  });

  it('shows server errors on the matching row field', () => {
    draw({ modelValue: [{ material_id: 7, quantity: '0' }], errors: { '0.quantity': 'Jumlah bahan harus lebih dari 0.' } });
    expect(screen.getByText('Jumlah bahan harus lebih dari 0.')).toBeInTheDocument();
  });
});
