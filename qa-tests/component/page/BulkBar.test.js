import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import BulkBar from '../../../resources/js/components/page/BulkBar.vue';

const ACTIONS = [
  { key: 'approve', label: 'Setujui' },
  { key: 'reject', label: 'Tolak', danger: true },
  { key: 'locked', label: 'Terkunci', disabled: true },
];

describe('BulkBar', () => {
  it('tidak dirender bila tidak ada baris terpilih dan tidak ada hasil', () => {
    const { container } = render(BulkBar, { props: { count: 0, actions: ACTIONS } });
    expect(container.querySelector('[role="toolbar"]')).toBeNull();
  });

  it('menampilkan jumlah terpilih dan hanya tindakan yang diberikan pemanggil', () => {
    render(BulkBar, { props: { count: 3, actions: ACTIONS } });
    expect(screen.getByRole('toolbar')).toBeInTheDocument();
    expect(screen.getByText('3 dipilih')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Setujui' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Ekspor' })).toBeNull();
  });

  it('memancarkan key tindakan dan clear', async () => {
    const { emitted } = render(BulkBar, { props: { count: 2, actions: ACTIONS } });
    await fireEvent.click(screen.getByRole('button', { name: 'Tolak' }));
    await fireEvent.click(screen.getByRole('button', { name: /batalkan pilihan/i }));
    expect(emitted().action).toEqual([['reject']]);
    expect(emitted().clear).toHaveLength(1);
  });

  it('tindakan nonaktif dan saat sibuk tidak bisa diklik', async () => {
    const { emitted, rerender } = render(BulkBar, { props: { count: 2, actions: ACTIONS } });
    expect(screen.getByRole('button', { name: 'Terkunci' })).toBeDisabled();
    await rerender({ busy: true });
    expect(screen.getByRole('button', { name: 'Setujui' })).toBeDisabled();
    // userEvent meniru browser (tombol nonaktif tak menerima klik); fireEvent mengirim peristiwa sintetis begitu saja.
    await userEvent.setup().click(screen.getByRole('button', { name: 'Setujui' }));
    expect(emitted().action).toBeUndefined();
  });

  it('menampilkan hasil per baris: yang berhasil tidak tersembunyi oleh yang gagal', () => {
    render(BulkBar, {
      props: { count: 0, actions: [], result: { succeeded: 2, failed: [{ label: 'PR-2610-031', message: 'Tidak bisa menyetujui PR sendiri' }] } },
    });
    const status = screen.getByRole('status');
    expect(status).toHaveTextContent('2 ✓');
    expect(status).toHaveTextContent('1 ✗');
    expect(screen.getByText('PR-2610-031')).toBeInTheDocument();
    expect(screen.getByText(/Tidak bisa menyetujui PR sendiri/)).toBeInTheDocument();
  });
});
