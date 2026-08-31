import client from './client';

export function listMovements(params = {}) {
  return client.get('/stock/movements', { params }).then((r) => r.data);
}

export function createAdjustment(payload) {
  return client.post('/stock/adjustments', payload).then((r) => r.data);
}

export function lowStock() {
  return client.get('/stock/low').then((r) => r.data);
}
