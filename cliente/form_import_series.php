<?php

require __DIR__ . '/includes/import_form_page.php';

renderImportFormPage([
    'action_endpoint' => 'series',
    'status_endpoint' => 'series_status',
    'current_nav_key' => 'series',
    'resource' => [
        'singular' => 'série',
        'plural' => 'séries',
        'capitalized_plural' => 'Séries',
        'upper_plural' => 'SÉRIES',
    ],
    'summary_list' => [
        'Número de episódios adicionados com sucesso',
        'Episódios ignorados por já existirem',
        'Eventuais erros encontrados durante o processo',
    ],
    'data_mutation_description' => 'novas categorias, séries e episódios',
    'job' => [
        'state_titles' => [
            'running' => 'Processando séries e episódios',
        ],
        'messages' => [
            'queued' => null,
            'running' => 'Processando séries e episódios...',
            'done' => null,
        ],
        'job_created_message_template' => null,
        'totals_labels' => [
            'added' => 'Episódios adicionados',
            'skipped' => 'Episódios ignorados',
            'errors' => 'Ocorrências',
        ],
    ],
    'faq_overrides' => [
        2 => [
            'answer_html' => '<p>É um importador profissional de listas de séries para o <strong>XUI.ONE</strong>. O sistema também cadastra automaticamente as categorias correspondentes, cria as séries e relaciona cada episódio, evitando duplicações e garantindo uma importação limpa e organizada.</p>',
        ],
    ],
]);
