<?php

return [
    'home' => 'Voltar ao início',
    'empty_response_question' => 'O servidor devolveu uma resposta vazia. Pretende voltar a carregar a página?',
    '401' => [
        'title' => 'Início de sessão necessário',
        'message' => 'Inicie sessão para abrir esta página.',
    ],
    '403' => [
        'title' => 'Acesso negado',
        'message' => 'Não tem permissão para abrir esta página.',
    ],
    '404' => [
        'title' => 'Página não encontrada',
        'message' => 'Esta página não existe ou já não está disponível.',
    ],
    '419' => [
        'title' => 'Sessão expirada',
        'message' => 'A página expirou. Volte a carregá-la e tente de novo.',
        'reload_question' => 'Esta página expirou. Pretende voltar a carregá-la?',
    ],
    '429' => [
        'title' => 'Demasiados pedidos',
        'message' => 'Aguarde um momento antes de tentar de novo.',
    ],
    '500' => [
        'title' => 'Algo correu mal',
        'message' => 'Ocorreu um erro inesperado. Tente novamente mais tarde.',
    ],
    '503' => [
        'title' => 'Serviço indisponível',
        'message' => 'O serviço está temporariamente indisponível. Tente novamente dentro de alguns minutos.',
    ],
    'zip_busy' => [
        'title' => 'Transferência em curso',
        'message' => 'Já existe uma transferência ZIP em curso para a sua conta. Aguarde que termine e tente novamente.',
    ],
    'zip_too_large' => [
        'title' => 'Demasiados ficheiros',
        'message' => 'Esta seleção tem demasiados ficheiros para um só ZIP. Escolha menos pastas e tente novamente.',
    ],
    'upload_quota' => 'Atingiu o limite de envios de hoje. Poderá voltar a enviar amanhã; peça a um administrador se precisar de mais.',
    'generic' => [
        'title' => 'Algo correu mal',
        'message' => 'Não foi possível concluir o pedido.',
    ],
];
