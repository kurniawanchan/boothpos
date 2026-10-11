// 043 — gaya kontrol formulir item (rata, garis tipis, tinggi 36px) dipakai bersama oleh laci dan tabel-tabelnya.
// Hanya kelas token Tailwind (tanpa hex). Select sengaja memakai tampilan bawaan browser ("native"), sesuai desain.
export const controlClass =
  'h-9 w-full border bg-white px-3 text-[13px] text-ink outline-none transition-colors placeholder:text-muted-3 focus:border-brand focus:ring-2 focus:ring-mint-100 disabled:bg-line-5 disabled:text-muted-3';
export const textareaClass =
  'min-h-[64px] w-full resize-y border bg-white px-3 py-2 text-[13px] text-ink outline-none transition-colors placeholder:text-muted-3 focus:border-brand focus:ring-2 focus:ring-mint-100';
export const borderOf = (error) => (error ? 'border-danger-border' : 'border-line');

// Sel tabel: input tanpa bingkai sendiri (bingkainya garis sel), sorotan lembut saat fokus.
export const cellInputClass =
  'h-10 w-full bg-transparent px-3 text-[13px] text-ink outline-none placeholder:text-muted-3 focus:bg-mint-50 disabled:text-muted-3';
