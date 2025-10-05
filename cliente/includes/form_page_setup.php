<?php

declare(strict_types=1);

if (!function_exists('importador_apply_default_php_settings')) {
    function importador_apply_default_php_settings(): void
    {
        ini_set('memory_limit', '512M');
        set_time_limit(0);
        ini_set('upload_max_filesize', '20M');
        ini_set('post_max_size', '25M');
    }
}

if (!function_exists('importador_build_local_url')) {
    /**
     * @param array<string, string|int> $params
     */
    function importador_build_local_url(string $script, array $params = []): string
    {
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        $directory = str_replace('\\', '/', dirname($scriptPath));

        if ($directory === '/' || $directory === '\\' || $directory === '.') {
            $directory = '';
        } else {
            $directory = rtrim($directory, '/');
        }

        $url = ($directory === '' ? '' : $directory) . '/' . ltrim($script, '/');

        if (!empty($params)) {
            $queryString = http_build_query($params);
            if ($queryString !== '') {
                $url .= '?' . $queryString;
            }
        }

        return $url;
    }
}

if (!function_exists('importador_get_form_type_config')) {
    /**
     * @return array{
     *     nav_key: string,
     *     action_endpoint: string,
     *     status_endpoint: string,
     *     type_highlight: string,
     *     faq_first_answer: string,
     *     summary_items: string[],
     *     final_faq_answer: string,
     *     state_titles: array<string, string>,
     *     messages: array<string, string>,
     *     totals_labels: array<string, string>
     * }
     */
    function importador_get_form_type_config(string $type): array
    {
        $configs = [
            'canais' => [
                'nav_key' => 'canais',
                'action_endpoint' => 'canais',
                'status_endpoint' => 'canais_status',
                'type_highlight' => 'CANAIS',
                'faq_first_answer' => 'É um importador profissional de listas de canais para o <strong>XUI.ONE</strong>. O sistema também cadastra automaticamente as categorias correspondentes aos canais e evita duplicações, garantindo uma importação limpa e organizada.',
                'summary_items' => [
                    'Número de canais adicionados com sucesso',
                    'Canais ignorados por já existirem',
                    'Eventuais erros encontrados durante o processo',
                ],
                'final_faq_answer' => '<strong>Não.</strong> O importador apenas <strong>insere</strong> novas categorias e canais. O sistema não possui permissões para alterar ou remover dados existentes, garantindo a segurança do seu banco de dados.',
                'state_titles' => [
                    'running' => 'Processando canais',
                ],
                'messages' => [
                    'queued' => 'Job de canais aguardando processamento...',
                    'running' => 'Processando canais...',
                    'done' => 'Importação de canais finalizada.',
                    'jobCreated' => 'Job de canais #%d criado com sucesso. O processamento será iniciado em breve.',
                ],
                'totals_labels' => [
                    'added' => 'Canais adicionados',
                    'skipped' => 'Canais ignorados',
                    'errors' => 'Erros',
                ],
            ],
            'filmes' => [
                'nav_key' => 'filmes',
                'action_endpoint' => 'filmes',
                'status_endpoint' => 'filmes_status',
                'type_highlight' => 'FILMES',
                'faq_first_answer' => 'É um importador profissional de listas de filmes para o <strong>XUI.ONE</strong>. O sistema também cadastra automaticamente as categorias correspondentes aos filmes e evita duplicações, garantindo uma importação limpa e organizada.',
                'summary_items' => [
                    'Número de filmes adicionados com sucesso',
                    'Filmes ignorados por já existirem',
                    'Eventuais erros encontrados durante o processo',
                ],
                'final_faq_answer' => '<strong>Não.</strong> O importador apenas <strong>insere</strong> novas categorias e filmes. O sistema não possui permissões para alterar ou remover dados existentes, garantindo a segurança do seu banco de dados.',
                'state_titles' => [
                    'running' => 'Processando filmes',
                ],
                'messages' => [
                    'running' => 'Processando filmes...',
                ],
                'totals_labels' => [
                    'added' => 'Filmes adicionados',
                    'skipped' => 'Filmes ignorados',
                    'errors' => 'Erros',
                ],
            ],
            'series' => [
                'nav_key' => 'series',
                'action_endpoint' => 'series',
                'status_endpoint' => 'series_status',
                'type_highlight' => 'SÉRIES',
                'faq_first_answer' => 'É um importador profissional de listas de séries para o <strong>XUI.ONE</strong>. O sistema também cadastra automaticamente as categorias correspondentes, cria as séries e relaciona cada episódio, evitando duplicações e garantindo uma importação limpa e organizada.',
                'summary_items' => [
                    'Número de episódios adicionados com sucesso',
                    'Episódios ignorados por já existirem',
                    'Eventuais erros encontrados durante o processo',
                ],
                'final_faq_answer' => '<strong>Não.</strong> O importador apenas <strong>insere</strong> novas categorias, séries e episódios. O sistema não possui permissões para alterar ou remover dados existentes, garantindo a segurança do seu banco de dados.',
                'state_titles' => [
                    'running' => 'Processando séries e episódios',
                ],
                'messages' => [
                    'running' => 'Processando séries e episódios...',
                ],
                'totals_labels' => [
                    'added' => 'Episódios adicionados',
                    'skipped' => 'Episódios ignorados',
                    'errors' => 'Ocorrências',
                ],
            ],
        ];

        if (!isset($configs[$type])) {
            throw new InvalidArgumentException('Tipo de formulário inválido: ' . $type);
        }

        return $configs[$type];
    }
}

if (!function_exists('importador_prepare_form_context')) {
    /**
     * @param array<string, mixed> $postData
     * @return array{
     *     buildLocalUrl: callable(string, array=): string,
     *     currentNavKey: string,
     *     actionUrl: string,
     *     statusUrl: string,
     *     host: string,
     *     dbname: string,
     *     username: string,
     *     password: string,
     *     m3u_url: string,
     *     typeHighlight: string,
     *     faqFirstAnswer: string,
     *     summaryItems: string[],
     *     finalFaqAnswer: string,
     *     controllerStateTitles: array<string, string>,
     *     controllerMessages: array<string, string>,
     *     controllerTotalsLabels: array<string, string>
     * }
     */
    function importador_prepare_form_context(string $type, array $postData): array
    {
        $config = importador_get_form_type_config($type);

        $actionUrl = importador_build_local_url('api_proxy.php', ['endpoint' => $config['action_endpoint']]);
        $statusUrl = importador_build_local_url('api_proxy.php', ['endpoint' => $config['status_endpoint']]);

        return [
            'buildLocalUrl' => static function (string $script = '', array $params = []) {
                return importador_build_local_url($script, $params);
            },
            'currentNavKey' => $config['nav_key'],
            'actionUrl' => $actionUrl,
            'statusUrl' => $statusUrl,
            'host' => trim((string)($postData['host'] ?? '')),
            'dbname' => (string)($postData['dbname'] ?? 'xui'),
            'username' => trim((string)($postData['username'] ?? '')),
            'password' => (string)($postData['password'] ?? ''),
            'm3u_url' => trim((string)($postData['m3u_url'] ?? '')),
            'typeHighlight' => $config['type_highlight'],
            'faqFirstAnswer' => $config['faq_first_answer'],
            'summaryItems' => $config['summary_items'],
            'finalFaqAnswer' => $config['final_faq_answer'],
            'controllerStateTitles' => $config['state_titles'],
            'controllerMessages' => $config['messages'],
            'controllerTotalsLabels' => $config['totals_labels'],
        ];
    }
}

if (!function_exists('importador_bootstrap_form_context')) {
    /**
     * @param array<string, mixed> $postData
     * @return array<string, mixed>
     */
    function importador_bootstrap_form_context(string $type, array $postData): array
    {
        importador_apply_default_php_settings();

        return importador_prepare_form_context($type, $postData);
    }
}
