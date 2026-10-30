<?php

return [
    'home' => 'Back to home',
    'empty_response_question' => 'The server sent back an empty answer. Would you like to reload the page?',
    '401' => [
        'title' => 'Sign-in required',
        'message' => 'Sign in to open this page.',
    ],
    '403' => [
        'title' => 'Access denied',
        'message' => 'You are not allowed to open this page.',
    ],
    '404' => [
        'title' => 'Page not found',
        'message' => 'This page does not exist or is no longer available.',
    ],
    '419' => [
        'title' => 'Session expired',
        'message' => 'Your page has expired. Reload it and try again.',
        'reload_question' => 'This page has expired. Would you like to reload it?',
    ],
    '429' => [
        'title' => 'Too many requests',
        'message' => 'Please wait a moment before trying again.',
    ],
    '500' => [
        'title' => 'Something went wrong',
        'message' => 'An unexpected error occurred. Please try again later.',
    ],
    '503' => [
        'title' => 'Service unavailable',
        'message' => 'The service is temporarily unavailable. Please try again in a few minutes.',
    ],
    'zip_busy' => [
        'title' => 'Download in progress',
        'message' => 'A ZIP download is already in progress for your account. Wait until it ends, then try again.',
    ],
    'zip_too_large' => [
        'title' => 'Too many files',
        'message' => 'This selection holds too many files for one ZIP. Choose fewer folders and try again.',
    ],
    'upload_quota' => 'You have reached today’s upload limit. Sending will work again tomorrow; ask an administrator if you need more.',
    'generic' => [
        'title' => 'Something went wrong',
        'message' => 'The request could not be completed.',
    ],
];
