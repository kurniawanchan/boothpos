/**
 * 059 — satu tempat untuk memetakan status persetujuan ke kunci teks, varian pil, dan ikon, dipakai
 * bagian Approval di detail, ikon di daftar Sales, dan popover. Status SELALU datang dari server
 * (`pending|partial|full|voided`); SPA tidak pernah menghitung ulang status dari catatan seller.
 */
export const APPROVAL_STATUSES = ['pending', 'partial', 'full', 'voided'];

const META = {
  pending: { key: 'approvals.status_pending', pill: 'neutral', icon: 'ph-clock', tone: 'text-muted-3' },
  partial: { key: 'approvals.status_partial', pill: 'warn', icon: 'ph-circle-half', tone: 'text-warn-text' },
  full: { key: 'approvals.status_full', pill: 'mint', icon: 'ph-check-circle', tone: 'text-brand-active' },
  voided: { key: 'approvals.status_voided', pill: 'danger', icon: 'ph-prohibit', tone: 'text-danger-text' },
};

export function approvalMeta(status) {
  return META[status] ?? META.pending;
}

/** Nama metode → kunci teks; metode tak dikenal jatuh ke teks apa adanya agar tak menampilkan kunci mentah. */
export function approvalMethodKey(method) {
  return ['draw', 'upload', 'stamp'].includes(method) ? `approvals.method_${method}` : null;
}
