<?php

return [
    'errors' => [
        'no_open_day' => 'Aucune journée en cours à terminer.',
        'previous_day_open' => 'La journée précédente n\'est pas terminée : déclarez d\'abord sa fin.',
        'end_before_start' => 'L\'heure de fin doit être après le début et ne pas être dans le futur.',
        'too_long' => 'Une journée ne peut pas durer plus de 30 heures.',
    ],
    'gate' => [
        'title' => 'Commencer la journée',
        'hint' => 'Votre position est enregistrée à l\'instant du pointage, uniquement pour attester votre présence. Si la localisation est refusée, la journée est enregistrée sans position.',
        'privacy' => 'Seuls vous et vos responsables la voient. Elle est effacée après :months mois ; les heures sont conservées.',
        'start' => 'Commencer la journée',
    ],
    'end' => [
        'button' => 'Finir la journée',
        'started_at' => 'Début : :time',
        'declare_reminder' => 'Déclarer la fin du :date',
    ],
    'declare' => [
        'title' => 'Déclarer la fin',
        'text' => 'La journée du :date, commencée à :time, n\'est pas terminée. Indiquez l\'heure à laquelle elle s\'est terminée.',
        'time' => 'Heure de fin',
        'submit' => 'Déclarer la fin',
        'cancel' => 'Annuler',
    ],
    'toast' => [
        'declared' => 'Fin de journée déclarée.',
    ],
    'js' => [
        'locating' => 'Localisation…',
        'started' => 'Journée commencée à :time.',
        'ended' => 'Journée terminée à :time.',
        'queued' => 'Pas de réseau : l\'enregistrement sera envoyé dès le retour de la connexion.',
        'network_error' => 'Impossible d\'enregistrer. Réessayez.',
        'end_confirm' => 'Terminer la journée commencée à :time ?',
        'denied_hint' => 'Localisation refusée : la journée est enregistrée sans position.',
    ],
    'pin' => [
        'start' => 'Début',
        'end' => 'Fin',
        'declared' => 'Fin déclarée par la personne (sans position)',
        'no_position' => 'Position indisponible',
        'open_map' => 'Ouvrir la position',
        'running' => 'En cours',
        'missing_end' => 'Fin non enregistrée',
        'imprecise' => 'Position imprécise (± :metres m)',
    ],
];
