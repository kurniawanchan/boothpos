<?php

namespace App\Services\CustomerCommerce;

use App\Exceptions\CustomerCommerceException;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Services\ActivityLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Customer commerce (CONTRACT §1, §5) — SATU-satunya tempat yang membuat akun
 * pelanggan, memeriksa kredensialnya, menerbitkan/mencabut tokennya, dan
 * menonaktifkannya. Controller pelanggan hanya memvalidasi, mendelegasikan
 * ke sini, dan membentuk respons.
 *
 * Aturan yang dijaga di sini:
 *  - Registrasi SELALU membuat kontak `customers` baru (tanpa seller) dalam
 *    transaksi dan mode data yang sama dengan akunnya; tidak pernah menempel
 *    ke kontak buatan staf walau e-mailnya sama (orang yang mendaftar belum
 *    terbukti pemilik e-mail itu, dan kontak staf memuat catatan internal).
 *  - Galat registrasi dan login GENERIK: respons tidak boleh bisa dipakai
 *    menebak e-mail mana yang terdaftar (OWASP: user enumeration).
 *  - Token: nama `customer-app`, ability `customer`, kedaluwarsa per token
 *    dari config. `sanctum.expiration` global tidak disentuh (itu juga akan
 *    mengubah token staf).
 *  - Tidak ada e-mail, nama, telepon, kata sandi, atau token yang masuk ke
 *    log aktivitas maupun pesan exception.
 */
class CustomerAccountService
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * @param  array{name: string, email: string, password: string, phone?: string|null}  $data  hasil validasi RegisterRequest
     *
     * @throws CustomerCommerceException 422 `registration_failed` bila e-mail sudah terdaftar di mode data aktif
     */
    public function register(array $data): CustomerAccount
    {
        $email = CustomerAccount::normaliseEmail($data['email']);

        // Di-hash SEBELUM memeriksa "sudah terdaftar?": kalau hash hanya dihitung
        // untuk e-mail baru, lama respons membedakan e-mail terdaftar dari yang belum.
        $passwordHash = Hash::make($data['password']);

        try {
            return DB::transaction(function () use ($data, $email, $passwordHash) {
                // Lewat model (DataModeScope): e-mail unik PER mode, sama seperti indeks (email, data_mode).
                if (CustomerAccount::where('email', $email)->exists()) {
                    throw $this->registrationFailed();
                }

                $customer = Customer::create([
                    'name' => $data['name'],
                    'phone' => $data['phone'] ?? null,
                    'email' => $email,
                    'artist_id' => null,
                ]);

                $account = new CustomerAccount([
                    'customer_id' => $customer->id,
                    'email' => $email,
                    'password' => $passwordHash,
                ]);
                // Mode akun = mode kontaknya, eksplisit: keduanya harus selalu berada di mode yang sama.
                $account->data_mode = $customer->data_mode;
                $account->save();

                $this->activityLogger->log(
                    userId: null, // akun pelanggan bukan pengguna staf; id-nya tidak boleh mengisi kolom user_id
                    action: 'customer_account_registered',
                    entityType: 'CustomerAccount',
                    entityId: $account->id,
                    description: 'Akun pelanggan baru terdaftar lewat aplikasi.',
                );

                return $account->setRelation('customer', $customer);
            });
        } catch (UniqueConstraintViolationException) {
            // Dua pendaftaran e-mail yang sama berbalapan: indeks unik adalah jaring
            // terakhir. Hasilnya harus galat generik yang sama, bukan 500.
            throw $this->registrationFailed();
        }
    }

    /**
     * Memeriksa kredensial dan mencap `last_login_at`.
     *
     * @throws CustomerCommerceException 401 `invalid_credentials`: SAMA persis untuk e-mail tidak dikenal,
     *                                   kata sandi salah, dan akun dinonaktifkan
     */
    public function authenticate(string $email, string $password): CustomerAccount
    {
        // Lewat model (DataModeScope): akun DEMO tidak bisa masuk saat toko LIVE, dan sebaliknya.
        // E-mail non-ASCII TIDAK PERNAH dikirim ke pencarian: kolasi basis data menyamakannya dengan
        // padanan ASCII-nya (lihat CustomerAccount::isAsciiEmail), jadi ia diperlakukan persis seperti
        // e-mail tak dikenal (hash tiruan dan galat 401 yang sama di bawah).
        $normalised = CustomerAccount::normaliseEmail($email);
        $account = CustomerAccount::isAsciiEmail($normalised)
            ? CustomerAccount::where('email', $normalised)->first()
            : null;

        if ($account === null) {
            // Pemeriksaan hash tiruan: satu komputasi hash dengan cost yang berlaku, setara
            // Hash::check() pada akun sungguhan, supaya lama respons tidak membedakan
            // "e-mail tidak dikenal" dari "kata sandi salah". Hasilnya dibuang.
            Hash::make($password);

            throw $this->invalidCredentials();
        }

        if (! Hash::check($password, $account->password) || $account->isDisabled() || $account->customer === null) {
            throw $this->invalidCredentials();
        }

        $account->forceFill(['last_login_at' => now()])->save();

        // Token kedaluwarsa akun ini tidak berguna lagi; dibuang supaya tabel token tidak tumbuh tanpa batas.
        $account->tokens()->where('expires_at', '<', now())->delete();

        return $account;
    }

    /** Token aplikasi pelanggan: nama, ability, dan kedaluwarsa per token (CONTRACT §1). */
    public function issueToken(CustomerAccount $account): NewAccessToken
    {
        return $account->createToken(
            CustomerAccount::TOKEN_NAME,
            [CustomerAccount::TOKEN_ABILITY],
            now()->addMinutes((int) config('customer_commerce.token_ttl_minutes')),
        );
    }

    /** Logout: HANYA token yang dipakai permintaan ini; perangkat lain tetap masuk. */
    public function revokeCurrentToken(CustomerAccount $account): void
    {
        $token = $account->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }

    /**
     * Nama dan telepon disimpan di kontak `customers` milik akun (satu sumber
     * untuk staf dan pelanggan). E-mail tidak bisa diubah di v1: ia identitas
     * login dan belum ada alur verifikasi e-mail.
     *
     * @param  array{name: string, phone?: string|null}  $data  hasil validasi UpdateProfileRequest
     */
    public function updateProfile(CustomerAccount $account, array $data): CustomerAccount
    {
        return DB::transaction(function () use ($account, $data) {
            $changes = ['name' => $data['name']];

            // `phone` yang tidak dikirim dibiarkan; `null` eksplisit mengosongkannya.
            if (array_key_exists('phone', $data)) {
                $changes['phone'] = $data['phone'];
            }

            $account->customer->update($changes);

            return $account;
        });
    }

    /**
     * Menonaktifkan akun dan mencabut SEMUA tokennya dalam satu transaksi,
     * supaya akses terputus saat itu juga (EnsureCustomerAccount tetap
     * memeriksa `disabled_at` di setiap permintaan sebagai lapis kedua).
     * Aman dipanggil ulang: tanggal penonaktifan pertama dipertahankan.
     */
    public function disable(CustomerAccount $account, ?int $actorUserId = null): CustomerAccount
    {
        return DB::transaction(function () use ($account, $actorUserId) {
            $alreadyDisabled = $account->isDisabled();

            if (! $alreadyDisabled) {
                $account->forceFill(['disabled_at' => now()])->save();
            }

            $account->tokens()->delete();

            if (! $alreadyDisabled) {
                $this->activityLogger->log(
                    userId: $actorUserId,
                    action: 'customer_account_disabled',
                    entityType: 'CustomerAccount',
                    entityId: $account->id,
                    description: 'Akun pelanggan dinonaktifkan; seluruh tokennya dicabut.',
                );
            }

            return $account;
        });
    }

    private function registrationFailed(): CustomerCommerceException
    {
        return CustomerCommerceException::make('registration_failed', 422);
    }

    private function invalidCredentials(): CustomerCommerceException
    {
        return CustomerCommerceException::make('invalid_credentials', 401);
    }
}
