// 049 — aksen warna per penjual pada PDF katalog. Hanya pasangan token yang sudah ada di @theme
// (mint/sky/violet/warn/danger), tanpa hex mentah. Nama kelas ditulis utuh (bukan dirakit dari string)
// supaya Tailwind mendeteksinya. Barcode TIDAK memakai aksen: ia selalu gelap di atas putih.
export const ACCENTS = [
  { key: 'mint', soft: 'bg-mint-100', text: 'text-brand-active', chip: 'bg-mint-100 text-brand-active', ring: 'border-mint-border' },
  { key: 'sky', soft: 'bg-sky-bg', text: 'text-sky-text', chip: 'bg-sky-bg text-sky-text', ring: 'border-sky-bg' },
  { key: 'violet', soft: 'bg-violet-bg', text: 'text-violet-text', chip: 'bg-violet-bg text-violet-text', ring: 'border-violet-bg' },
  { key: 'warn', soft: 'bg-warn-bg', text: 'text-warn-text', chip: 'bg-warn-bg text-warn-text', ring: 'border-warn-border' },
  { key: 'danger', soft: 'bg-danger-bg', text: 'text-danger-text', chip: 'bg-danger-bg text-danger-text', ring: 'border-danger-border' },
];

export function accentAt(index) {
  return ACCENTS[((index % ACCENTS.length) + ACCENTS.length) % ACCENTS.length];
}
