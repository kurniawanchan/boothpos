/**
 * Client-generated UUID used as `local_ref` — the idempotency key BoothPOS
 * sends on every order/checkout POST (openapi-pos-mvp.yaml `OrderInput`).
 * MUST be regenerated per checkout attempt, not reused across a session.
 */
export function uuid() {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) {
    return crypto.randomUUID();
  }
  // Fallback for environments without crypto.randomUUID (older WebViews).
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}
