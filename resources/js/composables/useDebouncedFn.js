export function useDebouncedFn(fn, delay = 300) {
  let timer = null;
  const debounced = (...args) => {
    clearTimeout(timer);
    timer = setTimeout(() => fn(...args), delay);
  };
  // 049: pemanggil yang mengambil alih (mis. pindai barcode di POS menekan Enter) harus bisa membatalkan panggilan yang
  // masih menunggu — kalau tidak, ia menyala SESUDAH Enter dan menimpa hasil pindai. Tambahan murni; pemanggil lama tak terpengaruh.
  debounced.cancel = () => clearTimeout(timer);
  return debounced;
}
