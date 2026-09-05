<?php

namespace App\Support;

/**
 * 018-license-activation — mengambil identitas MESIN yang stabil lintas
 * restart/upgrade aplikasi (research.md R3), bukan kombinasi
 * hostname/MAC yang bisa berubah tanpa mesinnya benar-benar berbeda.
 * Nilai mentahnya TIDAK PERNAH disimpan — hanya hash sha256-nya, dan itu
 * pun di-hash SEKALI LAGI (Hash::make) sebelum masuk database
 * (LicenseActivationService) — dua lapis, bukan berlebihan: sha256 di
 * sini menyamaratakan panjang/bentuk apa pun yang dikembalikan OS,
 * Hash::make di lapisan atasnya yang benar-benar membuatnya satu-arah
 * secara kriptografis.
 */
class MachineFingerprint
{
    public static function current(): string
    {
        $raw = match (PHP_OS_FAMILY) {
            'Linux' => self::linuxMachineId(),
            'Darwin' => self::macPlatformUuid(),
            default => throw new \RuntimeException('Platform tidak didukung untuk fingerprint mesin: '.PHP_OS_FAMILY),
        };

        if ($raw === null || $raw === '') {
            throw new \RuntimeException('Gagal mengambil identitas mesin.');
        }

        return hash('sha256', $raw);
    }

    private static function linuxMachineId(): ?string
    {
        foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $path) {
            if (is_readable($path)) {
                $value = trim((string) file_get_contents($path));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private static function macPlatformUuid(): ?string
    {
        $output = shell_exec('ioreg -rd1 -c IOPlatformExpertDevice 2>/dev/null');
        if ($output === null) {
            return null;
        }

        if (preg_match('/"IOPlatformUUID"\s*=\s*"([0-9A-Fa-f-]+)"/', $output, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
