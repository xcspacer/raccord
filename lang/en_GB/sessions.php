<?php

return [
    'title' => 'Active sessions',
    'this_device' => 'This device',
    'last_active' => 'Last activity: :date',
    'unknown_device' => 'Unknown device',
    'revoke' => 'Sign out',
    'revoke_others' => 'Sign out all other devices',
    'revoke_all' => 'Sign out everywhere',
    'confirm_revoke_others' => 'Sign out all your other devices?',
    'confirm_revoke_all' => 'Sign :name out of every device?',
    'revoked' => 'Device signed out.',
    'revoked_all' => '{0} No other device to sign out.|{1} :count device signed out.|[2,*] :count devices signed out.',
    'none' => 'No open session.',
    'end_messages' => [
        'session_revoked' => 'This session was signed out from another device.',
        'user_disabled' => 'This account is no longer active.',
        'unauthenticated' => 'Please sign in again.',
    ],
    'end_reasons' => [
        'session_revoked' => 'Signed out remotely',
        'user_disabled' => 'Account disabled',
    ],
    'confirm_password' => [
        'title' => 'Confirm your password',
        'hint' => 'For your security, please confirm your password to continue.',
        'password' => 'Password',
        'confirm' => 'Confirm',
        'cancel' => 'Cancel',
        'invalid' => 'Incorrect password.',
    ],
];
