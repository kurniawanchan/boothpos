/**
 * Simpan Blob sebagai berkas unduhan lewat tautan sementara. Satu tempat untuk pola
 * createObjectURL → klik <a download> → revoke yang sebelumnya disalin di tiap layar.
 */
export function saveBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
