import client from './client';

/**
 * GET /stations/{id}/tickets. Bila `version` dikirim dan tidak ada perubahan, server
 * membalas `{ unchanged: true, version }` — pemanggil (stores/kitchen.js) tidak merender ulang.
 */
export function getStationTickets(stationId, version = null) {
  return client.get(`/stations/${stationId}/tickets`, { params: version ? { version } : {} }).then((r) => r.data);
}

/** Satu tiket UTUH (semua item) untuk tampilan cetak. */
export function getTicket(id) {
  return client.get(`/tickets/${id}`).then((r) => r.data);
}

/** status: 'preparing' | 'ready' | 'served' — hanya maju; pembatalan lewat DELETE baris tab. */
export function setTicketItemStatus(itemId, status) {
  return client.patch(`/ticket-items/${itemId}/status`, { status }).then((r) => r.data);
}
