import client from './client';

export function createShipment(preorderId, payload) {
  return client.post(`/preorders/${preorderId}/shipment`, payload).then((r) => r.data);
}

export function updateShipment(id, payload) {
  return client.patch(`/shipments/${id}`, payload).then((r) => r.data);
}
