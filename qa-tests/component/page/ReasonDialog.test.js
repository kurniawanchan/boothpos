import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import ReasonDialog from '../../../resources/js/components/page/ReasonDialog.vue';

const REASONS = [{ id: 7, label: 'Salah input kasir' }, { id: 9, label: 'Item habis (86)' }];
const confirm = (name = 'Void') => screen.getByRole('button', { name });
const mountDialog = (props = {}) => render(ReasonDialog, { props: { open: true, title: 'Void item', message: 'Yakin?', confirmLabel: 'Void', ...props } });

describe('ReasonDialog (kit) — mode kode alasan', () => {
  it('menampilkan daftar alasan dan menonaktifkan konfirmasi sampai satu dipilih', async () => {
    const user = userEvent.setup();
    mountDialog({ reasons: REASONS });
    expect(confirm()).toBeDisabled();
    await user.selectOptions(screen.getByLabelText('Alasan'), 'Salah input kasir');
    expect(confirm()).toBeEnabled();
  });

  it('memancarkan reason_code_id (angka) dan catatan opsional yang dirapikan', async () => {
    const user = userEvent.setup();
    const { emitted } = mountDialog({ reasons: REASONS });
    await user.selectOptions(screen.getByLabelText('Alasan'), 'Item habis (86)');
    await user.type(screen.getByLabelText('Catatan (opsional)'), '  stok kosong  ');
    await user.click(confirm());
    expect(emitted().confirm).toEqual([[{ reason_code_id: 9, note: 'stok kosong' }]]);
  });

  it('catatan kosong dikirim sebagai null', async () => {
    const user = userEvent.setup();
    const { emitted } = mountDialog({ reasons: REASONS });
    await user.selectOptions(screen.getByLabelText('Alasan'), 'Salah input kasir');
    await user.click(confirm());
    expect(emitted().confirm[0][0]).toEqual({ reason_code_id: 7, note: null });
  });
});

describe('ReasonDialog (kit) — mode teks bebas (sampai endpoint kode alasan ada)', () => {
  it('mewajibkan teks dan menolak yang hanya spasi', async () => {
    const user = userEvent.setup();
    mountDialog();
    expect(confirm()).toBeDisabled();
    await user.type(screen.getByLabelText('Alasan'), '   ');
    expect(confirm()).toBeDisabled();
    await user.type(screen.getByLabelText('Alasan'), 'salah pesan');
    expect(confirm()).toBeEnabled();
  });

  it('memancarkan alasan yang dirapikan', async () => {
    const user = userEvent.setup();
    const { emitted } = mountDialog();
    await user.type(screen.getByLabelText('Alasan'), '  salah pesan  ');
    await user.click(confirm());
    expect(emitted().confirm).toEqual([[{ reason: 'salah pesan' }]]);
  });
});

describe('ReasonDialog (kit) — umum', () => {
  it('menampilkan judul dan pesan, dan Batal memancarkan close', async () => {
    const user = userEvent.setup();
    const { emitted } = mountDialog();
    expect(screen.getByRole('dialog')).toBeInTheDocument();
    expect(screen.getByText('Yakin?')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Batal' }));
    expect(emitted().close).toHaveLength(1);
  });

  it('mengosongkan isian saat dibuka kembali', async () => {
    const user = userEvent.setup();
    const { rerender } = mountDialog();
    await user.type(screen.getByLabelText('Alasan'), 'sisa');
    await rerender({ open: false });
    await rerender({ open: true });
    expect(screen.getByLabelText('Alasan')).toHaveValue('');
  });

  it('tidak memancarkan confirm bila belum siap, dan terkunci saat submitting', async () => {
    const user = userEvent.setup();
    const { emitted, rerender } = mountDialog({ reasons: REASONS });
    await user.click(confirm()).catch(() => {});
    expect(emitted().confirm).toBeUndefined();
    await user.selectOptions(screen.getByLabelText('Alasan'), 'Salah input kasir');
    await rerender({ submitting: true });
    expect(confirm()).toBeDisabled();
  });
});
