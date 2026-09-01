import client from './client';

// Filenames match exactly what GET /exports/{entity} sends server-side
// (PRD 7.15) — kept here rather than trusting Content-Disposition parsing.
const EXPORT_FILENAMES = {
  artists: 'data-artist.xlsx',
  categories: 'data-kategori.xlsx',
  products: 'data-produk.xlsx',
  stock: 'data-stok.xlsx',
};

function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

/**
 * GET /exports/{entity} — entity ∈ artists|categories|products|stock.
 * Same blob-download pattern as `exportReport` in api/reports.js. Uses the
 * same column headers the import accepts, so export → edit → re-upload
 * round-trips through the same file.
 */
export async function exportMasterData(entity) {
  const response = await client.get(`/exports/${entity}`, { responseType: 'blob' });
  downloadBlob(response.data, EXPORT_FILENAMES[entity] ?? `data-${entity}.xlsx`);
}

/** GET /imports/master-data/template — 4 sheets, headers + one example row each, importable as-is. */
export async function downloadImportTemplate() {
  const response = await client.get('/imports/master-data/template', { responseType: 'blob' });
  downloadBlob(response.data, 'template-impor-master-data.xlsx');
}

/**
 * POST /imports/master-data — one workbook, up to four sheets. `dryRun`
 * runs the identical validation path and reports counts without writing
 * anything (F15.4 preview), so callers should always preview before
 * applying for real.
 *
 * `images` is an optional batch of files uploaded alongside the sheet —
 * the products/categories sheets can reference any of them by filename via
 * their `image_filename` column; a referenced filename with no match here
 * comes back as a normal row-level error, not a client-side failure.
 */
export function importMasterData(file, { dryRun = false, images = [] } = {}) {
  const form = new FormData();
  form.append('file', file);
  if (dryRun) form.append('dry_run', '1');
  images.forEach((img) => form.append('images[]', img));
  return client.post('/imports/master-data', form).then((r) => r.data);
}
