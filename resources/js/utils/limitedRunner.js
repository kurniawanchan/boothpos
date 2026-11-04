/**
 * 056 (US7) — membatasi berapa pemanggilan async berjalan bersamaan (antrean FIFO). Dipakai daftar Sales saat
 * sakelar "tampilkan semua" menyalakan puluhan baris sekaligus: tanpa batas, 100 baris = ratusan permintaan
 * serentak ke server localhost. Hasil/galat tiap pemanggilan diteruskan apa adanya ke pemanggilnya.
 */
export function createLimitedRunner(max = 4) {
  let running = 0;
  const queue = [];

  function next() {
    while (running < max && queue.length > 0) {
      const { fn, resolve, reject } = queue.shift();
      running += 1;
      Promise.resolve()
        .then(fn)
        .then(resolve, reject)
        .finally(() => {
          running -= 1;
          next();
        });
    }
  }

  return (fn) => new Promise((resolve, reject) => {
    queue.push({ fn, resolve, reject });
    next();
  });
}
