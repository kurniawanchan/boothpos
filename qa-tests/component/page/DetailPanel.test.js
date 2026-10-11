import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/vue';
import DetailPanel from '../../../resources/js/components/page/DetailPanel.vue';

const BASE = {
  open: true,
  kicker: 'Purchase request · WJM 2',
  title: 'PR-2610-031',
  chips: [{ label: 'Menunggu approval', variant: 'warning' }],
  facts: [{ k: 'Pemohon', v: 'Bayu' }, { k: 'Nilai', v: 'Rp 1.268.000' }],
};

describe('DetailPanel', () => {
  it('tidak merender apa pun saat tertutup', () => {
    render(DetailPanel, { props: { ...BASE, open: false } });
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('menampilkan kicker, judul, chip status, dan fakta kunci', () => {
    render(DetailPanel, { props: BASE });
    const dialog = screen.getByRole('dialog', { name: 'PR-2610-031' });
    expect(within(dialog).getByText('Purchase request · WJM 2')).toBeInTheDocument();
    expect(within(dialog).getByText('Menunggu approval')).toHaveClass('bg-warn-bg');
    expect(within(dialog).getByText('Pemohon')).toBeInTheDocument();
    expect(within(dialog).getByText('Rp 1.268.000')).toBeInTheDocument();
  });

  it('hanya merender tindakan yang diberikan dan memancarkan key-nya', async () => {
    const { emitted } = render(DetailPanel, {
      props: { ...BASE, actions: [{ key: 'approve', label: 'Setujui', variant: 'primary' }, { key: 'reject', label: 'Tolak', variant: 'danger' }] },
    });
    expect(screen.queryByRole('button', { name: 'Hapus' })).toBeNull();
    await fireEvent.click(screen.getByRole('button', { name: 'Setujui' }));
    expect(emitted().action).toEqual([['approve']]);
  });

  it('tindakan yang diblokir status tampil nonaktif dengan alasan di title', () => {
    render(DetailPanel, { props: { ...BASE, actions: [{ key: 'split', label: 'Pisah', disabled: true, title: 'Sudah ada pembayaran' }] } });
    const btn = screen.getByRole('button', { name: 'Pisah' });
    expect(btn).toBeDisabled();
    expect(btn).toHaveAttribute('title', 'Sudah ada pembayaran');
  });

  it('tanpa tindakan dan fakta tidak merender bagian kosong', () => {
    render(DetailPanel, { props: { open: true, title: 'X' } });
    expect(screen.queryByTestId('panel-actions')).toBeNull();
    expect(document.querySelector('dl')).toBeNull();
  });

  it('merender bagian bebas lewat slot dan menutup dengan Escape serta tombol tutup', async () => {
    const { emitted } = render(DetailPanel, { props: BASE, slots: { default: '<table><caption>Item</caption></table>' } });
    expect(screen.getByText('Item')).toBeInTheDocument();
    await fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
    expect(emitted().close).toHaveLength(1);
  });
});
