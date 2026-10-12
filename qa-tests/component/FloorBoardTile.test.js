import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import FloorBoardTile from '../../resources/js/components/sales/FloorBoardTile.vue';

// 044-sales-table-view (US2) — satu ubin papan Meja. Bentuk `tile` persis keluaran utils/floorBoard.js;
// ubin tidak menghitung apa pun sendiri, jadi tes memberinya ubin jadi.
const empty = (extra = {}) => ({
  key: 'table-1', kind: 'table', tableId: 1, name: 'T1', seats: 4, inactive: false, state: 'empty',
  orderId: null, orderNumber: null, elapsedMinutes: null, overLimit: false, partial: false, amount: null, amountCents: 0, paidCents: 0, ...extra,
});
const occupied = (extra = {}) => ({
  key: 'table-4', kind: 'table', tableId: 4, name: 'T4', seats: 4, inactive: false, state: 'occupied',
  orderId: 9, orderNumber: 'WJM-0009', elapsedMinutes: 19, overLimit: false, partial: false, amount: '33000.00', amountCents: 3300000, paidCents: 0, ...extra,
});

describe('FloorBoardTile', () => {
  it('ubin kosong: nama, lencana Kosong, "Tersedia", bukan tombol', () => {
    render(FloorBoardTile, { props: { tile: empty() } });

    expect(screen.getByText('T1')).toBeInTheDocument();
    expect(screen.getByText('Kosong')).toBeInTheDocument();
    expect(screen.getByText('Tersedia')).toBeInTheDocument();
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });

  it('ubin terisi: nama, lencana, nomor order, lama terbuka, nominal, dan tombol bernama aksesibel', () => {
    render(FloorBoardTile, { props: { tile: occupied() } });

    const btn = screen.getByRole('button');
    expect(btn).toHaveAttribute('aria-label', expect.stringMatching(/^T4, order WJM-0009, terbuka 19 mnt, Rp\s*33\.000/));
    expect(screen.getByText('Terisi')).toBeInTheDocument();
    expect(screen.getByText('WJM-0009')).toBeInTheDocument();
    expect(screen.getByText('19 mnt')).toBeInTheDocument();
    expect(screen.getByText(/33\.000/)).toBeInTheDocument();
    expect(screen.queryByText('Tersedia')).not.toBeInTheDocument();
  });

  it('lama terbuka di atas satu jam memakai format jam-menit', () => {
    render(FloorBoardTile, { props: { tile: occupied({ elapsedMinutes: 65 }) } });
    expect(screen.getByText('1 j 5 m')).toBeInTheDocument();
  });

  it('sebagian dibayar: lencana Sebagian + isian kuning; lewat batas: garis luar kuning (data-state tersedia untuk tes)', () => {
    const { rerender } = render(FloorBoardTile, { props: { tile: occupied({ partial: true }) } });
    expect(screen.getByText('Sebagian')).toBeInTheDocument();
    expect(screen.queryByText('Terisi')).not.toBeInTheDocument();
    expect(screen.getByRole('button')).toHaveAttribute('data-partial', 'true');
    expect(screen.getByRole('button').getAttribute('aria-label')).toContain('Sebagian dibayar');

    return rerender({ tile: occupied({ overLimit: true, elapsedMinutes: 51 }) }).then(() => {
      expect(screen.getByRole('button')).toHaveAttribute('data-over', 'true');
      expect(screen.getByRole('button').getAttribute('aria-label')).toContain('> 45 menit');
      expect(screen.getByText('Terisi')).toBeInTheDocument();
    });
  });

  it('meja nonaktif yang masih menahan order diberi tanda Nonaktif tetapi tetap bisa dibuka', () => {
    render(FloorBoardTile, { props: { tile: occupied({ inactive: true }) } });

    expect(screen.getByText('Nonaktif')).toBeInTheDocument();
    expect(screen.getByRole('button')).toBeInTheDocument();
  });

  it('Enter dan Spasi memancarkan select dengan ubinnya; klik juga', async () => {
    const user = userEvent.setup();
    const tile = occupied();
    const { emitted } = render(FloorBoardTile, { props: { tile } });

    screen.getByRole('button').focus();
    await user.keyboard('{Enter}');
    await user.keyboard(' ');
    await user.click(screen.getByRole('button'));

    expect(emitted().select).toHaveLength(3);
    expect(emitted().select[0][0]).toEqual(tile);
    expect(emitted().select[0][1]).toBe(screen.getByRole('button'));
  });

  it('ubin kosong tidak pernah memancarkan select', async () => {
    const user = userEvent.setup();
    const { emitted, container } = render(FloorBoardTile, { props: { tile: empty() } });

    await user.click(container.firstElementChild);
    expect(emitted().select).toBeUndefined();
  });

  it('lama terbuka ikut berubah ketika ubin baru (jam berdetak) diberikan', async () => {
    const { rerender } = render(FloorBoardTile, { props: { tile: occupied({ elapsedMinutes: 19 }) } });
    expect(screen.getByText('19 mnt')).toBeInTheDocument();

    await rerender({ tile: occupied({ elapsedMinutes: 20 }) });
    expect(screen.getByText('20 mnt')).toBeInTheDocument();
  });

  it('ubin takeaway memakai nama label dan tetap berupa tombol', () => {
    render(FloorBoardTile, { props: { tile: occupied({ kind: 'takeaway', name: 'Budi', tableId: null, key: 'takeaway-5' }) } });
    expect(screen.getByRole('button', { name: /^Budi, order WJM-0009/ })).toBeInTheDocument();
  });

  it('tidak ada literal warna hex di sumber komponen (hanya kelas token)', () => {
    const source = readFileSync(resolve(__dirname, '../../resources/js/components/sales/FloorBoardTile.vue'), 'utf8');
    expect(source).not.toMatch(/#[0-9a-fA-F]{3,8}\b/);
  });
});
