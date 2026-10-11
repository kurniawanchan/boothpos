<?php

namespace App\Services\DemoClone;

use RuntimeException;

/**
 * Kegagalan bisnis penyalinan LIVE→DEMO. Pesan HANYA boleh memuat nama
 * tabel/kolom/id — tidak pernah nilai baris (bisa berisi PII pelanggan).
 */
class CloneException extends RuntimeException {}
