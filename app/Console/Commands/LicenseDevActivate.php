<?php

namespace App\Console\Commands;

use App\Models\LicenseActivation;
use App\Support\MachineFingerprint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * php artisan license:dev-activate
 *
 * DEV-ONLY convenience command — menggabungkan tiga langkah berikut
 * menjadi satu perintah untuk keperluan lokal development saja:
 *   1. Generate Ed25519 keypair (dev) + license key yang ditandatanganinya
 *   2. Verifikasi license key lewat LicenseSigning
 *   3. Aktivasi langsung ke database (insert LicenseActivation record)
 *
 * Ini adiluwis untuk dev environment — di production gunakan pola resmi:
 *   php artisan license:generate {email} {issued_to}   # vendor-side
 *   POST /api/v1/license/activate                      # store-side
 *
 * BUKAN untuk instalasi pelanggan sungguhan: kunci privat yang dibuat
 * command ini HANYA valid pada session PHP proses ini sendiri, tidak
 * pernah tersimpan ke disk, sehingga license key hasilnya tidak bisa
 * dipakai lagi setelah proses ini keluar (ini memaksakan perilaku
 * yang aman — tidak bisa dipakai sebagai "crack" ke license gate).
 * Hanya record LicenseActivation di database yang persists, dan itu
 * saja yang dibutuhkan supaya app tidak lagi mengembalikan 423.
 *
 * 018-license-activation, research.md R5: pemisahan tooling maintainer
 * vs artefak yang dikirim ke pelanggan — command ini termasuk tooling.
 */
class LicenseDevActivate extends Command
{
    protected $signature = 'license:dev-activate {--issued-to=Dev User} {--license-id=dev-license}';

    protected $description = 'DEV-ONLY: generate keypair + license key + activate ke database sekaligus (ganti license:generate + activate API)';

    public function handle(): int
    {
        if (config('app.env') !== 'local' && config('app.env') !== 'testing') {
            $this->error('Command ini HANYA untuk environment local/testing. Untuk produksi, gunakan php artisan license:generate + POST /api/v1/license/activate.');

            return self::FAILURE;
        }

        // 1. Generate Ed25519 keypair (hanya ada di memory, tidak disimpan ke disk)
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));
        $privateKey = base64_encode(sodium_crypto_sign_secretkey($keypair));

        $this->line('Public key  : ' . $publicKey);
        $this->line('Private key : ' . $privateKey . ' (memory-only, tidak disimpan ke disk)');

        // 2. Build dan sign license key
        $payload = [
            'license_id' => $this->option('license-id'),
            'issued_to' => $this->option('issued-to'),
            'issued_at' => now()->toIso8601String(),
        ];
        $payloadB64 = base64_encode(json_encode($payload));
        $signature = sodium_crypto_sign_detached($payloadB64, base64_decode($privateKey));
        $licenseKey = $payloadB64 . '.' . base64_encode($signature);

        // 3. Verifikasi via LicenseSigning (menggunakan public key dev default
        //    di config/license.php — kita set sementara sebagai env)
        putenv('LICENSE_PUBLIC_KEY=' . $publicKey);
        config(['license.public_key' => $publicKey]);

        try {
            $decoded = \App\Support\LicenseSigning::decodeAndVerify($licenseKey);
            $this->line('License ID  : ' . $decoded['license_id']);
            $this->line('Issued to   : ' . $decoded['issued_to']);
            $this->info('License key valid dan verified.');
        } catch (\InvalidArgumentException $e) {
            $this->error('License key tidak valid: ' . $e->getMessage());

            return self::FAILURE;
        }

        // 4. Aktivasi ke database — butuh machine fingerprint yang valid
        try {
            $fingerprint = MachineFingerprint::current();
        } catch (\Throwable $e) {
            $this->error('Gagal mendapatkan machine fingerprint: ' . $e->getMessage());
            $this->warn('Di Docker dev container, pastikan /etc/machine-id ada:');
            $this->warn('  docker compose exec app sh -c \'echo "1a2b3c4d5e6f789012345678901234567890" > /etc/machine-id\'');

            return self::FAILURE;
        }

        LicenseActivation::query()->delete();
        LicenseActivation::create([
            'license_id' => $payload['license_id'],
            'issued_to' => $payload['issued_to'],
            'machine_fingerprint_hash' => Hash::make($fingerprint),
            'activated_at' => now(),
        ]);

        $this->info('');
        $this->info('License key  : ' . $licenseKey);
        $this->info('Aktivasi      : berhasil (LicenseActivation record inserted)');
        $this->info('Fingerprint   : ' . $fingerprint);
        $this->info('');
        $this->info('Aplikasi sudah ter-aktfivasi. Anda bisa akses http://localhost:8000');
        $this->info('Untuk share license key ini ke pemasang lain di mesin berbeda,');
        $this->info('pakai: php artisan license:generate {email} {issued_to} (vendor-side).');

        return self::SUCCESS;
    }
}