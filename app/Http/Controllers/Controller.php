<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * BUG YANG DITEMUKAN & DIPERBAIKI saat bootstrap Laravel 13: skeleton
 * `laravel/laravel` sejak Laravel 11 menghasilkan base Controller KOSONG
 * (tanpa trait apa pun) — bawaan lama yang menyertakan AuthorizesRequests
 * sudah dihapus dari stub resmi. Controller-controller hasil sesi
 * sebelumnya (ArtistController, CategoryController, ProductController,
 * EventController, PaymentProofController) ditulis memakai
 * `$this->authorize(...)`, method yang HANYA ada lewat trait ini. Tanpa
 * baris ini, seluruh controller tersebut fatal error "Call to undefined
 * method" saat endpoint pertama kali diakses — persis jenis galat
 * "asumsi versi Laravel" yang diperkirakan README, bukan bug logika
 * bisnis. Ditambal di sini (bukan mengubah tiap controller) karena ini
 * genap tempatnya menurut konvensi Laravel sendiri.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
