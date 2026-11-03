// 090 / US6 — penerjemah status pesanan mandiri → label dan nada StatusPill di sisi staf. Satu tempat untuk panel dan laci.

export const STATUS = {
  awaiting_verification: { label: 'self_service_staff.status_awaiting_verification', variant: 'warning' },
  verified: { label: 'self_service_staff.status_verified', variant: 'info' },
  dispatched: { label: 'self_service_staff.status_dispatched', variant: 'success' },
  rejected: { label: 'self_service_staff.status_rejected', variant: 'danger' },
};

export const statusOf = (status) => STATUS[status] ?? { label: 'self_service_staff.status_unknown', variant: 'neutral' };

/** Pilihan filter status → daftar status yang dikirim ke server (kosong = semua). */
export const STATUS_FILTERS = {
  pending: ['awaiting_verification', 'verified'],
  awaiting_verification: ['awaiting_verification'],
  verified: ['verified'],
  dispatched: ['dispatched'],
  rejected: ['rejected'],
  all: [],
};

/** Pesanan yang masih menunggu tindakan staf (lencana tab). */
export const isPending = (status) => status === 'awaiting_verification' || status === 'verified';
