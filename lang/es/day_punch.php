<?php

return [
    'errors' => [
        'no_open_day' => 'No hay ninguna jornada en curso que terminar.',
        'previous_day_open' => 'La jornada anterior no está terminada: declare primero su fin.',
        'end_before_start' => 'La hora de fin debe ser posterior al inicio y no estar en el futuro.',
        'too_long' => 'Una jornada no puede durar más de 30 horas.',
    ],
    'gate' => [
        'title' => 'Empezar la jornada',
        'hint' => 'Su posición se registra en el momento del fichaje, solo para acreditar su presencia. Si rechaza la ubicación, la jornada se registra sin posición.',
        'privacy' => 'Solo usted y sus responsables pueden verla. Se borra después de :months meses; las horas se conservan.',
        'start' => 'Empezar la jornada',
    ],
    'end' => [
        'button' => 'Terminar la jornada',
        'started_at' => 'Inicio: :time',
        'declare_reminder' => 'Declarar el fin del :date',
    ],
    'declare' => [
        'title' => 'Declarar el fin',
        'text' => 'La jornada del :date, empezada a las :time, no está terminada. Indique la hora a la que terminó.',
        'time' => 'Hora de fin',
        'submit' => 'Declarar el fin',
        'cancel' => 'Cancelar',
    ],
    'toast' => [
        'declared' => 'Fin de jornada declarado.',
    ],
    'js' => [
        'locating' => 'Localizando…',
        'started' => 'Jornada empezada a las :time.',
        'ended' => 'Jornada terminada a las :time.',
        'queued' => 'Sin red: el registro se enviará en cuanto vuelva la conexión.',
        'network_error' => 'No se pudo guardar. Inténtelo de nuevo.',
        'end_confirm' => '¿Terminar la jornada empezada a las :time?',
        'denied_hint' => 'Ubicación rechazada: la jornada se registra sin posición.',
    ],
    'pin' => [
        'start' => 'Inicio',
        'end' => 'Fin',
        'declared' => 'Fin declarado por la persona (sin posición)',
        'no_position' => 'Posición no disponible',
        'open_map' => 'Abrir la posición',
        'running' => 'En curso',
        'missing_end' => 'Fin no registrado',
        'imprecise' => 'Posición imprecisa (± :metres m)',
    ],
];
