<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * 028 — masukan yang ditolak oleh SERVICE karena rujukannya tidak ada/tidak
 * sah (mis. pelanggan dari mode lain, pilihan modifier di luar aturan).
 * Berbeda dari ValidationException biasa dari service — yang, mengikuti
 * kasir ritel, dipetakan ke 409 "konflik aturan bisnis" — ini dirender
 * Laravel apa adanya sebagai 422 galat-per-field.
 */
class InputRejectedException extends ValidationException
{
}
