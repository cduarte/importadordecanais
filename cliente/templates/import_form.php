<?php
/** @var callable(string, array=): string $buildLocalUrl */
/** @var string $currentNavKey */
/** @var string $actionUrl */
/** @var string $statusUrl */
/** @var string $host */
/** @var string $dbname */
/** @var string $username */
/** @var string $password */
/** @var string $m3u_url */
/** @var string $typeHighlight */
/** @var string $faqFirstAnswer */
/** @var string[] $summaryItems */
/** @var string $finalFaqAnswer */
/** @var array<string, string> $controllerStateTitles */
/** @var array<string, string> $controllerMessages */
/** @var array<string, string> $controllerTotalsLabels */
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

        .nav-container {
            position: relative;
            margin-bottom: 2rem;
        }

        .nav-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 0.75rem 1rem;
            box-shadow: var(--shadow-md);
        }

        .nav-title {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .nav-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            border: none;
            background: transparent;
            color: var(--text-primary);
            cursor: pointer;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .nav-toggle:hover,
        .nav-toggle:focus-visible {
            background: rgba(59, 130, 246, 0.15);
            color: var(--primary-color);
            outline: none;
        }

        .nav-toggle .icon {
            pointer-events: none;
            font-size: 1.25rem;
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
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .nav-drawer {
            position: fixed;
            inset: 0 auto 0 0;
            height: 100vh;
            width: min(280px, 80%);
            padding: 1.5rem 1.25rem;
            background: var(--bg-secondary);
            border-right: 1px solid var(--border-color);
            box-shadow: var(--shadow-xl);
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            z-index: 1001;
            overflow-y: auto;
        }

        .nav-drawer.open {
            transform: translateX(0);
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 1.25rem;
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
            width: 100%;
        }

        .nav-link i {
            font-size: 1rem;
        }

        .nav-link:hover {
            color: var(--primary-hover);
            background: var(--primary-light);
            border-color: var(--primary-hover);
        }

        .nav-link.active {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-hover));
            color: #fff;
            border-color: transparent;
            box-shadow: var(--shadow-lg);
        }

        .nav-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
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
        }

        .response-header h3 i {
            font-size: 1.25rem;
        }

        .response-content {
            padding: 1.5rem;
            color: var(--text-secondary);
        }

        .progress-container {
            margin-top: 1rem;
            background: var(--bg-tertiary);
            border-radius: var(--radius-md);
            border: 1px solid rgba(148, 163, 184, 0.2);
            overflow: hidden;
        }

        .progress-bar {
            height: 6px;
            background: linear-gradient(90deg, var(--primary-color), #8b5cf6);
            width: 0;
            transition: width 0.3s ease;
        }

        .progress-text {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-top: 0.75rem;
        }

        .faq-section {
            background: var(--bg-secondary);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-xl);
            border: 1px solid var(--border-color);
            padding: 2rem;
        }

        .faq-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .faq-header h2 {
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }

        .faq-header p {
            color: var(--text-secondary);
            max-width: 600px;
            margin: 0 auto;
        }

        .faq-grid {
            display: grid;
            gap: 1.5rem;
        }

        .faq-item {
            background: var(--bg-tertiary);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
            overflow: hidden;
            transition: transform 0.2s ease;
        }

        .faq-item:hover {
            transform: translateY(-2px);
        }

        .faq-question {
            width: 100%;
            text-align: left;
            background: transparent;
            border: none;
            color: var(--text-primary);
            font-size: 1rem;
            font-weight: 500;
            padding: 1.25rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            transition: background 0.2s ease;
        }

        .faq-question:hover,
        .faq-question.active {
            background: rgba(59, 130, 246, 0.15);
        }

        .faq-question i {
            transition: transform 0.3s ease;
        }

        .faq-answer {
            padding: 0 1.5rem 1.5rem;
            color: var(--text-secondary);
            display: none;
        }

        .faq-answer.active {
            display: block;
        }

        .faq-answer ul {
            margin-top: 0.75rem;
            padding-left: 1.25rem;
        }

        .highlight {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(139, 92, 246, 0.15));
            border: 1px solid rgba(59, 130, 246, 0.25);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            display: flex;
            gap: 1rem;
            align-items: flex-start;
        }

        .highlight-icon {
            font-size: 1.75rem;
            color: var(--primary-color);
            background: rgba(59, 130, 246, 0.2);
            padding: 1rem;
            border-radius: 50%;
        }

        .highlight-content h3 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }

        .highlight-features {
            display: grid;
            gap: 0.75rem;
        }

        .highlight-feature {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }

        .highlight-feature i {
            color: var(--success-color);
            margin-top: 0.2rem;
        }

        .animated-card {
            animation: fadeInUp 0.6s ease both;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (min-width: 768px) {
            .nav-bar,
            .nav-overlay {
                display: none;
            }

            .navigation {
                flex-direction: row;
                justify-content: center;
                gap: 1rem;
                flex-wrap: wrap;
            }

            .nav-drawer {
                position: static;
                transform: none;
                height: auto;
                width: auto;
                padding: 0.75rem;
                border-radius: var(--radius-lg);
                border: 1px solid var(--border-color);
                background: var(--bg-secondary);
                box-shadow: var(--shadow-md);
            }

            .nav-link {
                width: auto;
                padding: 0.5rem 1.25rem;
            }

            .nav-toggle {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 1.5rem 1rem;
            }

            .form-container {
                padding: 1.5rem;
            }

            .faq-section {
                padding: 1.5rem;
            }

            .highlight {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 0.5rem;
            }

            .header h1 {
                font-size: 2rem;
            }

            .header p {
                font-size: 1rem;
            }

            .card-header h2 {
                font-size: 1.125rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include __DIR__ . '/../includes/navigation_menu.php'; ?>

        <header class="header">
            <h1><i class="fas fa-cloud-upload-alt"></i> Importador M3U</h1>
            <p>Sistema profissional para importação de Fonte de <span style="background:#fff3b0;color: #000;"><?= htmlspecialchars($typeHighlight, ENT_QUOTES, 'UTF-8'); ?></span> diretamente para o <strong>XUI.ONE</strong>, com categorização automática.</p>
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
                               value="<?= htmlspecialchars($host, ENT_QUOTES, 'UTF-8'); ?>"
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
                               value="<?= htmlspecialchars($dbname, ENT_QUOTES, 'UTF-8'); ?>"
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
                               value="<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8'); ?>"
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
                               value="<?= htmlspecialchars($password, ENT_QUOTES, 'UTF-8'); ?>"
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
                               value="<?= htmlspecialchars($m3u_url, ENT_QUOTES, 'UTF-8'); ?>"
                               placeholder="https://exemplo.com/lista.m3u">
                    </div>

                    <button class="submit-btn" type="submit" id="submitBtn">
                        <i class="fas fa-cloud-upload-alt"></i>
                        Iniciar Importação
                    </button>
                </form>

                <div class="response-container">
                    <div class="response-box" id="responseBox" style="display: none;">
                        <div class="response-header" id="responseHeader">
                            <h3>
                                <i class="fas fa-info-circle" id="responseIcon"></i>
                                <span id="responseTitle">Status do Processo</span>
                            </h3>
                        </div>
                        <div class="response-content">
                            <p id="responseMessage">Acompanhe o progresso do processo de importação.</p>
                            <div class="progress-container" id="progressWrapper" style="display: none;">
                                <div class="progress-bar" id="progressBar"></div>
                            </div>
                            <p class="progress-text" id="progressText">Aguardando início...</p>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <section class="faq-section animated-card">
            <div class="faq-header">
                <h2><i class="fas fa-question-circle"></i> Perguntas Frequentes</h2>
                <p>Tire suas dúvidas sobre o funcionamento do importador antes de iniciar o processo.</p>
            </div>

            <div class="faq-grid">
                <div class="faq-item">
                    <button class="faq-question" type="button" onclick="toggleFaq(this)">
                        O que faz esse sistema?
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="faq-answer">
                        <p><?= $faqFirstAnswer; ?></p>
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
                            <?php foreach ($summaryItems as $item): ?>
                                <li><?= htmlspecialchars($item, ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

                <div class="faq-item">
                    <button class="faq-question" type="button" onclick="toggleFaq(this)">
                        O sistema altera ou remove dados existentes?
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="faq-answer">
                        <p><?= $finalFaqAnswer; ?></p>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <script src="assets/importer.js"></script>
    <script>
        const ACTION_URL = <?= json_encode($actionUrl, JSON_UNESCAPED_SLASHES); ?>;
        const STATUS_URL = <?= json_encode($statusUrl, JSON_UNESCAPED_SLASHES); ?>;
        const form = document.getElementById('importForm');
        const submitBtn = document.getElementById('submitBtn');

        const stateTitles = <?= json_encode($controllerStateTitles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const messages = <?= json_encode($controllerMessages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const totalsLabels = <?= json_encode($controllerTotalsLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

        if (typeof messages === 'object' && messages !== null && typeof messages.jobCreated === 'string') {
            const template = messages.jobCreated;
            messages.jobCreated = jobId => template.replace('%d', jobId);
        }

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
