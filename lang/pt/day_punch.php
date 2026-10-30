<?php

return [
    'errors' => [
        'no_open_day' => 'Não há nenhum dia em curso para terminar.',
        'previous_day_open' => 'O dia anterior não está terminado: declare primeiro o seu fim.',
        'end_before_start' => 'A hora de fim tem de ser depois do início e não pode estar no futuro.',
        'too_long' => 'Um dia não pode durar mais de 30 horas.',
    ],
    'gate' => [
        'title' => 'Começar o dia',
        'hint' => 'A sua posição é registada no momento em que começa, só para comprovar a sua presença. Se recusar a localização, o dia é registado sem posição.',
        'privacy' => 'Só você e os seus responsáveis a veem. É apagada após :months meses; as horas são mantidas.',
        'start' => 'Começar o dia',
    ],
    'end' => [
        'button' => 'Terminar o dia',
        'started_at' => 'Início: :time',
        'declare_reminder' => 'Declarar o fim de :date',
    ],
    'declare' => [
        'title' => 'Declarar o fim',
        'text' => 'O dia de :date, começado às :time, não está terminado. Indique a hora a que terminou.',
        'time' => 'Hora de fim',
        'submit' => 'Declarar o fim',
        'cancel' => 'Cancelar',
    ],
    'toast' => [
        'declared' => 'Fim do dia declarado.',
    ],
    'js' => [
        'locating' => 'A localizar…',
        'started' => 'Dia começado às :time.',
        'ended' => 'Dia terminado às :time.',
        'queued' => 'Sem rede: o registo será enviado assim que a ligação voltar.',
        'network_error' => 'Não foi possível guardar. Tente novamente.',
        'end_confirm' => 'Terminar o dia começado às :time?',
        'denied_hint' => 'Localização recusada: o dia é registado sem posição.',
    ],
    'pin' => [
        'start' => 'Início',
        'end' => 'Fim',
        'declared' => 'Fim declarado pela pessoa (sem posição)',
        'no_position' => 'Posição indisponível',
        'open_map' => 'Abrir a posição',
        'running' => 'Em curso',
        'missing_end' => 'Fim não registado',
        'imprecise' => 'Posição imprecisa (± :metres m)',
    ],
];
