<?php

namespace App\Services\Backup;

use RuntimeException;

/** Kegagalan proses cadangan/pemulihan; pesannya aman ditampilkan ke pengguna. */
class BackupException extends RuntimeException {}
