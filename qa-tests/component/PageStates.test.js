import { describe, expect, it } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/vue';
import LoadingState from '../../resources/js/components/ui/LoadingState.vue';
import ErrorState from '../../resources/js/components/ui/ErrorState.vue';
import EmptyState from '../../resources/js/components/ui/EmptyState.vue';
import StatusPill from '../../resources/js/components/ui/StatusPill.vue';

describe('LoadingState', () => {
  it('berstatus "sibuk" dan punya label untuk pembaca layar', () => {
    render(LoadingState);
    const el = screen.getByRole('status');
    expect(el).toHaveAttribute('aria-busy', 'true');
    expect(screen.getByText('Memuat…')).toBeInTheDocument();
  });

  it('merender jumlah baris kerangka sesuai prop', () => {
    const { container } = render(LoadingState, { props: { rows: 3 } });
    expect(container.querySelectorAll('.animate-pulse')).toHaveLength(3);
  });
});

describe('ErrorState', () => {
  it('menampilkan pesan bawaan dan memancarkan retry', async () => {
    const { emitted } = render(ErrorState);
    expect(screen.getByRole('alert')).toBeInTheDocument();
    expect(screen.getByText('Gagal memuat data')).toBeInTheDocument();
    await fireEvent.click(screen.getByRole('button', { name: 'Coba lagi' }));
    expect(emitted().retry).toHaveLength(1);
  });

  it('memakai pesan khusus dan dapat menyembunyikan tombol coba lagi', () => {
    render(ErrorState, { props: { message: 'Server tidak merespons', retryable: false } });
    expect(screen.getByText('Server tidak merespons')).toBeInTheDocument();
    expect(screen.queryByRole('button')).toBeNull();
  });
});

describe('EmptyState', () => {
  it('menampilkan pesan, petunjuk, dan tindakan utama lewat slot', () => {
    render(EmptyState, {
      props: { message: 'Belum ada pelanggan', hint: 'Pelanggan dipakai untuk riwayat dan promo.' },
      slots: { default: '<button>Tambah pelanggan</button>' },
    });
    expect(screen.getByText('Belum ada pelanggan')).toBeInTheDocument();
    expect(screen.getByText('Pelanggan dipakai untuk riwayat dan promo.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Tambah pelanggan' })).toBeInTheDocument();
  });
});

describe('StatusPill', () => {
  it.each([
    ['success', 'bg-success-bg'],
    ['info', 'bg-sky-bg'],
    ['warning', 'bg-warn-bg'],
    ['danger', 'bg-danger-bg'],
    ['accent', 'bg-mint-100'],
    ['neutral', 'bg-line-7'],
  ])('nada %s memakai token %s', (variant, cls) => {
    render(StatusPill, { props: { variant }, slots: { default: 'x' } });
    expect(screen.getByText('x')).toHaveClass(cls);
  });

  it('alias lama (mint, dark, warn) tetap berlaku dan nada tak dikenal jatuh ke netral', () => {
    const { rerender } = render(StatusPill, { props: { variant: 'warn' }, slots: { default: 'y' } });
    expect(screen.getByText('y')).toHaveClass('bg-warn-bg');
    rerender({ variant: 'tidak-ada' });
    return Promise.resolve().then(() => expect(screen.getByText('y')).toHaveClass('bg-line-7'));
  });
});
