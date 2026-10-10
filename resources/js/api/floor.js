import client from './client';

/**
 * GET /floor. Bila `version` dikirim dan lantai tidak berubah, server membalas
 * kerangka kecil `{ unchanged: true, version }` — pemanggil (stores/floor.js)
 * yang memutuskan untuk tidak merender ulang.
 */
export function getFloor(version = null) {
  return client.get('/floor', { params: version ? { version } : {} }).then((r) => r.data);
}
