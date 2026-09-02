import '@testing-library/jest-dom/vitest';
import { afterEach } from 'vitest';
import { cleanup } from '@testing-library/vue';

// BUG YANG DITEMUKAN & DIPERBAIKI — tidak ada cleanup() di antar-test sama
// sekali sebelum ini. @testing-library/vue TIDAK auto-unmount komponen
// setelah tiap test seperti sebagian framework lain; tanpa afterEach ini,
// setiap render() dalam SATU file test menumpuk pohon komponen Vue yang
// masih ter-mount di document.body yang sama, membuat `screen` (yang
// terikat ke seluruh document.body) kadang mencocokkan elemen dari test
// SEBELUMNYA yang seharusnya sudah selesai — inilah penyebab flake nyata
// yang ditemukan di RolesView.test.js (createRole tidak pernah dipanggil
// karena `editingRole` milik instance komponen test sebelumnya yang masih
// hidup ikut ter-render ulang). Ini bug tingkat proyek, bukan spesifik
// satu file test — mempengaruhi setiap file di qa-tests/component/.
afterEach(() => {
  cleanup();
});
