<?php

return [
    'invalid_status_transition' => "Event with status ':from' cannot move to ':to'.",
    'cannot_close_open_sessions' => 'This event cannot be closed: :count cashier session(s) are still open.',
    'no_open_session' => 'No open session.',
    'already_has_open_session' => 'You still have an open cashier session. Close it first.',
    'opening_cash_entries_mismatch' => 'The per-artist opening cash entries must sum to the total opening cash.',
    'session_only_on_active_event' => 'A session can only be opened on an active event.',
    'not_authorized_close_session' => 'You are not authorized to close this session.',
    'session_already_closed' => 'This session is already closed.',
    'not_authorized_view_summary' => 'You are not authorized to view this session\'s summary.',
];
