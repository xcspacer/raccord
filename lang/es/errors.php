<?php

return [
    'home' => 'Volver al inicio',
    'empty_response_question' => 'El servidor ha devuelto una respuesta vacía. ¿Desea volver a cargar la página?',
    '401' => [
        'title' => 'Inicio de sesión necesario',
        'message' => 'Inicie sesión para abrir esta página.',
    ],
    '403' => [
        'title' => 'Acceso denegado',
        'message' => 'No tiene permiso para abrir esta página.',
    ],
    '404' => [
        'title' => 'Página no encontrada',
        'message' => 'Esta página no existe o ya no está disponible.',
    ],
    '419' => [
        'title' => 'Sesión caducada',
        'message' => 'Su página ha caducado. Vuelva a cargarla e inténtelo de nuevo.',
        'reload_question' => 'Esta página ha caducado. ¿Desea volver a cargarla?',
    ],
    '429' => [
        'title' => 'Demasiadas solicitudes',
        'message' => 'Espere un momento antes de volver a intentarlo.',
    ],
    '500' => [
        'title' => 'Algo ha salido mal',
        'message' => 'Se ha producido un error inesperado. Inténtelo de nuevo más tarde.',
    ],
    '503' => [
        'title' => 'Servicio no disponible',
        'message' => 'El servicio no está disponible temporalmente. Inténtelo de nuevo en unos minutos.',
    ],
    'zip_busy' => [
        'title' => 'Descarga en curso',
        'message' => 'Ya hay una descarga ZIP en curso para su cuenta. Espere a que termine y vuelva a intentarlo.',
    ],
    'zip_too_large' => [
        'title' => 'Demasiados archivos',
        'message' => 'Esta selección contiene demasiados archivos para un solo ZIP. Elija menos carpetas y vuelva a intentarlo.',
    ],
    'upload_quota' => 'Ha alcanzado el límite de envíos de hoy. Podrá volver a enviar mañana; pida a un administrador si necesita más.',
    'generic' => [
        'title' => 'Algo ha salido mal',
        'message' => 'No se ha podido completar la solicitud.',
    ],
];
