import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/vue';
import OutletSelector from '../../resources/js/components/layout/OutletSelector.vue';
import { i18n } from '../../resources/js/i18n';

const t = (key) => i18n.global.t(key);
const OUTLETS = [{ id: 1, name: 'Warkop WJM 1' }, { id: 2, name: 'Warkop WJM 2' }, { id: 3, name: 'Warkop WJM 3' }];

describe('OutletSelector', () => {
  it('tidak dirender untuk nol atau satu outlet (instalasi satu toko)', () => {
    const { container, rerender } = render(OutletSelector, { props: { outlets: [] } });
    expect(container.querySelector('select')).toBeNull();
    rerender({ outlets: [OUTLETS[0]] });
    return Promise.resolve().then(() => expect(container.querySelector('select')).toBeNull());
  });

  it('menampilkan "Semua outlet (n)" dan semua outlet bila lebih dari satu', () => {
    render(OutletSelector, { props: { outlets: OUTLETS } });
    const select = screen.getByRole('combobox', { name: t('frame.outlet_label') });
    const options = Array.from(select.querySelectorAll('option')).map((o) => o.textContent.trim());
    expect(options).toEqual([`${t('frame.outlet_all')} (3)`, 'Warkop WJM 1', 'Warkop WJM 2', 'Warkop WJM 3']);
    expect(select.value).toBe('');
  });

  it('mencerminkan outlet terpilih', () => {
    render(OutletSelector, { props: { outlets: OUTLETS, modelValue: 2 } });
    expect(screen.getByRole('combobox')).toHaveValue('2');
  });

  it('memancarkan id numerik saat memilih outlet dan null saat memilih semua', async () => {
    const { emitted } = render(OutletSelector, { props: { outlets: OUTLETS, modelValue: null } });
    const select = screen.getByRole('combobox');
    await fireEvent.update(select, '3');
    await fireEvent.update(select, '');
    expect(emitted()['update:modelValue']).toEqual([[3], [null]]);
  });
});
