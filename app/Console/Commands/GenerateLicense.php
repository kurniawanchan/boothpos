<?php

namespace App\Console\Commands;

use App\Mail\LicenseKeyMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * php artisan license:generate {email} {issued_to}
 *
 * CLI vendor-side, TIDAK PERNAH diekspos sebagai layar di produk yang
 * dikirim ke pelanggan (research.md R5) — mencerminkan pemisahan
 * docker/store/package-release.sh (016) antara tooling maintainer dan
 * artefak yang dikirim. Kode perintah ini MEMANG ikut ter-ship di repo
 * yang sama (Laravel ini tidak punya build vendor/customer terpisah),
 * tapi itu AMAN: tanpa LICENSE_SIGNING_PRIVATE_KEY (yang TIDAK PERNAH
 * ada di .env pelanggan manapun) perintah ini gagal total, tidak bisa
 * menghasilkan kunci valid apa pun — hanya kunci PUBLIK yang pernah
 * ikut ter-ship (app/Support/LicenseSigning.php).
 */
class GenerateLicense extends Command
{
    protected $signature = 'license:generate {email} {issued_to}';
    protected $description = 'Buat dan kirim kunci lisensi bertanda tangan ke email client (vendor-side, tidak untuk instalasi pelanggan)';

    public function handle(): int
    {
        $privateKeyBase64 = env('LICENSE_SIGNING_PRIVATE_KEY');
        if (! $privateKeyBase64) {
            $this->error('LICENSE_SIGNING_PRIVATE_KEY tidak diset. Kunci privat vendor HARUS disuplai lewat environment variable lokal Anda sendiri — TIDAK PERNAH lewat .env yang dikirim ke pelanggan.');

            return self::FAILURE;
        }

        $email = $this->argument('email');
        $issuedTo = $this->argument('issued_to');

        $payload = [
            'license_id' => (string) Str::uuid(),
            'issued_to' => $issuedTo,
            'issued_at' => now()->toIso8601String(),
        ];

        $payloadB64 = base64_encode(json_encode($payload));
        $secretKey = base64_decode($privateKeyBase64);
        $signature = sodium_crypto_sign_detached($payloadB64, $secretKey);
        $licenseKey = $payloadB64.'.'.base64_encode($signature);

        // Selalu dicetak ke konsol, terlepas dari hasil pengiriman email
        // di bawah — vendor tetap punya salinannya meski email gagal
        // terkirim (contracts/api.md's Vendor CLI section).
        $this->info('Kunci lisensi berhasil dibuat:');
        $this->line($licenseKey);

        try {
            Mail::to($email)->send(new LicenseKeyMail($issuedTo, $licenseKey));

            if (config('mail.default') === 'log') {
                $this->warn("mail.default belum dikonfigurasi (\"log\") — email HANYA dicatat ke storage/logs/laravel.log, tidak benar-benar terkirim ke {$email}.");
            } else {
                $this->info("Email terkirim ke {$email}.");
            }
        } catch (\Throwable $e) {
            $this->error("Gagal mengirim email ke {$email}: {$e->getMessage()}");
            $this->warn('Kunci lisensi di atas tetap valid — sampaikan secara manual bila perlu.');
        }

        return self::SUCCESS;
    }
}
