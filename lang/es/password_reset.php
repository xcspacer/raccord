<?php

return [
    'forgot_link' => '¿Ha olvidado su contraseña?',
    'request_title' => 'Contraseña olvidada',
    'request_intro' => 'Introduzca la dirección de correo de su cuenta: le enviaremos un enlace para elegir una nueva contraseña.',
    'email' => 'Correo electrónico',
    'no_email_hint' => '¿Su cuenta no tiene correo electrónico? Pida a un administrador que restablezca su contraseña.',
    'request_submit' => 'Enviar el enlace',
    'back_to_login' => 'Volver al inicio de sesión',
    'reset_title' => 'Nueva contraseña',
    'reset_intro' => 'Elija una nueva contraseña. Después tendrá que volver a iniciar sesión en todos sus dispositivos.',
    'minimum' => 'Al menos 10 caracteres (12 para los administradores).',
    'reset_submit' => 'Guardar la contraseña',
    'mail' => [
        'subject' => '[:platform] Restablecer su contraseña',
        'greeting' => 'Hola :name:',
        'body' => 'Se ha solicitado una nueva contraseña para su cuenta de :platform (usuario :username).',
        'action' => 'Elegir una nueva contraseña',
        'expiry' => 'Este enlace caduca en :count minutos.',
        'ignore' => 'Si no lo ha solicitado, ignore este correo: su contraseña no cambia.',
    ],
];
