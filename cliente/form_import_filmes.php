<?php
// configs iniciais
ini_set('memory_limit', '512M');
set_time_limit(0);
ini_set('upload_max_filesize', '20M');
ini_set('post_max_size', '25M');

$envBaseUrl = getenv('IMPORTADOR_API_BASE_URL') ?: ($_ENV['IMPORTADOR_API_BASE_URL'] ?? null);

if ($envBaseUrl) {
    $apiBaseUrl = rtrim($envBaseUrl, '/');
} else {
    $host = $_SERVER['HTTP_HOST']
        ?? $_SERVER['SERVER_NAME']
        ?? 'localhost';

    $scheme = 'https';

    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        $scheme = 'https';
    } elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
        $scheme = strtolower((string) $_SERVER['REQUEST_SCHEME']) === 'https' ? 'https' : 'http';
    } elseif (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 80) {
        $scheme = 'http';
    }

    $apiBaseUrl = sprintf('%s://%s/server', $scheme, $host);
}

$actionUrl = $apiBaseUrl . '/process_filmes.php';
$statusUrl = $apiBaseUrl . '/process_filmes_status.php';

// manter valores preenchidos após submit
$host = $_POST['host'] ?? '';
$dbname = $_POST['dbname'] ?? 'xui';
$username = $_POST['username'] ?? '';
$password = $_POST['password'] ?? '';
$m3u_url = $_POST['m3u_url'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-PT">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importador M3U para XUI.ONE</title>
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
            --bg-primary: #0f172a;
            --bg-secondary: #1e293b;
            --bg-tertiary: #334155;
            --text-primary: #f8fafc;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --border-color: #475569;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
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
            padding: 2rem 1rem;
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

        .main-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            border: 1px solid var(--border-color);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            padding: 1.5rem 2rem;
            border-bottom: 1px solid var(--border-color);
        }

        .card-header h2 {
            font-size: 1.25rem;
            font-weight: 600;
            color: white;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-container {
            padding: 2rem;
        }

        .form-grid {
            display: grid;
            gap: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-group label i {
            color: var(--primary-color);
            width: 16px;
        }

        .input-wrapper {
            position: relative;
        }

        .form-group input {
            width: 100%;
            padding: 0.875rem 1rem;
            background: var(--bg-tertiary);
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            font-size: 1rem;
            transition: all 0.2s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-group input::placeholder {
            color: var(--text-muted);
        }

        .submit-btn {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: var(--radius-md);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .response-container {
            margin-top: 2rem;
            margin-bottom: 2rem; /* Adicionado para espaçamento com a seção FAQ */
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
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: between;
            gap: 1rem;
            transition: all 0.2s ease;
        }

        .faq-question:hover {
            background: var(--bg-tertiary);
        }

        .faq-question i {
            color: var(--primary-color);
            transition: transform 0.2s ease;
            margin-left: auto;
        }

        .faq-question.active i {
            transform: rotate(180deg);
        }

        .faq-answer {
            padding: 0 1.25rem 1.25rem;
            color: var(--text-secondary);
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .faq-answer.active {
            display: block;
        }

        .faq-answer pre {
            background: var(--bg-primary);
            color: var(--success-color);
            padding: 1rem;
            border-radius: var(--radius-md);
            overflow-x: auto;
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
            margin: 1rem 0;
            border: 1px solid var(--border-color);
        }

        .faq-answer code {
            background: var(--bg-tertiary);
            color: var(--primary-color);
            padding: 0.25rem 0.5rem;
            border-radius: var(--radius-sm);
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
        }

        .highlight-box {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(139, 92, 246, 0.1));
            border: 1px solid var(--primary-color);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin: 1rem 0;
        }

        .highlight-box .meg {
            background: transparent;
            border: none;
            width: 100%;
            text-align: left;
            padding: 0;
            font-size: 1rem;
            font-weight: 600;
            color: var(--primary-color);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }

        .highlight-box .meg:hover {
            color: var(--primary-hover);
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Responsividade aprimorada */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .header h1 {
                font-size: 2rem;
            }

            .header p {
                font-size: 1rem;
            }

            .form-container {
                padding: 1.5rem;
            }

            .card-header,
            .faq-header {
                padding: 1rem 1.5rem;
            }

            .faq-question {
                padding: 1rem;
                font-size: 0.9rem;
            }

            .faq-answer {
                padding: 0 1rem 1rem;
            }

            .submit-btn {
                padding: 0.875rem 1.5rem;
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

            .card-header,
            .faq-header {
                padding: 1rem;
            }

            .card-header h2,
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
    <div class="container">
        <header class="header">
            <h1><i class="fas fa-cloud-upload-alt"></i> Importador M3U</h1>
            <p>Sistema profissional para importação de <span style="background:#fff3b0;color: #000;">FILMES</span> da listas M3U diretamente para o <strong>XUI.ONE</strong>, com categorização automática.</p>
        </header>

        <main class="main-card">
            <div class="card-header">
                <h2><i class="fas fa-database"></i> Configuração do Sistema</h2>
            </div>
            <div class="form-container">
                <form method="post" autocomplete="off" class="form-grid" id="importForm">
                    <div class="form-group">
                        <label for="host">
                            <i class="fas fa-server"></i>
                            Endereço IP do Banco de Dados
                        </label>
                        <input type="text" 
                               id="host" 
                               name="host" 
                               required 
                               value="<?= htmlspecialchars($host) ?>"
                               placeholder="Ex: 192.168.1.100">
                    </div>

                    <div class="form-group hidden">
                        <label for="dbname">
                            <i class="fas fa-database"></i>
                            Nome do Banco de Dados
                        </label>
                        <input type="text" 
                               id="dbname" 
                               name="dbname" 
                               value="<?= htmlspecialchars($dbname) ?>" 
                               required
                               placeholder="Ex: xui">
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
                               value="<?= htmlspecialchars($username) ?>"
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
                               value="<?= htmlspecialchars($password) ?>"
                               placeholder="Digite a senha">
                    </div>

                    <div class="form-group">
                        <label for="m3u_url">
                            <i class="fas fa-link"></i>
                            URL da Lista M3U
                        </label>
                        <input type="url" 
                               id="m3u_url" 
                               name="m3u_url" 
                               required 
                               value="<?= htmlspecialchars($m3u_url) ?>"
                               placeholder="https://exemplo.com/lista.m3u">
                    </div>

                    <button type="submit" class="submit-btn" id="submitBtn">
                        <i class="fas fa-upload"></i>
                        Iniciar Importação
                    </button>
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
                <div class="faq-item">
                    <button class="faq-question" type="button" onclick="toggleFaq(this)">
                        Como criar um usuário no banco de dados?
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="faq-answer">
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
                    </div>
                </div>

                <div class="faq-item">
                    <button class="faq-question" type="button" onclick="toggleFaq(this)">
                        O que faz esse sistema?
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="faq-answer">
                        <p>É um importador profissional de listas de filmes para o <strong>XUI.ONE</strong>. O sistema também cadastra automaticamente as categorias correspondentes aos filmes e evita duplicações, garantindo uma importação limpa e organizada.</p>
                    </div>
                </div>

                <div class="faq-item">
                    <button class="faq-question" type="button" onclick="toggleFaq(this)">
                        O que acontece se informar dados incorretos?
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="faq-answer">
                        <p>O sistema identifica automaticamente os erros e exibe mensagens claras, tais como:</p>
                        <ul>
                            <li>Usuário ou senha incorretos</li>
                            <li>Banco de dados inexistente</li>
                            <li>Falha de conexão com o servidor</li>
                            <li>URL M3U inválida ou inacessível</li>
                        </ul>
                    </div>
                </div>

                <div class="faq-item">
                    <button class="faq-question" type="button" onclick="toggleFaq(this)">
                        Como sei se a lista foi importada com sucesso?
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="faq-answer">
                        <p>Ao final do processo, o sistema apresenta um resumo detalhado com:</p>
                        <ul>
                            <li>Número de filmes adicionados com sucesso</li>
                            <li>filmes ignorados por já existirem</li>
                            <li>Eventuais erros encontrados durante o processo</li>
                        </ul>
                    </div>
                </div>

                <div class="faq-item">
                    <button class="faq-question" type="button" onclick="toggleFaq(this)">
                        O sistema altera ou remove dados existentes?
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="faq-answer">
                        <p><strong>Não.</strong> O importador apenas <strong>insere</strong> novas categorias e filmes. O sistema não possui permissões para alterar ou remover dados existentes, garantindo a segurança do seu banco de dados.</p>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <script>
        const API_BASE_URL = <?= json_encode($apiBaseUrl, JSON_UNESCAPED_SLASHES) ?>;
        const ACTION_URL = <?= json_encode($actionUrl, JSON_UNESCAPED_SLASHES) ?>;
        const STATUS_URL = <?= json_encode($statusUrl, JSON_UNESCAPED_SLASHES) ?>;
        const POLL_INTERVAL_MS = 5000;

        const form = document.getElementById('importForm');
        const submitBtn = document.getElementById('submitBtn');
        const submitBtnOriginal = submitBtn.innerHTML;

        const responseBox = document.getElementById('responseBox');
        const responseHeader = document.getElementById('responseHeader');
        const responseIcon = document.getElementById('responseIcon');
        const responseTitle = document.getElementById('responseTitle');
        const responseMessage = document.getElementById('responseMessage');
        const progressWrapper = document.getElementById('progressWrapper');
        const progressBar = document.getElementById('progressBar');
        const progressText = document.getElementById('progressText');

        let pollingHandle = null;
        let currentJobId = null;

        const responseStates = {
            queued: { headerClass: 'warning', icon: 'fa-clock', title: 'Job na fila' },
            running: { headerClass: 'warning', icon: 'fa-spinner fa-spin', title: 'Processando filmes' },
            done: { headerClass: 'success', icon: 'fa-circle-check', title: 'Importação concluída' },
            failed: { headerClass: '', icon: 'fa-triangle-exclamation', title: 'Falha na importação' },
            error: { headerClass: '', icon: 'fa-triangle-exclamation', title: 'Erro' }
        };

        function showResponseBox() {
            responseBox.classList.remove('hidden');
        }

        function resetProgress() {
            progressWrapper.classList.add('hidden');
            progressBar.style.width = '0%';
            progressText.textContent = '0%';
        }

        function updateProgress(value) {
            if (typeof value !== 'number' || Number.isNaN(value)) {
                progressWrapper.classList.add('hidden');
                return;
            }

            const safeValue = Math.min(100, Math.max(0, Math.round(value)));
            progressWrapper.classList.remove('hidden');
            progressBar.style.width = `${safeValue}%`;
            progressText.textContent = `${safeValue}%`;
        }

        function updateMessage(message) {
            responseMessage.innerHTML = '';
            if (!message) {
                return;
            }

            message.split('\n').forEach(line => {
                const trimmed = line.trim();
                if (!trimmed) {
                    return;
                }
                const div = document.createElement('div');
                div.className = 'message-line';
                div.textContent = trimmed;
                responseMessage.appendChild(div);
            });
        }

        function setHeader(stateKey, customTitle = null) {
            const state = responseStates[stateKey] ?? responseStates.error;
            responseHeader.classList.remove('success', 'warning');
            if (state.headerClass) {
                responseHeader.classList.add(state.headerClass);
            }
            responseIcon.className = `fas ${state.icon}`;
            responseTitle.textContent = customTitle ?? state.title;
            showResponseBox();
        }

        function setLoadingState() {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<div class="loading"></div> A processar...';
        }

        function restoreButton() {
            submitBtn.disabled = false;
            submitBtn.innerHTML = submitBtnOriginal;
        }

        function handleError(message) {
            stopPolling();
            setHeader('error', 'Erro na importação');
            updateMessage(message);
            resetProgress();
            restoreButton();
            showResponseBox();
        }

        async function submitForm(event) {
            event.preventDefault();

            if (!form.reportValidity()) {
                return;
            }

            stopPolling();
            currentJobId = null;
            setHeader('queued', 'Preparando importação');
            updateMessage('Aguarde, estamos validando as credenciais...');
            resetProgress();
            showResponseBox();
            setLoadingState();

            const formData = new FormData(form);

            try {
                const response = await fetch(ACTION_URL, {
                    method: 'POST',
                    body: formData,
                });

                const data = await response.json().catch(() => null);

                if (!response.ok || !data) {
                    const errorMsg = data && data.error ? data.error : `Falha na requisição (${response.status})`;
                    handleError(errorMsg);
                    return;
                }

                if (data.error) {
                    handleError(data.error);
                    return;
                }

                if (!data.job_id) {
                    handleError('Resposta inesperada do servidor.');
                    return;
                }

                currentJobId = data.job_id;
                setHeader('queued', 'Job na fila');
                updateMessage(`Job #${currentJobId} criado com sucesso. O processamento será iniciado em breve.`);
                updateProgress(0);
                startPolling(currentJobId);
            } catch (error) {
                handleError(`Erro de rede ao contactar o servidor: ${error.message}`);
            }
        }

        async function fetchStatus(jobId) {
            try {
                const response = await fetch(`${STATUS_URL}?job_id=${encodeURIComponent(jobId)}`, {
                    cache: 'no-store',
                });

                const data = await response.json().catch(() => null);

                if (!response.ok || !data) {
                    const errorMsg = data && data.error ? data.error : `Falha ao obter status (${response.status})`;
                    handleError(errorMsg);
                    return;
                }

                if (data.error) {
                    handleError(data.error);
                    return;
                }

                renderStatus(data);
            } catch (error) {
                updateMessage(`Aviso: não foi possível atualizar o status no momento (${error.message}).`);
            }
        }

        function renderStatus(data) {
            const status = data.status;
            const message = data.message ?? '';
            const progress = typeof data.progress === 'number' ? data.progress : null;
            const totals = data.totals || {};

            updateProgress(progress);

            const totalsLines = [];
            ['added', 'skipped', 'errors'].forEach(key => {
                if (typeof totals[key] === 'number') {
                    const labels = {
                        added: 'Filmes adicionados',
                        skipped: 'Filmes ignorados',
                        errors: 'Erros',
                    };
                    totalsLines.push(`${labels[key]}: ${totals[key]}`);
                }
            });

            const combinedMessage = (() => {
                const extra = totalsLines.length ? totalsLines.join('\n') : '';
                if (message && extra) {
                    return `${message}\n${extra}`;
                }
                return message || extra;
            })();

            if (status === 'queued') {
                setHeader('queued');
                updateMessage(combinedMessage || 'Job aguardando processamento...');
                return;
            }

            if (status === 'running') {
                setHeader('running');
                updateMessage(combinedMessage || 'Processando filmes...');
                return;
            }

            if (status === 'done') {
                setHeader('done');
                updateMessage(combinedMessage || 'Importação finalizada.');
                updateProgress(100);
                stopPolling();
                restoreButton();
                return;
            }

            if (status === 'failed') {
                setHeader('failed');
                updateMessage(combinedMessage || 'Ocorreu um erro durante o processamento.');
                updateProgress(100);
                stopPolling();
                restoreButton();
                return;
            }

            setHeader('error');
            updateMessage(combinedMessage || 'Status desconhecido retornado pelo servidor.');
            stopPolling();
            restoreButton();
        }

        function startPolling(jobId) {
            stopPolling();
            showResponseBox();
            fetchStatus(jobId);
            pollingHandle = setInterval(() => fetchStatus(jobId), POLL_INTERVAL_MS);
        }

        function stopPolling() {
            if (pollingHandle) {
                clearInterval(pollingHandle);
                pollingHandle = null;
            }
        }

        form.addEventListener('submit', submitForm);

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
</body>
</html>
