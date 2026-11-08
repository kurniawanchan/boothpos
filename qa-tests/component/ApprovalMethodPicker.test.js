import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import ApprovalMethodPicker from '../../resources/js/components/sales/ApprovalMethodPicker.vue';
import { useAuthStore } from '../../resources/js/stores/auth';

/** 064 — pemilih metode bersama: aturan berkas dan validasi satu tempat (dipakai dialog satu seller dan massal). */
function make(method = 'draw') {
  const pinia = createPinia();
  setActivePinia(pinia);
  useAuthStore().user = { id: 1, name: 'Penyetuju', role: 'Owner', menu_keys: ['sales'] };
  const wrapper = mount(ApprovalMethodPicker, { props: { method, sellerLabel: 'Seller X' }, global: { plugins: [pinia] }, attachTo: document.body });
  const errors = () => wrapper.emitted('error')?.map((e) => e[0]) ?? [];
  return { wrapper, errors };
}

async function pick(wrapper, file) {
  const input = wrapper.get('[data-testid="approval-file"]');
  Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
  await input.trigger('change');
}

describe('ApprovalMethodPicker (064)', () => {
  it('draw: validate() refuses a blank canvas and says why', () => {
    const { wrapper, errors } = make('draw');
    expect(wrapper.vm.validate()).toBe(false);
    expect(errors().at(-1)).toMatch(/gambar tanda tangan/i);
  });

  it('upload: refuses no file, a wrong type, an oversize file; accepts a PNG and returns it', async () => {
    const { wrapper, errors } = make('upload');
    expect(wrapper.vm.validate()).toBe(false);
    expect(errors().at(-1)).toMatch(/pilih gambar/i);

    await pick(wrapper, new File(['x'], 'ttd.gif', { type: 'image/gif' }));
    expect(errors().at(-1)).toMatch(/png atau jpg/i);
    expect(wrapper.vm.validate()).toBe(false);

    await pick(wrapper, new File([new Uint8Array(5 * 1024 * 1024 + 1)], 'besar.png', { type: 'image/png' }));
    expect(errors().at(-1)).toMatch(/5 MB/);

    const good = new File(['png'], 'ttd.png', { type: 'image/png' });
    await pick(wrapper, good);
    expect(wrapper.vm.validate()).toBe(true);
    expect(await wrapper.vm.getImage()).toBe(good);
  });

  it('stamp: always valid and sends no image', async () => {
    const { wrapper } = make('stamp');
    expect(wrapper.vm.validate()).toBe(true);
    expect(await wrapper.vm.getImage()).toBeNull();
    expect(wrapper.text()).toContain('Seller X');
  });

  it('clicking a tab asks the parent to change the method and clears the previous error', async () => {
    const { wrapper, errors } = make('draw');
    wrapper.vm.validate();
    await wrapper.get('[data-testid="method-stamp"]').trigger('click');
    expect(wrapper.emitted('update:method').at(-1)).toEqual(['stamp']);

    await wrapper.setProps({ method: 'stamp' });
    expect(errors().at(-1)).toBe('');
  });

  it('reset() forgets the chosen file and the error', async () => {
    const { wrapper, errors } = make('upload');
    await pick(wrapper, new File(['png'], 'ttd.png', { type: 'image/png' }));
    wrapper.vm.reset();
    expect(errors().at(-1)).toBe('');
    expect(wrapper.vm.validate()).toBe(false);
  });
});
