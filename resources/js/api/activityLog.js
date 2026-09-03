import client from './client';

/** GET /activity-logs — params: entity_type, entity_id, user_id, action, date_from, date_to, page, per_page. */
export function listActivityLogs(params = {}) {
  return client.get('/activity-logs', { params }).then((r) => r.data);
}
