<?php

return [
    'title' => 'Sesiones activas',
    'this_device' => 'Este dispositivo',
    'last_active' => 'Última actividad: :date',
    'unknown_device' => 'Dispositivo desconocido',
    'revoke' => 'Cerrar sesión',
    'revoke_others' => 'Cerrar sesión en los demás dispositivos',
    'revoke_all' => 'Cerrar sesión en todas partes',
    'confirm_revoke_others' => '¿Cerrar sesión en todos sus otros dispositivos?',
    'confirm_revoke_all' => '¿Cerrar la sesión de :name en todos sus dispositivos?',
    'revoked' => 'Sesión del dispositivo cerrada.',
    'revoked_all' => '{0} Ningún otro dispositivo con sesión abierta.|{1} Sesión cerrada en :count dispositivo.|[2,*] Sesión cerrada en :count dispositivos.',
    'none' => 'Ninguna sesión abierta.',
    'end_messages' => [
        'session_revoked' => 'Esta sesión se cerró desde otro dispositivo.',
        'user_disabled' => 'Esta cuenta ya no está activa.',
        'unauthenticated' => 'Vuelva a iniciar sesión.',
    ],
    'end_reasons' => [
        'session_revoked' => 'Sesión cerrada a distancia',
        'user_disabled' => 'Cuenta desactivada',
    ],
    'confirm_password' => [
        'title' => 'Confirme su contraseña',
        'hint' => 'Por seguridad, confirme su contraseña para continuar.',
        'password' => 'Contraseña',
        'confirm' => 'Confirmar',
        'cancel' => 'Cancelar',
        'invalid' => 'Contraseña incorrecta.',
    ],
];
