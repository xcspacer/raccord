<?php

return [
    'forgot_link' => 'Mot de passe oublié ?',
    'request_title' => 'Mot de passe oublié',
    'request_intro' => 'Saisissez l\'adresse e-mail de votre compte : nous vous enverrons un lien pour choisir un nouveau mot de passe.',
    'email' => 'E-mail',
    'no_email_hint' => 'Pas d\'adresse e-mail sur votre compte ? Demandez à un administrateur de réinitialiser votre mot de passe.',
    'request_submit' => 'Envoyer le lien',
    'back_to_login' => 'Retour à la connexion',
    'reset_title' => 'Nouveau mot de passe',
    'reset_intro' => 'Choisissez un nouveau mot de passe. Vous devrez ensuite vous reconnecter sur tous vos appareils.',
    'reset_submit' => 'Enregistrer le mot de passe',
    'mail' => [
        'subject' => '[:platform] Réinitialisation de votre mot de passe',
        'greeting' => 'Bonjour :name,',
        'body' => 'Un nouveau mot de passe a été demandé pour votre compte :platform (identifiant :username).',
        'action' => 'Choisir un nouveau mot de passe',
        'expiry' => 'Ce lien expire dans :count minutes.',
        'ignore' => 'Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet e-mail : votre mot de passe reste inchangé.',
    ],
];
