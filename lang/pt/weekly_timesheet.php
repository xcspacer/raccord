<?php

return [
    'sheet' => 'Ponto S:week',
    'title' => 'PONTO SEMANAL — EQUIPA :team',
    'week' => 'Semana :week · de :from a :to',
    'columns' => [
        'week' => 'Semana',
        'date' => 'Data',
        'technician' => 'Técnico',
        'work' => 'Trabalhos efetuados',
        'start' => 'Início do dia',
        'end' => 'Fim do dia',
    ],
    'departed' => 'Saiu da empresa',
    'note' => 'Nota: :note',
    'in_progress' => 'Em curso',
    'not_finished' => 'Não terminada',
    'generated' => 'Gerado em :date por :user · a partir do planeamento de :app (tarefas, ausências e registos de ponto dos técnicos; * = hora de fim declarada pelo técnico).',
    'location_notice' => 'Dados de localização — uso interno. ⚠ = posição menos precisa do que :metres m.',
    'file' => 'ponto-semanal-S:week-:monday',
    'button' => 'Ponto semanal (Excel)',
];
