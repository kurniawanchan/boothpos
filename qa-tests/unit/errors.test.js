import { describe, it, expect } from 'vitest';
import { ApiError, normalizeAxiosError, firstFieldErrors } from '../../resources/js/utils/errors';

describe('normalizeAxiosError', () => {
  it('maps a 422 response into a validation ApiError with field errors', () => {
    const axiosError = {
      response: {
        status: 422,
        data: { message: 'Validasi gagal', errors: { username: ['Wajib diisi'] } },
      },
    };
    const err = normalizeAxiosError(axiosError);
    expect(err).toBeInstanceOf(ApiError);
    expect(err.status).toBe(422);
    expect(err.isValidation).toBe(true);
    expect(err.errors).toEqual({ username: ['Wajib diisi'] });
  });

  it('flags a 409 response as a conflict', () => {
    const err = normalizeAxiosError({ response: { status: 409, data: { message: 'Stok tidak cukup' } } });
    expect(err.isConflict).toBe(true);
    expect(err.message).toBe('Stok tidak cukup');
  });

  it('flags a 403 response as forbidden', () => {
    const err = normalizeAxiosError({ response: { status: 403, data: { message: 'Tidak berhak.' } } });
    expect(err.isForbidden).toBe(true);
  });

  it('produces a network-error message when there is no response at all', () => {
    const err = normalizeAxiosError({ response: undefined });
    expect(err.status).toBeNull();
    expect(err.message).toMatch(/tidak dapat terhubung/i);
  });

  it('passes an already-normalized ApiError through unchanged', () => {
    const original = new ApiError('sudah dinormalisasi', { status: 500 });
    expect(normalizeAxiosError(original)).toBe(original);
  });

  it('carries the full raw response body on `.data` for callers needing more than message/errors', () => {
    const body = {
      message: 'Impor dibatalkan: ada baris yang tidak valid. Tidak ada data yang diubah.',
      applied: false,
      dry_run: false,
      sheets: { artists: { rows: 2, created: 1, updated: 1, unchanged: 0 } },
      ignored_sheets: [],
      errors: [{ sheet: 'products', row: 12, column: 'category_code', message: "Kategori dengan kode 'ZZ' tidak ditemukan." }],
    };
    const err = normalizeAxiosError({ response: { status: 422, data: body } });
    expect(err.data).toEqual(body);
    expect(Array.isArray(err.errors)).toBe(true);
  });
});

describe('firstFieldErrors', () => {
  it('flattens Laravel-shaped field errors to a single message per field', () => {
    const err = new ApiError('Validasi gagal', { status: 422, errors: { name: ['Wajib diisi', 'Terlalu pendek'] } });
    expect(firstFieldErrors(err)).toEqual({ name: 'Wajib diisi' });
  });

  it('returns an empty object when there are no field errors', () => {
    const err = new ApiError('Konflik', { status: 409 });
    expect(firstFieldErrors(err)).toEqual({});
  });
});
