<?php

return [
    'sheet' => 'Pointage S:week',
    'title' => 'POINTAGE HEBDOMADAIRE — ÉQUIPE :team',
    'week' => 'Semaine :week · du :from au :to',
    'columns' => [
        'week' => 'Semaine',
        'date' => 'Date',
        'technician' => 'Technicien',
        'work' => 'Travaux effectués',
        'start' => 'Début de journée',
        'end' => 'Fin de journée',
    ],
    'departed' => 'Parti(e) de l’entreprise',
    'note' => 'Note : :note',
    'in_progress' => 'En cours',
    'not_finished' => 'Non terminée',
    'generated' => 'Généré le :date par :user · d’après le planning :app (tâches, absences et pointages des techniciens ; * = heure de fin déclarée par le technicien).',
    'location_notice' => 'Données de localisation — usage interne. ⚠ = position moins précise que :metres m.',
    'file' => 'pointage-hebdo-S:week-:monday',
    'button' => 'Pointage hebdo (Excel)',
];
