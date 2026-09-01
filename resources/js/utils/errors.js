/**
 * Central normalization of API errors — every screen consumes this shape
 * instead of poking at raw axios error objects. See the error-handling
 * convention in the project brief: 422 → field errors, 409 → conflict
 * banner/toast, 403 → role denial, 401 → handled entirely in api/client.js.
 */
export class ApiError extends Error {
  constructor(message, { status = null, errors = null, code = null, data = null } = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors; // { field: [messages] } from 422 responses, or null
    this.code = code;
    // Full raw JSON body of the response, when there was one. Most callers
    // only ever need message/status/errors above, but a few endpoints
    // (bulk master-data import) return a richer envelope — sheets,
    // applied, dry_run, ignored_sheets — that doesn't fit the generic
    // shape. Those callers reach for `err.data` instead of re-parsing.
    this.data = data;
  }

  get isValidation() {
    return this.status === 422;
  }

  get isConflict() {
    return this.status === 409;
  }

  get isForbidden() {
    return this.status === 403;
  }

  get isNotFound() {
    return this.status === 404;
  }
}

const FALLBACK_MESSAGE = 'Terjadi kesalahan. Silakan coba lagi.';

export function normalizeAxiosError(err) {
  if (err instanceof ApiError) return err;

  const response = err?.response;
  if (!response) {
    return new ApiError('Tidak dapat terhubung ke server. Periksa koneksi lokal Anda.', {
      status: null,
    });
  }

  const data = response.data || {};
  return new ApiError(data.message || FALLBACK_MESSAGE, {
    status: response.status,
    errors: data.errors || null,
    code: data.code || null,
    data,
  });
}

/** Flatten Laravel-shaped `{field: [msg, ...]}` to `{field: firstMsg}` for simple form binding. */
export function firstFieldErrors(apiError) {
  if (!apiError?.errors) return {};
  return Object.fromEntries(
    Object.entries(apiError.errors).map(([field, messages]) => [field, messages[0]])
  );
}
