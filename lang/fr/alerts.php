<?php

return [
    'subject' => '[:platform] :subject',
    'greeting' => 'Bonjour :name,',
    'security_log' => 'Le détail (heure, adresse, navigateur) se trouve dans le journal de sécurité.',
    'reason' => 'Vous recevez cet e-mail parce que vous êtes administrateur de :platform.',
    'events' => [
        'client_account_created' => [
            'subject' => 'Nouveau compte du portail client',
            'body' => ':by a créé le compte du portail client « :account » (identifiant :username) pour :company.',
        ],
        'client_account_all_audits' => [
            'subject' => 'Compte client avec tous les audits',
            'body' => ':by a donné au compte du portail client « :account » (identifiant :username, :company) l\'accès à tous les audits, y compris les futurs.',
        ],
        'user_created' => [
            'subject' => 'Nouvel administrateur',
            'body' => ':by a créé un nouveau compte administrateur : :name (identifiant :username).',
        ],
        'role_changed' => [
            'subject' => 'Nouvel administrateur',
            'body' => ':by a donné le rôle administrateur à :name (identifiant :username).',
        ],
        'login_throttled' => [
            'subject' => 'Connexion bloquée',
            'body' => 'La connexion a été bloquée après trop de tentatives échouées (dernier identifiant essayé : :username). Les autres blocages de l\'heure qui suit ne sont pas envoyés par e-mail.',
        ],
        'portal_login_throttled' => [
            'subject' => 'Connexion au portail client bloquée',
            'body' => 'La connexion au portail client de :company a été bloquée après trop de tentatives échouées (dernier identifiant essayé : :username). Les autres blocages de l\'heure qui suit ne sont pas envoyés par e-mail.',
        ],
        'journal_broken' => [
            'subject' => 'Journal BE altéré',
            'body' => 'La vérification quotidienne a constaté que le journal du workflow BE a été modifié en dehors de la plateforme (première entrée rompue : :entry).',
        ],
    ],
];
