<?php

declare(strict_types=1);

/**
 * Renderiza a página de importação com base nas configurações fornecidas.
 *
 * @param array{
 *     action_endpoint?: string,
 *     status_endpoint?: string,
 *     current_nav_key?: string,
 *     resource?: array{
 *         singular?: string,
 *         plural?: string,
 *         capitalized_plural?: string,
 *         upper_plural?: string
 *     },
 *     summary_list?: string[],
 *     data_mutation_description?: string,
 *     faq_overrides?: array<int, array{
 *         question?: string,
 *         answer_html?: string
 *     }>,
 *     job?: array{
 *         state_titles?: array<string, string>,
 *         messages?: array<string, ?string>,
 *         job_created_message_template?: ?string,
 *         totals_labels?: array<string, string>
 *     }
 * } $config
 */
function renderImportFormPage(array $config): void
{
    ini_set('memory_limit', '512M');
    set_time_limit(0);
    ini_set('upload_max_filesize', '20M');
    ini_set('post_max_size', '25M');

    $buildLocalUrl = static function (string $script, array $params = []) {
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
        $scriptPath = str_replace('\\\\', '/', $scriptPath);

        $scriptDirectory = str_replace('\\\\', '/', dirname($scriptPath));
        $baseDirectory = $scriptDirectory;

        if ($scriptPath !== '' && substr($scriptPath, -10) === '/index.php') {
            $baseDirectory = str_replace('\\\\', '/', dirname($scriptDirectory));
        }

        if ($baseDirectory === '/' || $baseDirectory === '\\' || $baseDirectory === '.') {
            $baseDirectory = '';
        } else {
            $baseDirectory = rtrim($baseDirectory, '/');
        }

        $url = ($baseDirectory === '' ? '' : $baseDirectory) . '/' . ltrim($script, '/');

        if (!empty($params)) {
            $queryString = http_build_query($params);
            if ($queryString !== '') {
                $url .= '?' . $queryString;
            }
        }

        return $url;
    };

    $resourceDefaults = [
        'singular' => 'canal',
        'plural' => 'canais',
        'capitalized_plural' => 'Canais',
        'upper_plural' => 'CANAIS',
    ];

    $resource = array_merge($resourceDefaults, $config['resource'] ?? []);

    $resourceCapitalized = htmlspecialchars($resource['capitalized_plural'], ENT_QUOTES, 'UTF-8');
    $resourcePluralLowerRaw = $resource['plural'];
    if (function_exists('mb_strtolower')) {
        $resourcePluralLowerRaw = mb_strtolower($resourcePluralLowerRaw, 'UTF-8');
    } else {
        $resourcePluralLowerRaw = strtolower($resourcePluralLowerRaw);
    }
    $resourcePluralLower = htmlspecialchars($resourcePluralLowerRaw, ENT_QUOTES, 'UTF-8');

    $actionEndpoint = $config['action_endpoint'] ?? 'canais';
    $statusEndpoint = $config['status_endpoint'] ?? ($actionEndpoint . '_status');

    $actionUrl = $buildLocalUrl('api_proxy.php', ['endpoint' => $actionEndpoint]);
    $statusUrl = $buildLocalUrl('api_proxy.php', ['endpoint' => $statusEndpoint]);

    $currentNavKey = $config['current_nav_key'] ?? $resource['plural'];

    $host = $_POST['host'] ?? '';
    $dbname = $_POST['dbname'] ?? 'xui';
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $m3uUrl = $_POST['m3u_url'] ?? '';

    $summaryList = $config['summary_list'] ?? [
        sprintf('Número de %s adicionados com sucesso', $resource['plural']),
        sprintf('%s ignorados por já existirem', ucfirst($resource['plural'])),
        'Eventuais erros encontrados durante o processo',
    ];

    $dataMutationDescription = $config['data_mutation_description'] ?? sprintf('novas categorias e %s', $resource['plural']);

    $defaultJobConfig = [
        'state_titles' => [
            'running' => sprintf('Processando %s', $resource['plural']),
        ],
        'messages' => [
            'queued' => sprintf('Job de %s aguardando processamento...', $resource['plural']),
            'running' => sprintf('Processando %s...', $resource['plural']),
            'done' => sprintf('Importação de %s finalizada.', $resource['plural']),
        ],
        'job_created_message_template' => sprintf('Job de %s #{jobId} criado com sucesso. O processamento será iniciado em breve.', $resource['plural']),
        'totals_labels' => [
            'added' => sprintf('%s adicionados', $resource['capitalized_plural']),
            'skipped' => sprintf('%s ignorados', $resource['capitalized_plural']),
            'errors' => 'Erros',
        ],
    ];

    $jobConfig = array_replace_recursive($defaultJobConfig, $config['job'] ?? []);

    $jobStateTitles = $jobConfig['state_titles'] ?? [];
    $jobMessages = $jobConfig['messages'] ?? [];
    $jobMessages = array_filter(
        $jobMessages,
        static fn($message) => $message !== null && $message !== ''
    );
    $jobCreatedTemplate = $jobConfig['job_created_message_template'] ?? null;
    if ($jobCreatedTemplate === null) {
        // nothing to do
    }
    $jobTotalsLabels = $jobConfig['totals_labels'] ?? [];

    $faqAnswers = [];

    $faqAnswers[] = [
        'question' => 'Como criar um usuário no banco de dados?',
        'answer_html' => <<<HTML
<p><strong>Siga estes passos para criar um novo usuário no seu banco de dados:</strong></p>

<p><strong>1.</strong> Acesse sua máquina do <strong>XUI.ONE</strong> via terminal.</p>

<p><strong>2.</strong> Execute o seguinte comando SQL para acessar o MySQL:</p>
<pre><code>mysql -u root -p</code></pre>

<p><strong>3.</strong> Crie o usuário e senha desejados:</p>
<pre><code>CREATE USER 'novo_usuario'@'%' IDENTIFIED BY 'senha_segura';</code></pre>

<p><strong>4.</strong> Conceda privilégios ao usuário:</p>
<pre><code>GRANT ALL PRIVILEGES ON *.* TO 'novo_usuario'@'%';</code></pre>

<p><strong>5.</strong> Aplique as alterações:</p>
<pre><code>FLUSH PRIVILEGES;</code></pre>

<p><strong>Nota:</strong> Substitua <code>'novo_usuario'</code> e <code>'senha_segura'</code> pelos valores apropriados.</p>
HTML
    ];

    $faqAnswers[] = [
        'question' => 'Minha conexão e meus dados ficam salvos no sistema?',
        'answer_html' => <<<HTML
<p>Não. O Separa M3U processa todas as informações em tempo de execução, ou seja, nada é salvo nem armazenado após o uso.</p>
<p>Os dados informados (como IP, usuário, senha ou URL da fonte) não ficam guardados em nossos servidores — tudo é tratado em tempo real, garantindo privacidade e segurança total.</p>
HTML
    ];

    $resourcePluralEscaped = htmlspecialchars($resource['plural'], ENT_QUOTES, 'UTF-8');
    $faqAnswers[] = [
        'question' => 'O que faz esse sistema?',
        'answer_html' => sprintf(
            '<p>É um importador profissional de listas de %1$s para o <strong>XUI.ONE</strong>. O sistema também cadastra automaticamente as categorias correspondentes aos %1$s e evita duplicações, garantindo uma importação limpa e organizada.</p>',
            $resourcePluralEscaped
        ),
    ];

    $faqAnswers[] = [
        'question' => 'O que acontece se informar dados incorretos?',
        'answer_html' => <<<HTML
<p>O sistema identifica automaticamente os erros e exibe mensagens claras, tais como:</p>
<ul>
    <li>Usuário ou senha incorretos</li>
    <li>Banco de dados inexistente</li>
    <li>Falha de conexão com o servidor</li>
    <li>URL M3U inválida ou inacessível</li>
</ul>
HTML
    ];

    $summaryHtml = '<p>Ao final do processo, o sistema apresenta um resumo detalhado com:</p><ul>';
    foreach ($summaryList as $item) {
        $summaryHtml .= '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $summaryHtml .= '</ul>';

    $faqAnswers[] = [
        'question' => 'Como sei se a lista foi importada com sucesso?',
        'answer_html' => $summaryHtml,
    ];

    $faqAnswers[] = [
        'question' => 'O sistema altera ou remove dados existentes?',
        'answer_html' => sprintf(
            '<p><strong>Não.</strong> O importador apenas <strong>insere</strong> %s. O sistema não possui permissões para alterar ou remover dados existentes, garantindo a segurança do seu banco de dados.</p>',
            htmlspecialchars($dataMutationDescription, ENT_QUOTES, 'UTF-8')
        ),
    ];

    if (!empty($config['faq_overrides']) && is_array($config['faq_overrides'])) {
        foreach ($config['faq_overrides'] as $index => $override) {
            if (isset($faqAnswers[$index]) && is_array($override)) {
                $faqAnswers[$index] = array_merge($faqAnswers[$index], array_filter($override, static fn($value) => $value !== null));
            }
        }
    }

    $heroHighlight = htmlspecialchars($resource['upper_plural'], ENT_QUOTES, 'UTF-8');
    $pageTitle = sprintf('Importador de %s', $heroHighlight);
    $heroDescriptionHtml = 'diretamente para o XUI.ONE, com categorização automática.';
    $hostValue = htmlspecialchars($host, ENT_QUOTES, 'UTF-8');
    $dbnameValue = htmlspecialchars($dbname, ENT_QUOTES, 'UTF-8');
    $usernameValue = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $passwordValue = htmlspecialchars($password, ENT_QUOTES, 'UTF-8');
    $m3uUrlValue = htmlspecialchars($m3uUrl, ENT_QUOTES, 'UTF-8');

    $actionUrlJson = json_encode($actionUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $statusUrlJson = json_encode($statusUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $stateTitlesJson = json_encode($jobStateTitles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $jobMessagesJson = json_encode($jobMessages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $jobTotalsJson = json_encode($jobTotalsLabels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    ?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #3b82f6;
            --primary-hover: #2563eb;
            --primary-light: #dbeafe;
            --secondary-color: #64748b;
            --success-color: #10b981;
            --error-color: #ef4444;
            --warning-color: #f59e0b;
            --bg-primary: #0b1120;
            --bg-secondary: #111c2e;
            --bg-tertiary: #16263f;
            --text-primary: #f8fafc;
            --text-secondary: #cbd5f5;
            --text-muted: #8da2c5;
            --border-color: rgba(59, 130, 246, 0.25);
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
            --bg: var(--bg-primary);
            --text: var(--text-primary);
            --transition: 0.2s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, var(--bg-primary) 0%, #1a202c 100%);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 1.5rem 1rem 2rem;
        }

        .nav-container {
            width: min(1280px, 94vw);
            position: relative;
            margin: 1.35rem auto 1.95rem;
            padding: 0.6rem 0.85rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.9rem;
            background: linear-gradient(150deg, rgba(8, 13, 26, 0.94), rgba(16, 28, 52, 0.78));
            border: 1px solid rgba(59, 130, 246, 0.26);
            border-radius: 1.9rem;
            box-shadow: 0 26px 56px rgba(5, 9, 22, 0.55);
            backdrop-filter: blur(16px);
            overflow: hidden;
            z-index: 1000;
        }

        .nav-container::after {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at top left, rgba(59, 130, 246, 0.2), transparent 60%),
                        radial-gradient(circle at bottom right, rgba(30, 64, 175, 0.18), transparent 55%);
            pointer-events: none;
            opacity: 0.9;
        }

        .nav-bar {
            position: relative;
            z-index: 1;
            flex: 1 1 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .nav-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text);
            letter-spacing: 0.16em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .nav-toggle {
            position: relative;
            z-index: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.35rem;
            height: 2.35rem;
            border-radius: 999px;
            border: 1px solid rgba(59, 130, 246, 0.45);
            background: rgba(59, 130, 246, 0.2);
            color: var(--text);
            cursor: pointer;
            transition: background var(--transition), color var(--transition), transform var(--transition), box-shadow var(--transition);
            box-shadow: 0 14px 28px rgba(5, 9, 22, 0.35);
        }

        .nav-toggle:hover,
        .nav-toggle:focus-visible {
            background: rgba(59, 130, 246, 0.3);
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 20px 36px rgba(5, 9, 22, 0.45);
            outline: none;
        }

        .nav-toggle .icon {
            pointer-events: none;
            font-size: 1.2rem;
        }

        .nav-toggle .icon-close {
            display: none;
        }

        .nav-toggle.open .icon-close {
            display: inline;
        }

        .nav-toggle.open .icon-hamburger {
            display: none;
        }

        .navigation {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            gap: 0.6rem;
        }

        .nav-drawer {
            position: fixed;
            inset: 0 auto 0 0;
            height: 100vh;
            width: min(280px, 82%);
            padding: 1.4rem 1.25rem;
            background: rgba(6, 12, 24, 0.96);
            border-right: 1px solid rgba(59, 130, 246, 0.24);
            box-shadow: 0 30px 60px rgba(4, 9, 22, 0.55);
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            z-index: 1001;
            overflow-y: auto;
            backdrop-filter: blur(16px);
        }

        .nav-drawer.open {
            transform: translateX(0);
        }

        .nav-link {
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.8rem 1.2rem;
            background: rgba(14, 22, 40, 0.78);
            border: 1px solid rgba(148, 163, 184, 0.24);
            border-radius: 1.3rem;
            color: rgba(226, 232, 240, 0.9);
            text-decoration: none;
            font-weight: 600;
            letter-spacing: 0.01em;
            transition: color var(--transition), border-color var(--transition), box-shadow var(--transition), transform var(--transition), background var(--transition);
            overflow: hidden;
        }

        .nav-link::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.24), rgba(99, 102, 241, 0.1));
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .nav-link > * {
            position: relative;
            z-index: 1;
        }

        .nav-link-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.4rem;
            height: 2.4rem;
            border-radius: 999px;
            background: linear-gradient(150deg, rgba(59, 130, 246, 0.28), rgba(99, 102, 241, 0.16));
            border: 1px solid rgba(59, 130, 246, 0.42);
            color: rgba(224, 231, 255, 0.95);
            box-shadow: inset 0 0 0 1px rgba(6, 12, 24, 0.85);
            transition: background var(--transition), color var(--transition), box-shadow var(--transition);
            flex-shrink: 0;
        }

        .nav-link-icon i {
            font-size: 1.05rem;
        }

        .nav-link-text {
            font-size: 0.96rem;
        }

        .nav-link:hover {
            color: var(--text);
            border-color: rgba(59, 130, 246, 0.45);
            box-shadow: 0 20px 38px rgba(5, 9, 22, 0.45);
            transform: translateY(-1px);
        }

        .nav-link:hover::before {
            opacity: 1;
        }

        .nav-link:hover .nav-link-icon {
            background: linear-gradient(150deg, rgba(59, 130, 246, 0.42), rgba(99, 102, 241, 0.28));
            color: #fff;
            box-shadow: 0 14px 28px rgba(5, 9, 22, 0.5);
        }

        .nav-link.active {
            color: #0b1120;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.95), rgba(37, 99, 235, 0.9));
            border-color: rgba(59, 130, 246, 0.7);
            box-shadow: 0 24px 40px rgba(5, 9, 22, 0.5);
            transform: translateY(-1px);
        }

        .nav-link.active::before {
            opacity: 1;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.32), rgba(37, 99, 235, 0.18));
        }

        .nav-link.active .nav-link-icon {
            background: rgba(8, 15, 32, 0.35);
            border-color: rgba(255, 255, 255, 0.45);
            color: #fff;
            box-shadow: 0 16px 32px rgba(5, 9, 22, 0.5);
        }

        .nav-overlay {
            position: fixed;
            inset: 0;
            background: rgba(4, 7, 18, 0.55);
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
            z-index: 1000;
        }

        .nav-overlay.open {
            opacity: 1;
            pointer-events: auto;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .header {
            text-align: center;
            margin-bottom: 3rem;
        }

        .header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-color), #8b5cf6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 1rem;
        }

        .header p {
            font-size: 1.125rem;
            color: var(--text-secondary);
            max-width: 600px;
            margin: 0 auto;
        }

        .hero-highlight {
            display: inline-flex;
            align-items: center;
            padding: 0.2rem 0.75rem;
            border-radius: 999px;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.24), rgba(30, 64, 175, 0.18));
            border: 1px solid rgba(59, 130, 246, 0.4);
            color: var(--primary-light);
            font-weight: 700;
            letter-spacing: 0.08em;
            box-shadow: 0 16px 32px rgba(15, 23, 42, 0.4);
        }

        .main-card {
            background: linear-gradient(145deg, rgba(17, 28, 46, 0.92), rgba(17, 36, 64, 0.92));
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            border: 1px solid rgba(148, 163, 184, 0.12);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header {
            display: flex;
            align-items: flex-start;
            gap: 1.25rem;
            padding: 2rem;
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.12), rgba(37, 99, 235, 0.05));
            border-bottom: 1px solid rgba(59, 130, 246, 0.18);
        }

        .card-header-icon {
            width: 52px;
            height: 52px;
            border-radius: 18px;
            background: rgba(59, 130, 246, 0.18);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
            font-size: 1.5rem;
            box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.35);
        }

        .card-header-text {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .card-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.35rem 0.85rem;
            border-radius: 999px;
            background: rgba(59, 130, 246, 0.12);
            color: var(--primary-light);
            border: 1px solid rgba(59, 130, 246, 0.25);
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .card-chip i {
            color: var(--primary-color);
        }

        .card-header-text h2 {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .card-header-text p {
            color: var(--text-secondary);
            font-size: 0.95rem;
            max-width: 520px;
        }

        .form-container {
            padding: 2rem;
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        .form-grid {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .form-section {
            padding: 1.75rem;
            border-radius: var(--radius-lg);
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(148, 163, 184, 0.08);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
        }

        .section-header {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }

        .section-icon {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            background: rgba(59, 130, 246, 0.16);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
            font-size: 1.25rem;
            box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.2);
        }

        .section-header h3 {
            font-size: 1.15rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .section-header p {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin-top: 0.35rem;
        }

        .section-fields {
            margin-top: 1.25rem;
            display: grid;
            gap: 1.25rem;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            font-weight: 500;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 0.55rem;
        }

        .form-group label span {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 400;
        }

        .form-group label i {
            color: var(--primary-color);
            width: 18px;
        }

        .input-wrapper {
            position: relative;
        }

        .form-group input {
            width: 100%;
            padding: 0.9rem 1.1rem;
            background: rgba(15, 23, 42, 0.85);
            border: 1px solid rgba(59, 130, 246, 0.2);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-size: 1rem;
            transition: all 0.2s ease;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.18);
        }

        .form-group input::placeholder {
            color: var(--text-muted);
        }

        .form-footer {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .form-note {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            color: var(--text-muted);
            font-size: 0.95rem;
            line-height: 1.5;
            background: rgba(15, 23, 42, 0.7);
            border-radius: var(--radius-lg);
            border: 1px solid rgba(148, 163, 184, 0.1);
            padding: 1rem 1.25rem;
        }

        .form-note i {
            color: var(--primary-color);
            font-size: 1rem;
            margin-top: 0.2rem;
        }

        .submit-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            border: none;
            padding: 1rem 2.5rem;
            border-radius: var(--radius-lg);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            align-self: flex-end;
            box-shadow: 0 20px 35px -20px rgba(59, 130, 246, 0.8);
            min-width: 220px;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 25px 45px -22px rgba(59, 130, 246, 0.9);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .response-container {
            margin-top: 2rem;
            margin-bottom: 2rem;
        }

        .response-box {
            background: var(--bg-secondary);
            border-radius: var(--radius-xl);
            padding: 0;
            box-shadow: var(--shadow-xl);
            border: 1px solid var(--border-color);
            overflow: hidden;
        }

        .response-header {
            background: linear-gradient(135deg, var(--error-color), #dc2626);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .response-header.success {
            background: linear-gradient(135deg, var(--success-color), #059669);
        }

        .response-header.warning {
            background: linear-gradient(135deg, var(--warning-color), #d97706);
        }

        .response-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: white;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0;
        }

        .response-content {
            padding: 1.5rem;
            font-family: 'Inter', sans-serif;
            font-size: 0.95rem;
            line-height: 1.6;
            color: var(--text-primary);
        }

        .response-content .message-line {
            margin-bottom: 0.75rem;
            padding: 0.5rem 0;
        }

        .response-content .message-line:last-child {
            margin-bottom: 0;
        }

        .response-content .error-detail {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: var(--radius-md);
            padding: 1rem;
            margin-top: 1rem;
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
        }

        .response-content .success-detail {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: var(--radius-md);
            padding: 1rem;
            margin-top: 1rem;
        }

        .progress-wrapper {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .progress-bar {
            width: 100%;
            height: 12px;
            background: rgba(59, 130, 246, 0.15);
            border-radius: var(--radius-md);
            overflow: hidden;
            border: 1px solid rgba(59, 130, 246, 0.35);
        }

        .progress-bar-fill {
            height: 100%;
            width: 0%;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            transition: width 0.3s ease;
        }

        .progress-text {
            font-size: 0.9rem;
            color: var(--text-secondary);
            text-align: right;
        }

        .message-lines {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            white-space: pre-line;
        }

        .hidden {
            display: none !important;
        }

        .faq-section {
            background: var(--bg-secondary);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            border: 1px solid var(--border-color);
            overflow: hidden;
        }

        .faq-header {
            background: linear-gradient(135deg, var(--secondary-color), #475569);
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border-color);
        }

        .faq-header h2 {
            font-size: 1.25rem;
            font-weight: 600;
            color: white;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .faq-content {
            padding: 1rem;
        }

        .faq-item {
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 0;
        }

        .faq-item:last-child {
            border-bottom: none;
        }

        .faq-question {
            background: transparent;
            border: none;
            width: 100%;
            text-align: left;
            padding: 1.25rem;
            font-size: 1rem;
            font-weight: 500;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            cursor: pointer;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .faq-question i {
            transition: transform 0.3s ease;
        }

        .faq-question:hover,
        .faq-question.active {
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary-color);
        }

        .faq-answer {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            padding: 0 1.25rem;
            color: var(--text-secondary);
            font-size: 0.95rem;
        }

        .faq-answer.active {
            padding-bottom: 1.25rem;
            max-height: 500px;
        }

        .faq-answer p,
        .faq-answer ul {
            margin-bottom: 1rem;
        }

        .faq-answer ul {
            padding-left: 1.5rem;
        }

        .faq-answer li {
            margin-bottom: 0.5rem;
        }

        @media (min-width: 768px) {
            .nav-container {
                width: min(1360px, 90vw);
                padding: 0.75rem 1.1rem;
                gap: 1.2rem;
            }

            .nav-bar {
                flex: 0 0 auto;
            }

            .nav-toggle {
                display: none;
            }

            .nav-overlay {
                display: none !important;
            }

            .nav-drawer {
                position: static;
                transform: none;
                height: auto;
                width: auto;
                padding: 0;
                background: transparent;
                border: none;
                box-shadow: none;
                overflow: visible;
            }

            .navigation {
                flex-direction: row;
                align-items: center;
                justify-content: flex-end;
                gap: 0.75rem;
                flex-wrap: nowrap;
            }

            .nav-link {
                padding: 0.65rem 1.3rem;
                border-radius: 999px;
                background: rgba(12, 20, 36, 0.58);
                border-color: rgba(59, 130, 246, 0.3);
                box-shadow: none;
            }

            .nav-link:hover {
                box-shadow: 0 18px 36px rgba(5, 9, 22, 0.45);
            }

            .nav-link.active {
                box-shadow: 0 22px 40px rgba(5, 9, 22, 0.5);
            }

            .nav-link-icon {
                width: 2.15rem;
                height: 2.15rem;
            }

            .form-footer {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }

            .form-note {
                max-width: 60%;
            }
        }

        /* Responsividade aprimorada */
        @media (max-width: 768px) {
            .container {
                padding: 1.5rem 1rem;
            }

            .card-header {
                padding: 1.5rem;
                flex-direction: column;
            }

            .card-header-text {
                gap: 0.5rem;
            }

            .form-container {
                padding: 1.5rem;
                gap: 1.5rem;
            }

            .form-section {
                padding: 1.25rem;
            }

            .section-fields {
                grid-template-columns: 1fr;
            }

            .form-footer {
                align-items: stretch;
            }

            .submit-btn {
                width: 100%;
            }

            .header h1 {
                font-size: 2rem;
            }

            .header p {
                font-size: 1rem;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 0.5rem;
            }

            .header {
                margin-bottom: 2rem;
            }

            .header h1 {
                font-size: 1.75rem;
            }

            .form-container {
                padding: 1rem;
            }

            .card-header {
                padding: 1.25rem;
            }

            .card-header-icon {
                width: 44px;
                height: 44px;
                font-size: 1.25rem;
            }

            .card-header-text h2,
            .faq-header h2 {
                font-size: 1.125rem;
            }

            .faq-question {
                padding: 0.875rem;
                font-size: 0.875rem;
            }

            .faq-answer {
                padding: 0 0.875rem 0.875rem;
                font-size: 0.875rem;
            }

            .form-group input {
                padding: 0.75rem;
            }

            .submit-btn {
                padding: 0.75rem 1.25rem;
                font-size: 0.9rem;
            }

            .form-note {
                padding: 0.85rem 1rem;
                font-size: 0.85rem;
            }
        }

        /* Melhorias de acessibilidade */
        .form-group input:invalid {
            border-color: var(--error-color);
        }

        .form-group input:valid {
            border-color: var(--success-color);
        }

        /* Loading state */
        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid transparent;
            border-top: 2px solid currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/navigation_menu.php'; ?>
    <div class="container">
        <header class="header">
            <h1><i class="fas fa-cloud-upload-alt"></i> Importador de <span class="hero-highlight"><?= htmlspecialchars($resource['upper_plural'], ENT_QUOTES, 'UTF-8'); ?></span></h1>
            <p><?= $heroDescriptionHtml; ?></p>
        </header>

        <main class="main-card">
            <div class="card-header">
                <div class="card-header-icon">
                    <i class="fas fa-sliders-h"></i>
                </div>
                <div class="card-header-text">
                    <h2>Prepare a importação de <?= $resourceCapitalized; ?></h2>
                    <p>Informe a fonte M3U e os dados de conexão para importar <?= $resourcePluralLower; ?> com segurança no <strong>XUI.ONE</strong>.</p>
                </div>
            </div>
            <div class="form-container">
                <form method="post" autocomplete="off" class="form-grid" id="importForm">
                    <section class="form-section">
                        <div class="section-header">
                            <span class="section-icon"><i class="fas fa-cloud-download-alt"></i></span>
                            <div>
                                <h3>Fonte da Lista M3U</h3>
                                <p>Informe a URL que contém os <?= $resourcePluralLower; ?> que deseja importar.</p>
                            </div>
                        </div>
                        <div class="section-fields">
                            <div class="form-group full-width">
                                <label for="m3u_url">
                                    <i class="fas fa-link"></i>
                                    URL da Fonte
                                </label>
                                <input type="url"
                                       id="m3u_url"
                                       name="m3u_url"
                                       required
                                       value="<?= $m3uUrlValue; ?>"
                                       placeholder="https://exemplo.com/lista.m3u">
                            </div>
                        </div>
                    </section>

                    <section class="form-section">
                        <div class="section-header">
                            <span class="section-icon"><i class="fas fa-database"></i></span>
                            <div>
                                <h3>Configuração do Banco de Dados de Destino</h3>
                                <p>Use as credenciais com permissão de inserção para conectar ao XUI.ONE.</p>
                            </div>
                        </div>
                        <div class="section-fields">
                            <input type="hidden" id="dbname" name="dbname" value="<?= $dbnameValue; ?>">
                            <div class="form-group">
                                <label for="host">
                                    <i class="fas fa-server"></i>
                                    Endereço IP do Banco de Dados
                                </label>
                                <input type="text"
                                       id="host"
                                       name="host"
                                       required
                                       value="<?= $hostValue; ?>"
                                       placeholder="Ex: 192.168.1.100">
                            </div>

                            <div class="form-group">
                                <label for="username">
                                    <i class="fas fa-user"></i>
                                    Usuário do Banco de Dados
                                </label>
                                <input type="text"
                                       id="username"
                                       name="username"
                                       required
                                       value="<?= $usernameValue; ?>"
                                       placeholder="Ex: admin">
                            </div>

                            <div class="form-group">
                                <label for="password">
                                    <i class="fas fa-lock"></i>
                                    Senha do Banco de Dados
                                </label>
                                <input type="password"
                                       id="password"
                                       name="password"
                                       required
                                       value="<?= $passwordValue; ?>"
                                       placeholder="Digite a senha">
                            </div>
                        </div>
                    </section>

                    <div class="form-footer">
                        <div class="form-note">
                            <i class="fas fa-shield-alt"></i>
                            <div>
                                <strong>Segurança garantida.</strong> Seus dados são utilizados apenas durante o processo de importação e não ficam armazenados em nossos servidores.
                            </div>
                        </div>
                        <button type="submit" class="submit-btn" id="submitBtn">
                            <i class="fas fa-upload"></i>
                            Iniciar Importação
                        </button>
                    </div>
                </form>
            </div>
        </main>

        <div class="response-container">
            <div id="responseBox" class="response-box hidden">
                <div id="responseHeader" class="response-header warning">
                    <h3>
                        <i id="responseIcon" class="fas fa-circle-info"></i>
                        <span id="responseTitle">Resultado da Importação</span>
                    </h3>
                </div>

                <div class="response-content">
                    <div id="progressWrapper" class="progress-wrapper hidden">
                        <div class="progress-bar">
                            <div id="progressBar" class="progress-bar-fill"></div>
                        </div>
                        <div id="progressText" class="progress-text">0%</div>
                    </div>
                    <div id="responseMessage" class="message-lines"></div>
                </div>
            </div>
        </div>

        <section class="faq-section">
            <div class="faq-header">
                <h2><i class="fas fa-question-circle"></i> Perguntas Frequentes</h2>
            </div>
            <div class="faq-content">
                <?php foreach ($faqAnswers as $faq): ?>
                    <div class="faq-item">
                        <button class="faq-question" type="button" onclick="toggleFaq(this)">
                            <?= htmlspecialchars($faq['question'], ENT_QUOTES, 'UTF-8'); ?>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="faq-answer">
                            <?= $faq['answer_html']; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <script src="assets/importer.js"></script>
    <script>
        const ACTION_URL = <?= $actionUrlJson ?>;
        const STATUS_URL = <?= $statusUrlJson ?>;
        const form = document.getElementById('importForm');
        const submitBtn = document.getElementById('submitBtn');
        const stateTitles = <?= $stateTitlesJson ?>;
        const messages = <?= $jobMessagesJson ?>;
<?php if ($jobCreatedTemplate !== null): ?>
        messages.jobCreated = jobId => <?= json_encode($jobCreatedTemplate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>.replace('{jobId}', jobId);
<?php endif; ?>
        const totalsLabels = <?= $jobTotalsJson ?>;

        createImportJobController({
            form,
            submitButton: submitBtn,
            elements: {
                responseBox: document.getElementById('responseBox'),
                responseHeader: document.getElementById('responseHeader'),
                responseIcon: document.getElementById('responseIcon'),
                responseTitle: document.getElementById('responseTitle'),
                responseMessage: document.getElementById('responseMessage'),
                progressWrapper: document.getElementById('progressWrapper'),
                progressBar: document.getElementById('progressBar'),
                progressText: document.getElementById('progressText'),
            },
            urls: {
                action: ACTION_URL,
                status: STATUS_URL,
            },
            stateTitles,
            messages,
            totalsLabels,
        });

        // Funcionalidade FAQ melhorada
        function toggleFaq(element) {
            const answer = element.nextElementSibling;
            const icon = element.querySelector('i:last-child');

            document.querySelectorAll('.faq-question').forEach(q => {
                if (q !== element) {
                    q.classList.remove('active');
                    q.nextElementSibling.classList.remove('active');
                    q.querySelector('i:last-child').style.transform = 'rotate(0deg)';
                }
            });

            element.classList.toggle('active');
            answer.classList.toggle('active');

            if (element.classList.contains('active')) {
                icon.style.transform = 'rotate(180deg)';
            } else {
                icon.style.transform = 'rotate(0deg)';
            }
        }

        // Funcionalidade para o destaque especial
        function toggleContent(element) {
            const content = element.nextElementSibling;
            const icon = element.querySelector('i:last-child');

            content.classList.toggle('active');

            if (content.classList.contains('active')) {
                content.style.display = 'block';
                icon.style.transform = 'rotate(180deg)';
            } else {
                content.style.display = 'none';
                icon.style.transform = 'rotate(0deg)';
            }
        }

        // Validação em tempo real
        document.querySelectorAll('input[required]').forEach(input => {
            input.addEventListener('blur', function() {
                if (this.value.trim() === '') {
                    this.style.borderColor = 'var(--error-color)';
                } else {
                    this.style.borderColor = 'var(--success-color)';
                }
            });
        });

        document.getElementById('m3u_url').addEventListener('input', function() {
            const urlPattern = /^https?:\/\/.+/;
            if (this.value && !urlPattern.test(this.value)) {
                this.style.borderColor = 'var(--error-color)';
            } else if (this.value) {
                this.style.borderColor = 'var(--success-color)';
            }
        });
    </script>
    <script>
        (function () {
            const navToggle = document.querySelector('.nav-toggle');
            const navDrawer = document.querySelector('.nav-drawer');
            const navOverlay = document.querySelector('.nav-overlay');

            if (!navToggle || !navDrawer || !navOverlay) {
                return;
            }

            const setState = (isOpen) => {
                navDrawer.classList.toggle('open', isOpen);
                navOverlay.classList.toggle('open', isOpen);
                navToggle.classList.toggle('open', isOpen);
                navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            };

            navToggle.addEventListener('click', () => {
                const isOpen = !navDrawer.classList.contains('open');
                setState(isOpen);
            });

            navOverlay.addEventListener('click', () => setState(false));

            navDrawer.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', () => setState(false));
            });
        })();
    </script>
</body>
</html>
<?php
}
