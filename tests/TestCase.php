<?php

namespace Tests;

use App\Models\LicenseActivation;
use App\Support\MachineFingerprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

abstract class TestCase extends BaseTestCase
{
    // BUG YANG DITEMUKAN & DIPERBAIKI (015-dockerize-dev-environment) —
    // ditemukan lewat `php artisan test` di dalam container Docker: 10
    // test gagal di sana padahal 424/424 selalu hijau secara native di
    // mesin yang sama. Root cause-nya BUKAN Docker itu sendiri — seluruh
    // suite test berjalan dalam SATU proses PHP (PHPUnit tidak fork per
    // test), dan driver cache `array` di .env.testing hanyalah array PHP
    // statis yang hidup selama proses itu, jadi nilai yang di-cache satu
    // test (mis. Setting::get('system_mode') via ModeGate) bisa bocor ke
    // test lain yang tidak pernah menyentuh setting itu sendiri —
    // RefreshDatabase mereset DATABASE, bukan cache. Ini SUDAH ada
    // sebelum fitur ini, hanya baru kelihatan sekarang karena urutan
    // discovery test PHPUnit (tidak dijamin sama di semua filesystem)
    // berbeda antara APFS native dan filesystem container Linux,
    // sehingga urutan test yang berbeda mengekspos ketergantungan urutan
    // yang sebelumnya diam-diam lolos. Cache::flush() di sini murni
    // kebersihan test — tidak mengubah kode produksi sama sekali.
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // BUG YANG DITEMUKAN & DIPERBAIKI (018-license-activation) —
        // terverifikasi lewat `php artisan test` sungguhan: menambahkan
        // gerbang EnsureInstallationIsActivated GLOBAL ke seluruh grup
        // middleware 'api' membuat 364 dari ~430 test lama gagal dengan
        // 423, karena tidak satu pun test itu pernah mengaktivasi
        // instalasinya lebih dulu — mereka semua ditulis sebelum fitur
        // ini ada, dan tidak seharusnya perlu tahu tentang lisensi sama
        // sekali. Baris ini membuat setiap test SECARA DEFAULT berjalan
        // di instalasi yang sudah teraktivasi (fingerprint mesin yang
        // menjalankan test itu sendiri), persis seperti keadaan
        // sungguhan setelah operator mengaktivasi sekali. Test yang
        // MEMANG menguji perilaku gerbang lisensi itu sendiri
        // (LicenseActivationTest) meng-override setUp()-nya sendiri
        // untuk menghapus baris ini lagi, supaya bisa menguji dari
        // keadaan belum teraktivasi.
        LicenseActivation::query()->create([
            'license_id' => 'test-suite',
            'issued_to' => 'Test Suite',
            'machine_fingerprint_hash' => Hash::make(MachineFingerprint::current()),
            'activated_at' => now(),
        ]);
    }
}
