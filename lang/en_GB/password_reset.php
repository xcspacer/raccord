<?php

return [
    'forgot_link' => 'Forgot your password?',
    'request_title' => 'Forgot password',
    'request_intro' => 'Enter the e-mail address of your account: we will send you a link to choose a new password.',
    'email' => 'E-mail',
    'no_email_hint' => 'No e-mail address on your account? Ask an administrator to reset your password.',
    'request_submit' => 'Send the link',
    'back_to_login' => 'Back to sign-in',
    'reset_title' => 'New password',
    'reset_intro' => 'Choose a new password. You will then sign in again on all your devices.',
    'minimum' => 'At least 10 characters (12 for administrators).',
    'reset_submit' => 'Save the password',
    'mail' => [
        'subject' => '[:platform] Reset your password',
        'greeting' => 'Hello :name,',
        'body' => 'A new password was requested for your :platform account (username :username).',
        'action' => 'Choose a new password',
        'expiry' => 'This link expires in :count minutes.',
        'ignore' => 'If you did not ask for it, ignore this e-mail: your password stays the same.',
    ],
];
