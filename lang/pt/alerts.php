<?php

return [
    'subject' => '[:platform] :subject',
    'greeting' => 'Olá, :name,',
    'security_log' => 'Os detalhes (hora, endereço, navegador) estão no registo de segurança.',
    'reason' => 'Recebe este e-mail porque é administrador de :platform.',
    'events' => [
        'client_account_created' => [
            'subject' => 'Nova conta do portal do cliente',
            'body' => ':by criou a conta do portal do cliente «:account» (utilizador :username) para :company.',
        ],
        'client_account_all_audits' => [
            'subject' => 'Conta de cliente com todas as auditorias',
            'body' => ':by deu à conta do portal do cliente «:account» (utilizador :username, :company) acesso a todas as auditorias, incluindo as futuras.',
        ],
        'user_created' => [
            'subject' => 'Novo administrador',
            'body' => ':by criou uma nova conta de administrador: :name (utilizador :username).',
        ],
        'role_changed' => [
            'subject' => 'Novo administrador',
            'body' => ':by deu o papel de administrador a :name (utilizador :username).',
        ],
        'login_throttled' => [
            'subject' => 'Início de sessão bloqueado',
            'body' => 'O início de sessão foi bloqueado após demasiadas tentativas falhadas (último utilizador tentado: :username). Os outros bloqueios na hora seguinte não são enviados por e-mail.',
        ],
        'portal_login_throttled' => [
            'subject' => 'Início de sessão no portal do cliente bloqueado',
            'body' => 'O início de sessão no portal do cliente de :company foi bloqueado após demasiadas tentativas falhadas (último utilizador tentado: :username). Os outros bloqueios na hora seguinte não são enviados por e-mail.',
        ],
        'journal_broken' => [
            'subject' => 'Diário BE alterado',
            'body' => 'A verificação diária detetou que o diário do workflow BE foi alterado fora da plataforma (primeira entrada quebrada: :entry).',
        ],
    ],
];
