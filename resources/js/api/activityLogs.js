import client from './client';

export function listActivityLogs(params = {}) {
  return client.get('/activity-logs', { params }).then((r) => r.data);
}
