import client from './client';

/** 045 Stage 2 — outlet yang boleh dilihat pengguna ini: { outlets, selected, can_see_all, multi }. */
export function getScope() {
  return client.get('/me/scope').then((r) => r.data);
}
