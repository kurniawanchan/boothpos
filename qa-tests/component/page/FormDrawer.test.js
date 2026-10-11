import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/vue';
import FormDrawer from '../../../resources/js/components/page/FormDrawer.vue';
import FormSection from '../../../resources/js/components/page/FormSection.vue';

const SLOT = {
  default: { components: { FormSection }, template: '<FormSection title="Informasi dasar" description="Nama dan kode"><label>Nama<input /></label></FormSection>' },
};

describe('FormDrawer', () => {
  it('tidak merender saat tertutup', () => {
    render(FormDrawer, { props: { open: false, title: 'Item menu' } });
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('merender judul, bagian berjudul, dan tombol Simpan/Batal', () => {
    render(FormDrawer, { props: { open: true, title: 'Item menu' }, slots: SLOT });
    expect(screen.getByRole('dialog', { name: 'Item menu' })).toBeInTheDocument();
    expect(screen.getByText('Informasi dasar')).toBeInTheDocument();
    expect(screen.getByText('Nama dan kode')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Simpan' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Batal' })).toBeInTheDocument();
  });

  it('tombol kirim memakai id form yang valid (tanpa spasi) dan Enter di form memancarkan submit', async () => {
    const { emitted, container } = render(FormDrawer, { props: { open: true, title: 'Item menu baru' }, slots: SLOT });
    const form = document.querySelector('form');
    expect(form.id).toMatch(/^form-drawer-\S+$/);
    expect(screen.getByRole('button', { name: 'Simpan' })).toHaveAttribute('form', form.id);
    await fireEvent.submit(form);
    expect(emitted().submit).toHaveLength(1);
    expect(container).toBeTruthy();
  });

  it('terkunci saat submitting (mencegah klik ganda)', () => {
    render(FormDrawer, { props: { open: true, title: 'X', submitting: true }, slots: SLOT });
    expect(screen.getByRole('button', { name: 'Simpan' })).toBeDisabled();
  });

  it('memakai label kirim khusus dan Batal memancarkan close', async () => {
    const { emitted } = render(FormDrawer, { props: { open: true, title: 'X', submitLabel: 'Simpan & masukkan ke rilis' } });
    expect(screen.getByRole('button', { name: 'Simpan & masukkan ke rilis' })).toBeInTheDocument();
    await fireEvent.click(screen.getByRole('button', { name: 'Batal' }));
    expect(emitted().close).toHaveLength(1);
  });
});
