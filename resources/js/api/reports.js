import client from './client';

export function salesReport(params = {}) {
  return client.get('/reports/sales', { params }).then((r) => r.data);
}

export function profitReport(eventId) {
  return client.get('/reports/profit', { params: { event_id: eventId } }).then((r) => r.data);
}

export function artistSettlements(eventId) {
  return client
    .get('/reports/artist-settlements', { params: { event_id: eventId } })
    .then((r) => r.data);
}

export function recordSettlementPayment(settlementId, payload) {
  return client
    .post(`/reports/artist-settlements/${settlementId}/payment`, payload)
    .then((r) => r.data);
}

/**
 * report is one of: sales | profit | artist-settlements. Downloaded via
 * axios (not a plain <a href>) because the export endpoint requires the
 * same Bearer token as every other request — a bare link would hit it
 * unauthenticated and get a 401.
 */
export async function exportReport(report, params = {}) {
  const response = await client.get(`/reports/${report}/export`, { params, responseType: 'blob' });
  const url = URL.createObjectURL(response.data);
  const link = document.createElement('a');
  link.href = url;
  link.download = `${report}.xlsx`;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}
