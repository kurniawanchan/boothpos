<?php

// Customer commerce (storefront + customer Android app, 2026-11-11).
//
// Messages for /api/v1/customer/* and /api/v1/storefront/*. `titles` and
// `details` are keyed by machine CODE (CONTRACT §3): the Android app decides
// what to do from `code`, this text is only displayed. It must be safe for
// the public: no table/class names, no hint whether an e-mail is registered,
// no other customer's data. Keys must match lang/id/customer_commerce.php
// exactly (guarded by CustomerCommerceErrorEnvelopeTest).
return [
    'titles' => [
        'unauthenticated' => 'Invalid session',
        'invalid_credentials' => 'Sign-in failed',
        'forbidden' => 'Not allowed',
        'not_found' => 'Not found',
        'method_not_allowed' => 'Method not supported',
        'idempotency_key_reused' => 'Request key already used',
        'cart_invalid' => 'Your cart needs an update',
        'price_changed' => 'Price changed',
        'address_limit_reached' => 'Address limit reached',
        'validation_failed' => 'Invalid data',
        'registration_failed' => 'Registration could not be processed',
        'idempotency_key_required' => 'Request key required',
        'too_many_requests' => 'Too many requests',
        'customer_commerce_disabled' => 'The store is not taking orders right now',
        'payload_too_large' => 'Request too large',
        'installation_locked' => 'The store is not activated',
        'server_error' => 'Server error',
        'request_failed' => 'The request could not be processed',
    ],

    'details' => [
        'unauthenticated' => 'Your session is invalid or has expired. Please sign in again.',
        'invalid_credentials' => 'The e-mail or password is incorrect.',
        'forbidden' => 'You are not allowed to do this.',
        'not_found' => 'We could not find what you were looking for.',
        'method_not_allowed' => 'This request method is not supported.',
        'idempotency_key_reused' => 'This request reuses a key that already belongs to another order. Please start the order again.',
        'cart_invalid' => 'Some items in your cart are no longer available or exceed the available quantity.',
        'price_changed' => 'The price or order total has changed. Review the latest summary before ordering.',
        'address_limit_reached' => 'You have reached the saved address limit. Delete an address to add a new one.',
        'validation_failed' => 'The submitted data is invalid. Please check your input.',
        'registration_failed' => 'Registration could not be processed. Check your details, or sign in if you already have an account.',
        'idempotency_key_required' => 'An order request must include a valid request key.',
        'too_many_requests' => 'Too many attempts. Please wait a moment and try again.',
        'customer_commerce_disabled' => 'The store is not accepting new orders through the app right now. Please try again later.',
        'payload_too_large' => 'The request is too large. Please send less data.',
        'installation_locked' => 'This store has not been activated yet. Please try again later.',
        'server_error' => 'Something went wrong on the server. Please try again shortly.',
        'request_failed' => 'The request could not be processed. Please check it and try again.',
    ],

    // Checkout field errors (422 `validation_failed`) that only the service can decide.
    'checkout' => [
        'duplicate_variant' => 'An item may appear only once in the cart.',
        'address_invalid' => 'The delivery address is invalid. Choose one of your saved addresses.',
        'contact_name_required' => 'The contact name is required.',
        'contact_phone_required' => 'The contact phone number is required.',
    ],

    // Account field errors (422 `validation_failed`) decided by our own rules.
    'account' => [
        'email_ascii' => 'The e-mail may only contain standard letters, digits and symbols (no accents).',
        'password_too_long' => 'The password may be at most :max bytes long (accented and non-Latin characters take more than one byte).',
    ],

    // 422 `validation_failed` field messages. The project ships no validation texts, so Laravel hands back the
    // raw key ("validation.in"); CustomerCommerceProblem swaps those keys for these sentences (customer/storefront
    // routes only). No attribute names: the app shows the sentence next to the field it belongs to.
    // Lookup order: `<rule>.<type>` (size rules: string/numeric/array/file), then `<rule>`, then `generic`.
    'validation_rules' => [
        'required' => 'This field is required.',
        'required_if' => 'This field is required for the selected option.',
        'string' => 'This field must be text.',
        'max' => [
            'string' => 'This text is too long.',
            'numeric' => 'This number is too large.',
            'array' => 'There are too many entries.',
            'file' => 'This file is too large.',
        ],
        'min' => [
            'string' => 'This text is too short.',
            'numeric' => 'This number is too small.',
            'array' => 'There are too few entries.',
            'file' => 'This file is too small.',
        ],
        'between' => 'This value is outside the allowed range.',
        'size' => 'This value does not have the required size.',
        'email' => 'Enter a valid e-mail address.',
        'in' => 'The selected value is not valid.',
        'integer' => 'This field must be a whole number.',
        'numeric' => 'This field must be a number.',
        'array' => 'This field must be a list.',
        'boolean' => 'This field must be yes or no.',
        'regex' => 'The format of this value is not valid.',
        'distinct' => 'This value appears more than once.',
        'exists' => 'The selected value could not be found.',
        'unique' => 'This value is already in use.',
        'date' => 'This field must be a valid date.',
        'digits' => 'This field must have the required number of digits.',
        'generic' => 'This value is not valid.',
    ],

    // Fulfilment by STAFF (/customer-orders routes, staff envelope `{message, code}`). Key = machine code.
    // Text for staff, not customers; still without customer personal data.
    'fulfilment' => [
        'not_authorized' => 'You do not have access to customer orders.',
        'invalid_transition' => 'An order with status ":status" cannot be processed with this action.',
        'wrong_fulfilment_method' => 'This action does not apply to this order\'s fulfilment method.',
        'insufficient_stock' => 'Not enough stock to confirm this order. No stock was deducted.',
        'variant_unavailable' => 'An ordered item\'s variant is no longer available. No stock was changed.',
        'shipping_cost_courier_only' => 'A shipping cost can only be set for courier orders.',
        'shipping_cost_required' => 'Enter the shipping cost to confirm a courier order (enter 0 if shipping is free).',
        'delivered_before_dispatch' => 'The delivery time cannot be before the dispatch time.',
        'shipment_nothing_to_update' => 'Provide the tracking number or service name to change.',
        'plain_text' => 'The :attribute must be plain text, without HTML tags or control characters.',
    ],

    // Feature settings validation (PUT /settings, staff).
    'settings' => [
        'enabled_must_be_boolean' => 'The online store switch can only be on or off.',
        'instructions_must_be_plain_text' => 'Instructions must be plain text of at most :max characters, without HTML tags.',
    ],
];
