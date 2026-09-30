<?php

return [
    'not_authorized' => 'Only owner/admin can back up or restore the database.',
    'not_found' => 'Backup not found.',
    'invalid_dump' => 'This file does not look like a database backup from this app (the settings and users tables are missing).',
    'safety_backup_failed' => "Could not take a safety backup of the current data, so the restore was cancelled and nothing was changed: :reason",
];
