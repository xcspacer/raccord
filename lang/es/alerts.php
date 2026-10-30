<?php

return [
    'subject' => '[:platform] :subject',
    'greeting' => 'Hola, :name:',
    'security_log' => 'Los detalles (hora, dirección, navegador) están en el registro de seguridad.',
    'reason' => 'Recibe este correo porque es administrador de :platform.',
    'events' => [
        'client_account_created' => [
            'subject' => 'Nueva cuenta del portal de clientes',
            'body' => ':by ha creado la cuenta del portal de clientes «:account» (usuario :username) para :company.',
        ],
        'client_account_all_audits' => [
            'subject' => 'Cuenta de cliente con todas las auditorías',
            'body' => ':by ha dado a la cuenta del portal de clientes «:account» (usuario :username, :company) acceso a todas las auditorías, incluidas las futuras.',
        ],
        'user_created' => [
            'subject' => 'Nuevo administrador',
            'body' => ':by ha creado una nueva cuenta de administrador: :name (usuario :username).',
        ],
        'role_changed' => [
            'subject' => 'Nuevo administrador',
            'body' => ':by ha dado el rol de administrador a :name (usuario :username).',
        ],
        'login_throttled' => [
            'subject' => 'Inicio de sesión bloqueado',
            'body' => 'El inicio de sesión se ha bloqueado tras demasiados intentos fallidos (último usuario probado: :username). Los demás bloqueos de la hora siguiente no se envían por correo.',
        ],
        'portal_login_throttled' => [
            'subject' => 'Inicio de sesión en el portal de clientes bloqueado',
            'body' => 'El inicio de sesión en el portal de clientes de :company se ha bloqueado tras demasiados intentos fallidos (último usuario probado: :username). Los demás bloqueos de la hora siguiente no se envían por correo.',
        ],
        'journal_broken' => [
            'subject' => 'Diario de la oficina técnica alterado',
            'body' => 'La comprobación diaria detectó que el diario del flujo de la oficina técnica se modificó fuera de la plataforma (primera entrada rota: :entry).',
        ],
    ],
];
