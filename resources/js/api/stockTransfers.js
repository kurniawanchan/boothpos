import client from './client';

/** 045 Stage 6 — transfer stok antar outlet (contracts/api-stock-transfers.md). */
export const listTransfers = (params = {}) => client.get('/stock/transfers', { params }).then((r) => r.data);
export const getTransfer = (id) => client.get(`/stock/transfers/${id}`).then((r) => r.data);
export const createTransfer = (payload) => client.post('/stock/transfers', payload).then((r) => r.data);
export const receiveTransfer = (id, payload = {}) => client.post(`/stock/transfers/${id}/receive`, payload).then((r) => r.data);
export const cancelTransfer = (id) => client.post(`/stock/transfers/${id}/cancel`).then((r) => r.data);
export const listDiscrepancies = () => client.get('/stock/transfers/discrepancies').then((r) => r.data);
export const listInTransit = (params = {}) => client.get('/stock/in-transit', { params }).then((r) => r.data);
