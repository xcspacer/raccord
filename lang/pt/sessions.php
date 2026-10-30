<?php

return [
    'title' => 'Sessões ativas',
    'this_device' => 'Este dispositivo',
    'last_active' => 'Última atividade: :date',
    'unknown_device' => 'Dispositivo desconhecido',
    'revoke' => 'Terminar sessão',
    'revoke_others' => 'Terminar sessão nos outros dispositivos',
    'revoke_all' => 'Terminar sessão em todo o lado',
    'confirm_revoke_others' => 'Terminar a sessão em todos os seus outros dispositivos?',
    'confirm_revoke_all' => 'Terminar a sessão de :name em todos os dispositivos?',
    'revoked' => 'Sessão do dispositivo terminada.',
    'revoked_all' => '{0} Nenhum outro dispositivo com sessão aberta.|{1} Sessão terminada em :count dispositivo.|[2,*] Sessão terminada em :count dispositivos.',
    'none' => 'Nenhuma sessão aberta.',
    'end_messages' => [
        'session_revoked' => 'Esta sessão foi terminada a partir de outro dispositivo.',
        'user_disabled' => 'Esta conta já não está ativa.',
        'unauthenticated' => 'Volte a iniciar sessão.',
    ],
    'end_reasons' => [
        'session_revoked' => 'Sessão terminada remotamente',
        'user_disabled' => 'Conta desativada',
    ],
    'confirm_password' => [
        'title' => 'Confirme a sua palavra-passe',
        'hint' => 'Por segurança, confirme a sua palavra-passe para continuar.',
        'password' => 'Palavra-passe',
        'confirm' => 'Confirmar',
        'cancel' => 'Cancelar',
        'invalid' => 'Palavra-passe incorreta.',
    ],
];
