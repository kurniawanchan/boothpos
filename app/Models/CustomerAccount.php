<?php

namespace App\Models;

use App\Models\Concerns\HasDataMode;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Customer commerce — identitas pelanggan untuk aplikasi Android: subjek token
 * Sanctum yang TERPISAH dari `users` (staf) dan satu-ke-satu dengan
 * `customers` (kontak yang dikelola staf, tidak bisa masuk).
 *
 * Batas keamanan bergantung pada KELAS model ini: guard `customer` hanya
 * menerima token milik CustomerAccount dan guard staf hanya milik User.
 * Jangan pernah menjadikan kelas ini turunan User atau sebaliknya.
 *
 * HasDataMode dipasang SENGAJA (berbeda dari User, yang tidak memakai scope
 * agar login staf berfungsi di kedua mode): akun pelanggan adalah data
 * bisnis milik satu mode, jadi token yang dibuat di LIVE berhenti dikenali
 * begitu toko dipindah ke DEMO, dan sebaliknya. Baris `customers`-nya harus
 * dibuat di mode yang sama, dalam transaksi yang sama.
 *
 * Tanpa HasSellerScope: SellerScope melempar LogicException untuk tabel yang
 * tidak punya aturan, dan akun pelanggan bukan milik seller mana pun.
 *
 * Data pribadi: `email` (dan nama/telepon di `customers`) tidak boleh masuk
 * log, activity log, atau pesan galat.
 */
class CustomerAccount extends Authenticatable
{
    use HasApiTokens, HasDataMode, HasFactory;

    /** Nama token dan ability yang diterbitkan login/registrasi pelanggan. */
    public const TOKEN_NAME = 'customer-app';

    public const TOKEN_ABILITY = 'customer';

    protected $fillable = [
        'customer_id',
        'email',
        'password',
        'email_verified_at',
        'disabled_at',
        'last_login_at',
    ];

    protected $hidden = ['password'];

    /**
     * Tidak ada kolom `remember_token`: akun ini hanya dipakai lewat token
     * API, tidak pernah lewat sesi "ingat saya".
     */
    protected $rememberTokenName = '';

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'disabled_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * E-mail SELALU disimpan sudah di-trim dan huruf kecil, apa pun jalurnya
     * (registrasi, factory, perbaikan manual), supaya indeks unik
     * (`email`, `data_mode`) dan pencarian saat login memakai bentuk yang sama.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null ? null : self::normaliseEmail($value),
        );
    }

    /** Satu definisi normalisasi; dipakai juga oleh login, registrasi, dan kunci rate limiter. */
    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * E-mail akun HANYA boleh ASCII tercetak (tanpa spasi dan tanpa karakter kontrol).
     *
     * Kolom `email` memakai kolasi `utf8mb4_unicode_ci`: di `WHERE email = ?` huruf beraksen dan
     * karakter kontrol yang "diabaikan" kolasi dianggap SAMA dengan padanan ASCII-nya
     * (`víctim@x.com` = `victim@x.com`), sementara kunci pembatas laju per e-mail dihitung dari
     * sha1(normaliseEmail()) dan memperlakukannya berbeda. Akibatnya tiap ejaan beraksen mendapat
     * ember baru dan satu akun bisa ditebak kata sandinya tanpa batas. Satu-satunya penutup yang
     * tidak bergantung pada kolasi adalah memastikan e-mail non-ASCII tidak pernah disimpan
     * (registrasi) dan tidak pernah dipakai mencari akun (login).
     *
     * Dipanggil dengan nilai yang SUDAH lewat normaliseEmail() (spasi tepi sudah dibuang).
     */
    public static function isAsciiEmail(string $email): bool
    {
        return preg_match('/\A[\x21-\x7E]+\z/', $email) === 1;
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Alamat utama lebih dulu, lalu yang terbaru. */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class)->orderByDesc('is_default')->orderByDesc('id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(CustomerOrder::class);
    }
}
