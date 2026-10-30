<?php

return [
    'home' => 'Retour à l’accueil',
    'empty_response_question' => 'Le serveur a renvoyé une réponse vide. Voulez-vous recharger la page ?',
    '401' => [
        'title' => 'Connexion requise',
        'message' => 'Connectez-vous pour ouvrir cette page.',
    ],
    '403' => [
        'title' => 'Accès refusé',
        'message' => 'Vous n’avez pas le droit d’ouvrir cette page.',
    ],
    '404' => [
        'title' => 'Page introuvable',
        'message' => 'Cette page n’existe pas ou n’est plus disponible.',
    ],
    '419' => [
        'title' => 'Session expirée',
        'message' => 'Votre page a expiré. Rechargez-la et réessayez.',
        'reload_question' => 'Cette page a expiré. Voulez-vous la recharger ?',
    ],
    '429' => [
        'title' => 'Trop de requêtes',
        'message' => 'Patientez un instant avant de réessayer.',
    ],
    '500' => [
        'title' => 'Une erreur est survenue',
        'message' => 'Une erreur inattendue s’est produite. Réessayez plus tard.',
    ],
    '503' => [
        'title' => 'Service indisponible',
        'message' => 'Le service est momentanément indisponible. Réessayez dans quelques minutes.',
    ],
    'zip_busy' => [
        'title' => 'Téléchargement en cours',
        'message' => 'Un téléchargement ZIP est déjà en cours pour votre compte. Attendez qu’il se termine, puis réessayez.',
    ],
    'zip_too_large' => [
        'title' => 'Trop de fichiers',
        'message' => 'Cette sélection contient trop de fichiers pour un seul ZIP. Choisissez moins de dossiers et réessayez.',
    ],
    'upload_quota' => 'Vous avez atteint la limite d’envoi du jour. L’envoi refonctionnera demain ; demandez à un administrateur si vous avez besoin de plus.',
    'generic' => [
        'title' => 'Une erreur est survenue',
        'message' => 'La demande n’a pas pu aboutir.',
    ],
];
