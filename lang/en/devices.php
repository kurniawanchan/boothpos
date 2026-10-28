<?php

return [
    'pick_outlet' => 'Select one outlet first (X-Outlet-Id header).',
    'name_required' => 'The terminal name is required.',
    'name_taken' => 'The terminal name ":name" is already used by another terminal in this outlet.',
    'revoked_no_rename' => 'A revoked terminal cannot be renamed.',
    'forbidden' => 'Only the owner or an admin can manage terminals.',
    'invalid_device' => 'Unknown terminal, revoked, or not part of this outlet.',
    'device_required' => 'Choose a terminal before signing in with a PIN.',
    'move_open_shift' => 'This terminal still has an open shift. Close the shift before moving the terminal.',
    'revoked_no_edit' => 'A deactivated terminal cannot be edited. Activate it again first.',
    'in_use' => 'This terminal has been used (it has orders or shifts) and cannot be deleted. Deactivate it instead.',
    'bulk_not_found' => 'Terminal not found or not part of your outlets.',
];
