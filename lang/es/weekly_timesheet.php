<?php

return [
    'sheet' => 'Fichaje S:week',
    'title' => 'FICHAJE SEMANAL — EQUIPO :team',
    'week' => 'Semana :week · del :from al :to',
    'columns' => [
        'week' => 'Semana',
        'date' => 'Fecha',
        'technician' => 'Técnico',
        'work' => 'Trabajos realizados',
        'start' => 'Inicio de jornada',
        'end' => 'Fin de jornada',
    ],
    'departed' => 'Ha dejado la empresa',
    'note' => 'Nota: :note',
    'in_progress' => 'En curso',
    'not_finished' => 'No terminada',
    'generated' => 'Generado el :date por :user · según el planning de :app (tareas, ausencias y fichajes de los técnicos; * = hora de fin declarada por el técnico).',
    'location_notice' => 'Datos de localización — uso interno. ⚠ = posición menos precisa que :metres m.',
    'file' => 'fichaje-semanal-S:week-:monday',
    'button' => 'Fichaje semanal (Excel)',
];
