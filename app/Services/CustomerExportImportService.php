<?php

namespace App\Services;

use App\Imports\CustomerImport;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * 020-customer-data-import-export — export/import Customer lewat SATU
 * workbook TERPISAH (bukan sheet tambahan di MasterDataSheets::ORDER —
 * Customer bukan bagian dari katalog produk, tidak punya dependency ke
 * artists/categories/products/stock — lihat research.md Decision 1),
 * meniru bentuk PreorderExportImportService (007): satu sheet, validasi
 * PENUH dulu baru satu transaksi all-or-nothing, dry_run lewat jalur
 * validasi yang identik untuk pratinjau.
 *
 * ATURAN BARU yang tidak ada presedennya di importer lain di kodebase ini:
 * baris dengan `email` yang cocok (case-insensitive, exact match, dalam
 * mode data yang sedang aktif) dengan pelanggan yang sudah ada meng-UPDATE
 * pelanggan itu, bukan bikin duplikat — sel kosong pada baris update TIDAK
 * mengosongkan nilai yang sudah ada (konsisten dengan "blank = biarkan"
 * milik MasterDataImportService). Baris tanpa email SELALU membuat
 * pelanggan baru, walau nama/telepon kebetulan sama dengan yang sudah ada.
 * Dua baris di file yang sama dengan email yang sama diperlakukan sebagai
 * pembaruan berurutan ke pelanggan yang sama — baris terakhir menang untuk
 * field yang diisinya (research.md Decision 3).
 */
class CustomerExportImportService
{
    private const HEADINGS = ['name', 'phone', 'address', 'email', 'social_handle', 'notes'];

    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * @return array<int, array{name:string,phone:?string,address:?string,email:?string,social_handle:?string,notes:?string}>
     */
    public function export(): array
    {
        // Query polos tanpa paginasi — dibatasi asumsi realistis "satu
        // daftar pelanggan satu toko" (plan.md Scale/Scope), sama seperti
        // asumsi yang sudah dipakai PreorderExportImportService::export().
        return Customer::query()
            ->orderBy('name')
            ->get()
            ->map(fn (Customer $c) => [
                'name' => $c->name,
                'phone' => $c->phone,
                'address' => $c->address,
                'email' => $c->email,
                'social_handle' => $c->social_handle,
                'notes' => $c->notes,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function template(): array
    {
        return [array_combine(self::HEADINGS, self::HEADINGS)];
    }

    /**
     * @return array{applied: bool, dry_run: bool, created_count: int, updated_count: int, row_errors: array}
     */
    public function import(UploadedFile $file, bool $dryRun, User $importedBy): array
    {
        $rows = Excel::toArray(new CustomerImport, $file)[0] ?? [];

        [$validated, $rowErrors] = $this->validateRows($rows);

        if ($rowErrors !== []) {
            return [
                'applied' => false, 'dry_run' => $dryRun,
                'created_count' => 0, 'updated_count' => 0,
                'row_errors' => $rowErrors,
            ];
        }

        $normalizedEmails = collect($validated)
            ->pluck('email')
            ->filter()
            ->map(fn ($email) => strtolower($email))
            ->unique()
            ->values()
            ->all();

        $existingByEmail = $this->fetchExistingByEmail($normalizedEmails);

        if ($dryRun) {
            [$createdCount, $updatedCount] = $this->countOutcome($validated, $existingByEmail);

            return [
                'applied' => false, 'dry_run' => true,
                'created_count' => $createdCount, 'updated_count' => $updatedCount,
                'row_errors' => [],
            ];
        }

        $createdCount = 0;
        $updatedCount = 0;

        DB::transaction(function () use ($validated, $existingByEmail, $importedBy, &$createdCount, &$updatedCount) {
            // Dua baris di file yang sama berbagi email harus saling
            // memperbarui (research.md Decision 3), bukan berlomba insert
            // dua kali — makanya pelanggan yang baru dibuat/diperbarui DI
            // DALAM loop ini juga ikut dimasukkan ke $touchedByEmail, bukan
            // hanya row hasil query awal.
            $touchedByEmail = [];

            foreach ($validated as $row) {
                $key = $row['email'] !== null ? strtolower($row['email']) : null;
                $customer = $key !== null ? ($touchedByEmail[$key] ?? $existingByEmail->get($key)) : null;

                if ($customer !== null) {
                    foreach (['name', 'phone', 'address', 'email', 'social_handle', 'notes'] as $field) {
                        if ($row[$field] !== null) {
                            $customer->{$field} = $row[$field];
                        }
                    }
                    $customer->save();
                    $updatedCount++;
                } else {
                    $customer = Customer::create([
                        'name' => $row['name'],
                        'phone' => $row['phone'],
                        'address' => $row['address'],
                        'email' => $row['email'],
                        'social_handle' => $row['social_handle'],
                        'notes' => $row['notes'],
                    ]);
                    $createdCount++;
                }

                if ($key !== null) {
                    $touchedByEmail[$key] = $customer;
                }
            }

            // F13.4 — impor massal adalah tindakan sensitif, log DI DALAM
            // transaksi yang sama (research.md Decision 6) — kalau impornya
            // batal, lognya ikut batal.
            $this->activityLogger->log(
                userId: $importedBy->id,
                action: 'imported',
                entityType: 'CustomerImport',
                entityId: null,
                description: 'Impor massal data pelanggan.',
                newValues: ['created_count' => $createdCount, 'updated_count' => $updatedCount],
            );
        });

        return [
            'applied' => true, 'dry_run' => false,
            'created_count' => $createdCount, 'updated_count' => $updatedCount,
            'row_errors' => [],
        ];
    }

    /**
     * @return array{0: int, 1: int} [created_count, updated_count]
     */
    private function countOutcome(array $validated, Collection $existingByEmail): array
    {
        $created = 0;
        $updated = 0;
        $touched = [];

        foreach ($validated as $row) {
            $key = $row['email'] !== null ? strtolower($row['email']) : null;
            $isUpdate = $key !== null && (isset($touched[$key]) || $existingByEmail->has($key));

            $isUpdate ? $updated++ : $created++;

            if ($key !== null) {
                $touched[$key] = true;
            }
        }

        return [$created, $updated];
    }

    /**
     * @return array{0: array<int, array{row:int,name:string,phone:?string,address:?string,email:?string,social_handle:?string,notes:?string}>, 1: array<int, array{row:int,errors:string[]}>}
     */
    private function validateRows(array $rows): array
    {
        $validated = [];
        $rowErrors = [];

        foreach ($rows as $i => $row) {
            $rowNumber = $i + 2; // +1 karena 0-index, +1 karena baris judul sendiri
            $errors = [];

            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $errors[] = __('customers.import_name_required');
            } elseif (mb_strlen($name) > 100) {
                $errors[] = __('customers.import_name_too_long');
            }

            $email = trim((string) ($row['email'] ?? ''));
            $email = $email !== '' ? $email : null;
            if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = __('customers.import_email_invalid', ['email' => $email]);
            } elseif ($email !== null && mb_strlen($email) > 100) {
                $errors[] = __('customers.import_email_too_long');
            }

            $phone = trim((string) ($row['phone'] ?? ''));
            $phone = $phone !== '' ? $phone : null;
            if ($phone !== null && mb_strlen($phone) > 30) {
                $errors[] = __('customers.import_phone_too_long');
            }

            $address = trim((string) ($row['address'] ?? ''));
            $address = $address !== '' ? $address : null;

            $socialHandle = trim((string) ($row['social_handle'] ?? ''));
            $socialHandle = $socialHandle !== '' ? $socialHandle : null;
            if ($socialHandle !== null && mb_strlen($socialHandle) > 100) {
                $errors[] = __('customers.import_social_handle_too_long');
            }

            $notes = trim((string) ($row['notes'] ?? ''));
            $notes = $notes !== '' ? $notes : null;

            if ($errors !== []) {
                $rowErrors[] = ['row' => $rowNumber, 'errors' => $errors];

                continue;
            }

            $validated[] = [
                'row' => $rowNumber,
                'name' => $name,
                'phone' => $phone,
                'address' => $address,
                'email' => $email,
                'social_handle' => $socialHandle,
                'notes' => $notes,
            ];
        }

        return [$validated, $rowErrors];
    }

    /**
     * withoutGlobalScope TIDAK dipakai di sini secara SENGAJA — beda dari
     * bug `code` yang ditemukan di MasterDataImportService (categories/
     * artists/vendors/materials), `customers.email` TIDAK punya UNIQUE
     * constraint lintas database, jadi tidak ada risiko tabrakan constraint
     * lintas mode yang perlu diantisipasi. Pencocokan update justru HARUS
     * tetap terbatas pada mode data yang sedang aktif — baris impor LIVE
     * tidak boleh mencocokkan (apalagi meng-update) pelanggan DEMO, dan
     * sebaliknya — jadi global scope HasDataMode di sini dibiarkan aktif
     * apa adanya (research.md Decision 2).
     *
     * whereIn('email', ...) polos (bukan whereRaw LOWER(email)) sengaja
     * dipakai: kolom ini pakai collation default koneksi
     * (`utf8mb4_unicode_ci`, lihat config/database.php) yang MEMANG
     * case-insensitive di level MySQL, jadi perbandingan string biasa
     * sudah cukup — normalisasi strtolower() di sisi PHP di bawah ini
     * hanya untuk kunci array/perbandingan dalam satu batch impor yang
     * sama, bukan untuk membuat query-nya case-insensitive (itu sudah
     * beres di level database).
     */
    private function fetchExistingByEmail(array $normalizedEmails): Collection
    {
        if ($normalizedEmails === []) {
            return collect();
        }

        return Customer::query()
            ->whereIn('email', $normalizedEmails)
            ->get()
            ->keyBy(fn (Customer $c) => strtolower($c->email));
    }
}
