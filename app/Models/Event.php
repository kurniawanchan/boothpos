<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * BUG YANG DITEMUKAN & DIPERBAIKI — 'status' semula tidak ada di sini.
     * EventController::updateStatus() menulis status baru lewat
     * $event->update(['status' => $newStatus]) — tanpa 'status' di
     * $fillable, panggilan itu diam-diam tidak melakukan apa-apa (tidak
     * error, hanya tidak menyimpan), sehingga event TIDAK PERNAH benar-
     * benar berpindah status meski API merespons 200. Proteksi terhadap
     * klien mengubah status secara sembarangan sudah ada di lapisan lain:
     * StoreEventRequest tidak mengizinkan 'status' sama sekali (selalu
     * mulai 'draft'), dan transisi hanya lewat updateStatus() yang
     * ditegakkan EventPolicy::transitionStatus + Event::canTransitionTo().
     */
    protected $fillable = ['name', 'location', 'start_date', 'end_date', 'status', 'event_cost', 'notes'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'event_cost' => 'decimal:2',
        ];
    }

    public function cashierSessions(): HasMany
    {
        return $this->hasMany(CashierSession::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(ArtistSettlement::class);
    }

    /**
     * State machine sesuai uml-pos-mvp.md bagian 6.4. Hanya transisi yang
     * digambar eksplisit di diagram yang diizinkan — 'active' -> 'cancelled'
     * SENGAJA tidak ada karena tidak digambarkan di sana (lihat Ambiguitas
     * A6 di laporan: perlu dikonfirmasi apakah ini kebutuhan nyata).
     */
    private const ALLOWED_TRANSITIONS = [
        'draft' => ['active', 'cancelled'],
        'active' => ['closed'],
        'closed' => [],
        'cancelled' => [],
    ];

    public function canTransitionTo(string $newStatus): bool
    {
        return in_array($newStatus, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
    }
}
