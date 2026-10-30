<?php

return [
    'subject' => '[:platform] :subject',
    'greeting' => 'Hello :name,',
    'security_log' => 'The details (time, address, browser) are in the security log.',
    'reason' => 'You receive this e-mail because you are an administrator of :platform.',
    'events' => [
        'client_account_created' => [
            'subject' => 'New client portal account',
            'body' => ':by created the client portal account « :account » (username :username) for :company.',
        ],
        'client_account_all_audits' => [
            'subject' => 'Client account given every audit',
            'body' => ':by gave the client portal account « :account » (username :username, :company) access to every audit, future ones included.',
        ],
        'user_created' => [
            'subject' => 'New administrator',
            'body' => ':by created a new administrator account: :name (username :username).',
        ],
        'role_changed' => [
            'subject' => 'New administrator',
            'body' => ':by gave the administrator role to :name (username :username).',
        ],
        'login_throttled' => [
            'subject' => 'Sign-in blocked',
            'body' => 'Sign-in was blocked after too many failed attempts (last username tried: :username). Other blocks in the next hour are not e-mailed.',
        ],
        'portal_login_throttled' => [
            'subject' => 'Client portal sign-in blocked',
            'body' => 'Sign-in to the client portal of :company was blocked after too many failed attempts (last username tried: :username). Other blocks in the next hour are not e-mailed.',
        ],
        'journal_broken' => [
            'subject' => 'Design office journal altered',
            'body' => 'The daily check found that the design office workflow journal was altered outside the platform (first broken entry: :entry).',
        ],
    ],
];
