<?php

require __DIR__ . '/includes/import_form_page.php';

renderImportFormPage([
    'action_endpoint' => 'filmes',
    'status_endpoint' => 'filmes_status',
    'current_nav_key' => 'filmes',
    'resource' => [
        'singular' => 'filme',
        'plural' => 'filmes',
        'capitalized_plural' => 'Filmes',
        'upper_plural' => 'FILMES',
    ],
    'summary_list' => [
        'Número de filmes adicionados com sucesso',
        'Filmes ignorados por já existirem',
        'Eventuais erros encontrados durante o processo',
    ],
    'data_mutation_description' => 'novas categorias e filmes',
    'job' => [
        'state_titles' => [
            'running' => 'Processando filmes',
        ],
        'messages' => [
            'queued' => null,
            'running' => 'Processando filmes...',
            'done' => null,
        ],
        'job_created_message_template' => null,
        'totals_labels' => [
            'added' => 'Filmes adicionados',
            'skipped' => 'Filmes ignorados',
            'errors' => 'Erros',
        ],
    ],
]);
