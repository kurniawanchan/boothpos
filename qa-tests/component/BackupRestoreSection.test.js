import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/vue';
import BackupRestoreSection from '../../resources/js/components/settings/BackupRestoreSection.vue';
import { listBackups, createBackup, downloadBackup, restoreBackup, restoreFromUpload, deleteBackup } from '../../resources/js/api/backups';
import { reloadApp } from '../../resources/js/utils/reloadApp';

vi.mock('../../resources/js/api/backups', () => ({
  listBackups: vi.fn(),
  createBackup: vi.fn(),
  downloadBackup: vi.fn(),
  restoreBackup: vi.fn(),
  restoreFromUpload: vi.fn(),
  deleteBackup: vi.fn(),
}));

const toast = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }));
vi.mock('../../resources/js/stores/toast', () => ({ useToastStore: () => toast }));
vi.mock('../../resources/js/utils/reloadApp', () => ({ reloadApp: vi.fn() }));

const BACKUPS = [
  { id: '2026-09-30_100000', created_at: '2026-09-30T03:00:00Z', size_bytes: 2048, has_payment_proofs: true },
  { id: '2026-09-29_090000', created_at: '2026-09-29T02:00:00Z', size_bytes: 3145728, has_payment_proofs: false },
];

async function setup() {
  const { default: userEvent } = await import('@testing-library/user-event');
  const user = userEvent.setup();
  render(BackupRestoreSection);
  return user;
}

/** Buka dialog pemulihan dari baris cadangan ke-`index`. */
async function openRestoreDialog(user, index = 0) {
  await screen.findByText(BACKUPS[0].id);
  await user.click(screen.getAllByRole('button', { name: 'Pulihkan' })[index]);

  return screen.findByRole('dialog', { name: 'Pulihkan database?' });
}

describe('BackupRestoreSection', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listBackups.mockResolvedValue({ data: BACKUPS });
  });

  it('lists the backups with a readable size and marks the ones that include payment proofs', async () => {
    await setup();

    expect(await screen.findByText('2026-09-30_100000')).toBeInTheDocument();
    expect(screen.getByText('2.0 KB')).toBeInTheDocument();
    expect(screen.getByText('3.0 MB')).toBeInTheDocument();
    expect(screen.getAllByText('Termasuk bukti pembayaran')).toHaveLength(1);
  });

  it('says so when there are no backups yet', async () => {
    listBackups.mockResolvedValue({ data: [] });
    await setup();

    expect(await screen.findByText('Belum ada cadangan.')).toBeInTheDocument();
  });

  it('creates a backup on demand and refreshes the list', async () => {
    createBackup.mockResolvedValue({ id: '2026-09-30_110000' });
    const user = await setup();
    await screen.findByText(BACKUPS[0].id);

    await user.click(screen.getByRole('button', { name: 'Buat cadangan sekarang' }));

    await waitFor(() => expect(createBackup).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(toast.success).toHaveBeenCalledWith('Cadangan berhasil dibuat.'));
    expect(listBackups).toHaveBeenCalledTimes(2);
  });

  it('shows the server message when creating a backup fails', async () => {
    createBackup.mockRejectedValue(new Error('mysqldump gagal'));
    const user = await setup();
    await screen.findByText(BACKUPS[0].id);

    await user.click(screen.getByRole('button', { name: 'Buat cadangan sekarang' }));

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('mysqldump gagal'));
  });

  it('downloads a backup as a .sql file named after its id', async () => {
    const blob = new Blob(['-- dump'], { type: 'application/sql' });
    downloadBackup.mockResolvedValue(blob);
    URL.createObjectURL = vi.fn(() => 'blob:fake');
    URL.revokeObjectURL = vi.fn();
    const clicked = [];
    const original = HTMLAnchorElement.prototype.click;
    HTMLAnchorElement.prototype.click = function click() { clicked.push(this.download); };
    const user = await setup();
    await screen.findByText(BACKUPS[0].id);

    try {
      await user.click(screen.getAllByRole('button', { name: 'Unduh' })[0]);
      await waitFor(() => expect(downloadBackup).toHaveBeenCalledWith('2026-09-30_100000'));
      await waitFor(() => expect(clicked).toEqual(['boothpos-backup-2026-09-30_100000.sql']));
      expect(URL.createObjectURL).toHaveBeenCalledWith(blob);
    } finally {
      HTMLAnchorElement.prototype.click = original;
    }
  });

  it('will not restore until the exact word RESTORE is typed', async () => {
    restoreBackup.mockResolvedValue({ source: BACKUPS[0].id, safety_backup: { id: '2026-09-30_120000' } });
    const user = await setup();
    const dialog = await openRestoreDialog(user);
    const confirm = within(dialog).getByRole('button', { name: 'Pulihkan sekarang' });
    const input = within(dialog).getByLabelText(/Ketik RESTORE/);

    expect(confirm).toBeDisabled();
    await user.type(input, 'restore'); // huruf kecil = salah
    expect(confirm).toBeDisabled();

    await user.clear(input);
    await user.type(input, 'RESTORE');
    expect(confirm).toBeEnabled();

    await user.click(confirm);

    await waitFor(() => expect(restoreBackup).toHaveBeenCalledWith('2026-09-30_100000'));
    await waitFor(() => expect(reloadApp).toHaveBeenCalledTimes(1));
    expect(toast.success).toHaveBeenCalledWith(expect.stringContaining('2026-09-30_120000')); // id cadangan pengaman
  });

  it('warns that everything is overwritten and that a safety backup is taken first', async () => {
    const user = await setup();
    const dialog = await openRestoreDialog(user);

    expect(within(dialog).getByText(/MENIMPA SELURUH data/)).toBeInTheDocument();
    expect(within(dialog).getByText(/Cadangan otomatis/)).toBeInTheDocument();
    expect(within(dialog).getByText(/2026-09-30_100000/)).toBeInTheDocument(); // sumber yang dipilih
  });

  it('warns that the licence activation is restored too and the key may be needed again', async () => {
    // Pulihan mengganti SELURUH database, termasuk license_activations yang terikat
    // ke sidik jari mesin — cadangan dari perangkat lain mengunci aplikasi (423).
    const user = await setup();
    const dialog = await openRestoreDialog(user);

    const warning = within(dialog).getByTestId('restore-license-warning');
    expect(warning).toHaveTextContent(/perangkat lain/);
    expect(warning).toHaveTextContent(/kunci lisensi/);
  });

  it('cancelling closes the dialog and restores nothing', async () => {
    const user = await setup();
    const dialog = await openRestoreDialog(user);

    await user.click(within(dialog).getByRole('button', { name: 'Batal' }));

    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Pulihkan database?' })).not.toBeInTheDocument());
    expect(restoreBackup).not.toHaveBeenCalled();
    expect(reloadApp).not.toHaveBeenCalled();
  });

  it('keeps the dialog open and shows the reason when the restore fails, without reloading', async () => {
    restoreBackup.mockRejectedValue(new Error('Cadangan pengaman gagal dibuat, pemulihan dibatalkan'));
    const user = await setup();
    const dialog = await openRestoreDialog(user);
    await user.type(within(dialog).getByLabelText(/Ketik RESTORE/), 'RESTORE');

    await user.click(within(dialog).getByRole('button', { name: 'Pulihkan sekarang' }));

    expect(await within(dialog).findByRole('alert')).toHaveTextContent('pemulihan dibatalkan');
    expect(reloadApp).not.toHaveBeenCalled();
    expect(screen.getByRole('dialog', { name: 'Pulihkan database?' })).toBeInTheDocument();
  });

  it('restores from an uploaded .sql file after the same confirmation', async () => {
    restoreFromUpload.mockResolvedValue({ source: 'upload', safety_backup: { id: '2026-09-30_130000' } });
    const user = await setup();
    await screen.findByText(BACKUPS[0].id);
    const file = new File(['-- dump'], 'cadangan-laptop-lain.sql', { type: 'application/sql' });

    await user.upload(screen.getByLabelText('Pilih berkas .sql'), file);
    await user.click(screen.getByRole('button', { name: 'Pulihkan dari berkas' }));
    const dialog = await screen.findByRole('dialog', { name: 'Pulihkan database?' });
    expect(within(dialog).getByText(/cadangan-laptop-lain\.sql/)).toBeInTheDocument();
    await user.type(within(dialog).getByLabelText(/Ketik RESTORE/), 'RESTORE');
    await user.click(within(dialog).getByRole('button', { name: 'Pulihkan sekarang' }));

    await waitFor(() => expect(restoreFromUpload).toHaveBeenCalledWith(file));
    await waitFor(() => expect(reloadApp).toHaveBeenCalledTimes(1));
    expect(restoreBackup).not.toHaveBeenCalled();
  });

  it('cannot restore from a file until one is chosen', async () => {
    await setup();
    await screen.findByText(BACKUPS[0].id);

    expect(screen.getByRole('button', { name: 'Pulihkan dari berkas' })).toBeDisabled();
  });

  it('shows the file-specific message when the upload is rejected as not a valid dump', async () => {
    const err = Object.assign(new Error('The given data was invalid.'), { errors: { file: ['Berkas ini bukan cadangan aplikasi ini.'] } });
    restoreFromUpload.mockRejectedValue(err);
    const user = await setup();
    await screen.findByText(BACKUPS[0].id);
    await user.upload(screen.getByLabelText('Pilih berkas .sql'), new File(['x'], 'salah.sql'));
    await user.click(screen.getByRole('button', { name: 'Pulihkan dari berkas' }));
    const dialog = await screen.findByRole('dialog', { name: 'Pulihkan database?' });
    await user.type(within(dialog).getByLabelText(/Ketik RESTORE/), 'RESTORE');

    await user.click(within(dialog).getByRole('button', { name: 'Pulihkan sekarang' }));

    expect(await within(dialog).findByRole('alert')).toHaveTextContent('bukan cadangan aplikasi ini');
    expect(reloadApp).not.toHaveBeenCalled();
  });

  // --- hapus ----------------------------------------------------------------

  async function openDeleteDialog(user, index = 0) {
    await screen.findByText(BACKUPS[0].id);
    await user.click(screen.getAllByRole('button', { name: 'Hapus' })[index]);

    return screen.findByRole('dialog', { name: 'Hapus cadangan?' });
  }

  it('asks before deleting, naming the backup and warning the delete is permanent', async () => {
    const user = await setup();
    const dialog = await openDeleteDialog(user);

    expect(within(dialog).getByText(/2026-09-30_100000/)).toBeInTheDocument();
    expect(within(dialog).getByText(/permanen/)).toBeInTheDocument();
    expect(deleteBackup).not.toHaveBeenCalled();
  });

  it('deletes the chosen backup after confirmation and refreshes the list', async () => {
    deleteBackup.mockResolvedValue(undefined);
    const user = await setup();
    const dialog = await openDeleteDialog(user, 1); // cadangan KEDUA

    await user.click(within(dialog).getByRole('button', { name: 'Hapus' }));

    await waitFor(() => expect(deleteBackup).toHaveBeenCalledWith('2026-09-29_090000'));
    await waitFor(() => expect(toast.success).toHaveBeenCalledWith('Cadangan dihapus.'));
    expect(listBackups).toHaveBeenCalledTimes(2);
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Hapus cadangan?' })).not.toBeInTheDocument());
    expect(restoreBackup).not.toHaveBeenCalled(); // menghapus bukan memulihkan
  });

  it('cancelling the delete dialog deletes nothing', async () => {
    const user = await setup();
    const dialog = await openDeleteDialog(user);

    await user.click(within(dialog).getByRole('button', { name: 'Batal' }));

    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Hapus cadangan?' })).not.toBeInTheDocument());
    expect(deleteBackup).not.toHaveBeenCalled();
    expect(listBackups).toHaveBeenCalledTimes(1);
  });

  it('shows the server message when deleting fails and does not claim success', async () => {
    deleteBackup.mockRejectedValue(new Error('Cadangan tidak ditemukan.'));
    const user = await setup();
    const dialog = await openDeleteDialog(user);

    await user.click(within(dialog).getByRole('button', { name: 'Hapus' }));

    await waitFor(() => expect(toast.error).toHaveBeenCalledWith('Cadangan tidak ditemukan.'));
    expect(toast.success).not.toHaveBeenCalled();
  });
});
