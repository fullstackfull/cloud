<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => 'The :attribute field must be accepted.',
    'accepted_if' => 'The :attribute field must be accepted when :other is :value.',
    'active_url' => 'The :attribute field must be a valid URL.',
    'after' => 'The :attribute field must be a date after :date.',
    'after_or_equal' => 'The :attribute field must be a date after or equal to :date.',
    'alpha' => 'The :attribute field must only contain letters.',
    'alpha_dash' => 'The :attribute field must only contain letters, numbers, dashes, and underscores.',
    'alpha_num' => 'The :attribute field must only contain letters and numbers.',
    'any_of' => 'The :attribute field is invalid.',
    'array' => 'The :attribute field must be an array.',
    'array_keys' => 'The :attribute field must only contain the following keys: :values.',
    'ascii' => 'The :attribute field must only contain single-byte alphanumeric characters and symbols.',
    'base64' => 'The :attribute field must be a valid Base64 string.',
    'before' => 'The :attribute field must be a date before :date.',
    'before_or_equal' => 'The :attribute field must be a date before or equal to :date.',
    'between' => [
        'array' => 'The :attribute field must have between :min and :max items.',
        'file' => 'The :attribute field must be between :min and :max kilobytes.',
        'numeric' => 'The :attribute field must be between :min and :max.',
        'string' => 'The :attribute field must be between :min and :max characters.',
    ],
    'boolean' => 'The :attribute field must be true or false.',
    'can' => 'The :attribute field contains an unauthorized value.',
    'confirmed' => 'The :attribute field confirmation does not match.',
    'contains' => 'The :attribute field is missing a required value.',
    'current_password' => 'The password is incorrect.',
    'date' => 'The :attribute field must be a valid date.',
    'date_equals' => 'The :attribute field must be a date equal to :date.',
    'date_format' => 'The :attribute field must match the format :format.',
    'decimal' => 'The :attribute field must have :decimal decimal places.',
    'declined' => 'The :attribute field must be declined.',
    'declined_if' => 'The :attribute field must be declined when :other is :value.',
    'different' => 'The :attribute field and :other must be different.',
    'digits' => 'The :attribute field must be :digits digits.',
    'digits_between' => 'The :attribute field must be between :min and :max digits.',
    'dimensions' => 'The :attribute field has invalid image dimensions.',
    'distinct' => 'The :attribute field has a duplicate value.',
    'doesnt_contain' => 'The :attribute field must not contain any of the following: :values.',
    'doesnt_end_with' => 'The :attribute field must not end with one of the following: :values.',
    'doesnt_start_with' => 'The :attribute field must not start with one of the following: :values.',
    'email' => 'The :attribute field must be a valid email address.',
    'encoding' => 'The :attribute field must be encoded in :encoding.',
    'ends_with' => 'The :attribute field must end with one of the following: :values.',
    'enum' => 'The selected :attribute is invalid.',
    'exists' => 'The selected :attribute is invalid.',
    'extensions' => 'The :attribute field must have one of the following extensions: :values.',
    'file' => 'The :attribute field must be a file.',
    'filled' => 'The :attribute field must have a value.',
    'gt' => [
        'array' => 'The :attribute field must have more than :value items.',
        'file' => 'The :attribute field must be greater than :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than :value.',
        'string' => 'The :attribute field must be greater than :value characters.',
    ],
    'gte' => [
        'array' => 'The :attribute field must have :value items or more.',
        'file' => 'The :attribute field must be greater than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than or equal to :value.',
        'string' => 'The :attribute field must be greater than or equal to :value characters.',
    ],
    'hex_color' => 'The :attribute field must be a valid hexadecimal color.',
    'image' => 'The :attribute field must be an image.',
    'in' => 'The selected :attribute is invalid.',
    'in_array' => 'The :attribute field must exist in :other.',
    'in_array_keys' => 'The :attribute field must contain at least one of the following keys: :values.',
    'integer' => 'The :attribute field must be an integer.',
    'ip' => 'The :attribute field must be a valid IP address.',
    'ipv4' => 'The :attribute field must be a valid IPv4 address.',
    'ipv6' => 'The :attribute field must be a valid IPv6 address.',
    'json' => 'The :attribute field must be a valid JSON string.',
    'list' => 'The :attribute field must be a list.',
    'lowercase' => 'The :attribute field must be lowercase.',
    'lt' => [
        'array' => 'The :attribute field must have less than :value items.',
        'file' => 'The :attribute field must be less than :value kilobytes.',
        'numeric' => 'The :attribute field must be less than :value.',
        'string' => 'The :attribute field must be less than :value characters.',
    ],
    'lte' => [
        'array' => 'The :attribute field must not have more than :value items.',
        'file' => 'The :attribute field must be less than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be less than or equal to :value.',
        'string' => 'The :attribute field must be less than or equal to :value characters.',
    ],
    'mac_address' => 'The :attribute field must be a valid MAC address.',
    'max' => [
        'array' => 'The :attribute field must not have more than :max items.',
        'file' => 'The :attribute field must not be greater than :max kilobytes.',
        'numeric' => 'The :attribute field must not be greater than :max.',
        'string' => 'The :attribute field must not be greater than :max characters.',
    ],
    'max_digits' => 'The :attribute field must not have more than :max digits.',
    'mimes' => 'The :attribute field must be a file of type: :values.',
    'mimetypes' => 'The :attribute field must be a file of type: :values.',
    'min' => [
        'array' => 'The :attribute field must have at least :min items.',
        'file' => 'The :attribute field must be at least :min kilobytes.',
        'numeric' => 'The :attribute field must be at least :min.',
        'string' => 'The :attribute field must be at least :min characters.',
    ],
    'min_digits' => 'The :attribute field must have at least :min digits.',
    'missing' => 'The :attribute field must be missing.',
    'missing_if' => 'The :attribute field must be missing when :other is :value.',
    'missing_unless' => 'The :attribute field must be missing unless :other is :value.',
    'missing_with' => 'The :attribute field must be missing when :values is present.',
    'missing_with_all' => 'The :attribute field must be missing when :values are present.',
    'multiple_of' => 'The :attribute field must be a multiple of :value.',
    'not_in' => 'The selected :attribute is invalid.',
    'not_regex' => 'The :attribute field format is invalid.',
    'numeric' => 'The :attribute field must be a number.',
    'password' => [
        'letters' => 'The :attribute field must contain at least one letter.',
        'mixed' => 'The :attribute field must contain at least one uppercase and one lowercase letter.',
        'numbers' => 'The :attribute field must contain at least one number.',
        'symbols' => 'The :attribute field must contain at least one symbol.',
        'uncompromised' => 'The given :attribute has appeared in a data leak. Please choose a different :attribute.',
    ],
    'present' => 'The :attribute field must be present.',
    'present_if' => 'The :attribute field must be present when :other is :value.',
    'present_unless' => 'The :attribute field must be present unless :other is :value.',
    'present_with' => 'The :attribute field must be present when :values is present.',
    'present_with_all' => 'The :attribute field must be present when :values are present.',
    'prohibited' => 'The :attribute field is prohibited.',
    'prohibited_if' => 'The :attribute field is prohibited when :other is :value.',
    'prohibited_if_accepted' => 'The :attribute field is prohibited when :other is accepted.',
    'prohibited_if_declined' => 'The :attribute field is prohibited when :other is declined.',
    'prohibited_unless' => 'The :attribute field is prohibited unless :other is in :values.',
    'prohibits' => 'The :attribute field prohibits :other from being present.',
    'regex' => 'The :attribute field format is invalid.',
    'required' => 'The :attribute field is required.',
    'required_array_keys' => 'The :attribute field must contain entries for: :values.',
    'required_if' => 'The :attribute field is required when :other is :value.',
    'required_if_accepted' => 'The :attribute field is required when :other is accepted.',
    'required_if_declined' => 'The :attribute field is required when :other is declined.',
    'required_unless' => 'The :attribute field is required unless :other is in :values.',
    'required_with' => 'The :attribute field is required when :values is present.',
    'required_with_all' => 'The :attribute field is required when :values are present.',
    'required_without' => 'The :attribute field is required when :values is not present.',
    'required_without_all' => 'The :attribute field is required when none of :values are present.',
    'same' => 'The :attribute field must match :other.',
    'size' => [
        'array' => 'The :attribute field must contain :size items.',
        'file' => 'The :attribute field must be :size kilobytes.',
        'numeric' => 'The :attribute field must be :size.',
        'string' => 'The :attribute field must be :size characters.',
    ],
    'starts_with' => 'The :attribute field must start with one of the following: :values.',
    'string' => 'The :attribute field must be a string.',
    'timezone' => 'The :attribute field must be a valid timezone.',
    'unique' => 'The :attribute has already been taken.',
    'uploaded' => 'The :attribute failed to upload.',
    'uppercase' => 'The :attribute field must be uppercase.',
    'url' => 'The :attribute field must be a valid URL.',
    'ulid' => 'The :attribute field must be a valid ULID.',
    'uuid' => 'The :attribute field must be a valid UUID.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [

        'confirm_account_name' => 'account name',

        'cursor' => 'page position',
        'client_secret' => 'payment credential',
        'accepts_terms' => 'terms of service',
        'account_type' => 'account type',
        'action' => 'power action',
        'admin_email' => 'administrator email',
        'admin_username' => 'administrator username',
        'allowed_ip_ranges' => 'allowed IP ranges',
        'allowed_ip_ranges.*' => 'IP range',
        'also_try' => 'alternative endings',
        'also_try.*' => 'alternative ending',
        'attachments' => 'attachments',
        'attachments.*' => 'attachment',
        'authorisation_code' => 'authorisation code',
        'billing_period' => 'billing period',
        'body' => 'message',
        'category' => 'category',
        'challenge_token' => 'sign-in challenge',
        'channel' => 'channel',
        'code' => 'authentication code',
        'company_name' => 'company name',
        'confirm_account_id' => 'account confirmation',
        'confirm_hostname' => 'hostname confirmation',
        'confirm_serial' => 'serial number confirmation',
        'confirm_subscription_id' => 'subscription confirmation',
        'confirm_zone_name' => 'zone name confirmation',
        'confirmation' => 'confirmation',
        'content' => 'content',
        'country' => 'country',
        'coupon_code' => 'coupon code',
        'currency' => 'currency',
        'current_password' => 'current password',
        'data' => 'record data',
        'data.flags' => 'flags',
        'data.tag' => 'tag',
        'data.value' => 'value',
        'domain' => 'domain',
        'domain_source' => 'domain source',
        'email' => 'email address',
        'enabled' => 'enabled',
        'error' => 'error',
        'expires_at' => 'expiry date',
        'fingerprint' => 'fingerprint',
        'from' => 'from',
        'hostname' => 'hostname',
        'immediately' => 'immediately',
        'invoice_id' => 'invoice',
        'items' => 'items',
        'items.*' => 'item',
        'items.*.plan_id' => 'plan',
        'items.*.quantity' => 'quantity',
        'kind' => 'kind',
        'locale' => 'language',
        'member_id' => 'member',
        'message' => 'message',
        'meta' => 'details',
        'mode' => 'mode',
        'name' => 'name',
        'nameservers' => 'nameservers',
        'nameservers.*' => 'nameserver',
        'notes' => 'notes',
        'operation' => 'operation',
        'os_profile' => 'operating system',
        'page' => 'page',
        'password' => 'password',
        'path' => 'path',
        'paths' => 'paths',
        'paths.*' => 'path',
        'per_page' => 'page size',
        'phone' => 'phone number',
        'plan_id' => 'plan',
        'power_state' => 'power state',
        'price_id' => 'price',
        'priority' => 'priority',
        'quote_id' => 'quote',
        'rate_limit_per_minute' => 'rate limit',
        'reason' => 'reason',
        'recovery_codes' => 'recovery codes',
        'registrant' => 'registrant',
        'registrant.address_line_one' => 'address',
        'registrant.address_line_two' => 'address (second line)',
        'registrant.city' => 'city',
        'registrant.country' => 'country',
        'registrant.email' => 'registrant email',
        'registrant.name' => 'registrant name',
        'registrant.organisation' => 'organisation',
        'registrant.phone' => 'registrant phone',
        'registrant.postal_code' => 'postal code',
        'registrant.region' => 'region',
        'remember' => 'remember me',
        'return_url' => 'return address',
        'role' => 'role',
        'scope' => 'scope',
        'service_id' => 'service',
        'ssh_keys' => 'SSH keys',
        'ssh_keys.*' => 'SSH key',
        'state' => 'state',
        'status' => 'status',
        'subject' => 'subject',
        'template_id' => 'operating system image',
        'term_years' => 'registration period',
        'text' => 'text',
        'timezone' => 'time zone',
        'to' => 'to',
        'token' => 'token',
        'ttl' => 'TTL',
        'type' => 'type',
        'units' => 'units',
        'user_id' => 'user',
        'idempotency_key' => 'Idempotency-Key header',
    ],

    /*
    |--------------------------------------------------------------------------
    | Request-specific sentences
    |--------------------------------------------------------------------------
    |
    | Sentences a request or controller hands the validator directly, keyed by
    | what they are about rather than by the field, so two requests that
    | confirm different things do not share one sentence by accident. The
    | Arabic file carries the same keys.
    |
    */

    'requests' => [
        'idempotency_key' => [
            'required' => 'An Idempotency-Key header is required so a repeated submission cannot run this operation twice.',
            'min' => 'The Idempotency-Key header must be at least 8 characters.',
            'max' => 'The Idempotency-Key header must not exceed 128 characters.',
            'regex' => 'The Idempotency-Key header may contain only letters, digits, dots, colons, hyphens and underscores.',
        ],
        'registration' => [
            'country_required' => 'Choose the country this account is billed from. It decides the currency your invoices are issued in.',
            'country_unknown' => 'That is not a country we can bill from. Choose one from the list.',
            'currency_not_billable' => 'We bill in :currencies. Choose one of those.',
        ],
        'api_token' => [
            'current_password_required' => 'Confirm your account password to issue an API token.',
            'expires_in_future' => 'The expiry date must be in the future.',
        ],
        'backup' => [
            'delete_confirmation_required' => 'Type the machine\'s hostname to confirm. Deleting a backup cannot be undone once the grace period runs out.',
            'restore_confirmation_required' => 'Type the machine\'s hostname to confirm. A restore replaces every disk on it.',
            'file_restore_confirmation_required' => 'Type the machine\'s hostname to confirm. A file restore replaces those files on it.',
        ],
        'subscription' => [
            'immediate_cancellation_confirmation' => 'Cancelling immediately stops the service now and does not refund the rest of the period already paid for. Send confirm_subscription_id with this subscription\'s id to confirm, or omit `immediately` to end it when the paid period runs out.',
        ],
        'dedicated' => [
            'reinstall_confirmation_required' => 'Reinstalling erases every disk in this machine. Send confirm_serial with the server\'s serial number to confirm.',
            'os_profile_unavailable' => 'That operating system is not available for installation.',
            'power_action_required' => 'Name the power action: on, off or cycle.',
        ],
        'ipam' => [
            'hostname_required' => 'Name the hostname this address should resolve back to.',
        ],
        'order' => [
            'idempotency_key_required' => 'An Idempotency-Key header is required so a repeated submission cannot place a second order.',
            'plan_repeated' => 'Each plan may appear in the basket only once; use the quantity to order more than one.',
        ],
        'wordpress' => [
            'push_confirmation_required' => 'Type the production site\'s domain to confirm. A push overwrites it.',
        ],
        'vps' => [
            'power_action_required' => 'Name the power action: start, stop, reboot or shutdown.',
            'reinstall_confirmation_required' => 'Reinstalling erases every disk on this machine. Send confirm_hostname with the machine\'s hostname to confirm.',
            'ssh_key_format' => 'Each entry must be one OpenSSH public key on one line, in the form "type base64-key comment".',
        ],
        'auth' => [
            'challenge_expired' => 'This sign-in challenge has expired. Please sign in again.',
            'code_invalid' => 'That code is not valid.',
            'two_factor_not_enabled' => 'Two-factor authentication is not enabled on this account.',
            'too_many_password_attempts' => 'Too many failed attempts. Try again later.',
            'password_incorrect' => 'That password is incorrect.',
        ],
        'team' => [
            'confirm_account_name_required' => "The account's name, typed exactly as it appears above, confirms the transfer.",
        ],
        'notifications' => [
            'unknown_category_or_channel' => 'Unknown notification category or channel.',
        ],
    ],

];
