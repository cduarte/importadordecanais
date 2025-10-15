<?php
declare(strict_types=1);

$error = null;
$results = null;
$activeMode = 'file';

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

$currentNavKey = 'dividir_m3u';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedUrl = trim((string)($_POST['m3u_url'] ?? ''));
    if ($postedUrl !== '') {
        $activeMode = 'url';
    } elseif (!empty($_FILES['m3u_file']['name'] ?? '')) {
        $activeMode = 'file';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $content = extractM3uContents();
        if (!$content) {
            throw new RuntimeException('Envie um arquivo M3U válido ou informe uma URL para download.');
        }

        $entries = parseM3u($content);
        if (empty($entries)) {
            throw new RuntimeException('Nenhum canal foi identificado no arquivo enviado.');
        }

        $results = persistCategories($entries);
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

function extractM3uContents(): string
{
    if (!empty($_FILES['m3u_file']['tmp_name'])) {
        if (!is_uploaded_file($_FILES['m3u_file']['tmp_name'])) {
            throw new RuntimeException('Falha ao receber o arquivo enviado.');
        }

        $contents = file_get_contents($_FILES['m3u_file']['tmp_name']);
        if ($contents === false) {
            throw new RuntimeException('Não foi possível ler o arquivo enviado.');
        }

        return $contents;
    }

    $url = trim((string)($_POST['m3u_url'] ?? ''));
    if ($url === '') {
        return '';
    }

    logSubmittedUrl($url);

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Informe uma URL válida.');
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 15,
            'user_agent' => 'SeparadorM3U/1.0'
        ]
    ]);

    $contents = @file_get_contents($url, false, $context);
    if ($contents === false) {
        throw new RuntimeException('Não foi possível baixar o arquivo informado.');
    }

    return $contents;
}

function logSubmittedUrl(string $url): void
{
    if ($url === '') {
        return;
    }

    $logDirectory = __DIR__ . '/storage/logs';
    if (!is_dir($logDirectory) && !mkdir($logDirectory, 0775, true) && !is_dir($logDirectory)) {
        return;
    }

    $logEntry = sprintf('[%s] %s%s', date('Y-m-d H:i:s'), $url, PHP_EOL);
    $logFile = $logDirectory . '/m3u_urls.log';
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}

function parseM3u(string $contents): array
{
    $lines = preg_split('/\r\n|\n|\r/', trim($contents));
    $entries = [];
    $currentInfo = null;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        if (stripos($line, '#EXTINF') === 0) {
            $currentInfo = $line;
            continue;
        }

        if ($currentInfo === null) {
            continue;
        }

        $entries[] = [
            'info' => $currentInfo,
            'url' => $line,
            'category' => extractCategory($currentInfo)
        ];
        $currentInfo = null;
    }

    return $entries;
}

function extractCategory(string $info): string
{
    if (preg_match('/group-title="([^"]+)"/i', $info, $matches)) {
        return trim($matches[1]) ?: 'Sem categoria';
    }

    return 'Sem categoria';
}

function persistCategories(array $entries): array
{
    $grouped = [];
    foreach ($entries as $entry) {
        $category = $entry['category'];
        $grouped[$category][] = $entry;
    }

    $baseName = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $basePath = __DIR__ . '/storage/' . $baseName;

    if (!is_dir($basePath) && !mkdir($basePath, 0775, true) && !is_dir($basePath)) {
        throw new RuntimeException('Não foi possível preparar a pasta para salvar os arquivos.');
    }

    $written = [];

    foreach ($grouped as $category => $items) {
        $folder = normalizeTopLevel($category);
        $targetDir = $basePath . '/' . $folder;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException(sprintf('Não foi possível criar a pasta %s.', $folder));
        }

        $filename = slugify($category) ?: 'sem-categoria';
        $filePath = $targetDir . '/' . $filename . '.m3u';
        $content = buildM3u($items);

        if (file_put_contents($filePath, $content) === false) {
            throw new RuntimeException(sprintf('Falha ao criar o arquivo da categoria %s.', $category));
        }

        $written[] = [
            'category' => $category,
            'folder' => $folder,
            'path' => $filePath,
        ];
    }

    $zipFile = $basePath . '/listas-separadas.zip';
    if (class_exists(ZipArchive::class)) {
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    continue;
                }
                $localName = substr($file->getPathname(), strlen($basePath) + 1);
                $zip->addFile($file->getPathname(), $localName);
            }
            $zip->close();
        }
    }

    return [
        'baseName' => $baseName,
        'files' => $written,
        'zip' => file_exists($zipFile) ? $zipFile : null,
    ];
}

function normalizeTopLevel(string $category): string
{
    $normalized = toLower($category);

    if (str_contains($normalized, 'filme') || str_contains($normalized, 'movie') || str_contains($normalized, 'cinema')) {
        return 'Filmes';
    }

    if (str_contains($normalized, 'serie') || str_contains($normalized, 'series') || str_contains($normalized, 'show')) {
        return 'Series';
    }

    return 'Canais';
}

function toLower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function slugify(string $text): string
{
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = trim($text, '-');
    $text = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) : $text;
    $text = preg_replace('~[^-\w]+~', '', $text);
    return strtolower($text);
}

function buildM3u(array $items): string
{
    $lines = ['#EXTM3U'];
    foreach ($items as $item) {
        $lines[] = $item['info'];
        $lines[] = $item['url'];
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

function publicPath(string $absolutePath): string
{
    return 'storage/' . ltrim(str_replace(__DIR__ . '/storage/', '', $absolutePath), '/');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dividir Playlist</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/navigation_menu.php'; ?>
<div class="app-shell">
    <section class="split-landing">
        <div class="split-grid">
            <div class="split-intro">
                <p class="split-subtitle">Organize suas listas</p>
                <h1>Divida sua playlist M3U por categorias</h1>
                <p class="split-description">Transforme uma playlist única em coleções separadas de canais, filmes e séries em segundos.</p>
                <ul class="split-highlights">
                    <li>
                        <span class="split-highlight-icon"><i class="fa-solid fa-bolt" aria-hidden="true"></i></span>
                        Processamento automático com identificação das categorias existentes.
                    </li>
                    <li>
                        <span class="split-highlight-icon"><i class="fa-solid fa-folder-tree" aria-hidden="true"></i></span>
                        Cria a estrutura de pastas pronta para importar no seu painel IPTV.
                    </li>
                    <li>
                        <span class="split-highlight-icon"><i class="fa-solid fa-cloud-arrow-down" aria-hidden="true"></i></span>
                        Baixe todas as categorias em ZIP ou escolha apenas as que precisar.
                    </li>
                </ul>
                <?php if ($results): ?>
                    <div class="split-success">
                        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                        <div>
                            <strong>Listas geradas com sucesso!</strong>
                            <p>Faça o download dos arquivos ao lado e gere novas separações quando quiser.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="split-footnote">Suporta arquivos .m3u, .m3u8 e .txt com codificação UTF-8.</p>
                <?php endif; ?>
            </div>
            <article class="split-card<?php echo $results ? ' split-card--results' : ''; ?>">
                <?php if ($error): ?>
                    <div class="alert error">
                        <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                <?php endif; ?>

                <?php if (!$results): ?>
                    <h2 class="split-card__title">Comece enviando sua playlist</h2>
                    <p class="split-card__subtitle">Escolha enviar um arquivo M3U ou informe uma URL para que façamos o download automaticamente.</p>

                    <form method="post" enctype="multipart/form-data" data-default-mode="<?php echo htmlspecialchars($activeMode, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="mode-switch" role="tablist" aria-label="Selecione a forma de envio">
                            <button type="button" class="mode-button<?php echo $activeMode === 'file' ? ' active' : ''; ?>" data-mode="file">Por arquivo</button>
                            <button type="button" class="mode-button<?php echo $activeMode === 'url' ? ' active' : ''; ?>" data-mode="url">Por URL</button>
                        </div>

                        <div class="mode-panels">
                            <div class="mode-pane<?php echo $activeMode === 'file' ? ' active' : ''; ?>" data-mode="file">
                                <div class="dropzone" data-role="file" role="button" tabindex="0" aria-label="Enviar arquivo M3U">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M12 16a1 1 0 0 1-1-1V9.41l-1.3 1.3a1 1 0 1 1-1.4-1.42l3-3a1 1 0 0 1 1.4 0l3 3a1 1 0 1 1-1.4 1.42L13 9.41V15a1 1 0 0 1-1 1Z"/>
                                        <path d="M6 20a4 4 0 0 1-4-4 4 4 0 0 1 3-3.86A6 6 0 0 1 11 5a6 6 0 0 1 5.61 3.8A5 5 0 0 1 22 13a5 5 0 0 1-5 5H6Zm0-2h11a3 3 0 1 0-.28-5.99 1 1 0 0 1-1-.65A4 4 0 0 0 11 7a4 4 0 0 0-3.63 2.25 1 1 0 0 1-.83.57A2 2 0 0 0 4 12a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1Zm0 0"/>
                                    </svg>
                                    <strong>Arraste e solte aqui seu arquivo M3U</strong>
                                    <span>ou clique para selecionar um arquivo do seu computador</span>
                                </div>
                                <input type="file" name="m3u_file" id="m3u_file" accept=".m3u,.m3u8,.txt" hidden>
                            </div>

                            <div class="mode-pane<?php echo $activeMode === 'url' ? ' active' : ''; ?>" data-mode="url">
                                <div class="url-panel">
                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                        <path d="M8.59 13.41a1 1 0 0 1 0-1.41L12.59 8a1 1 0 0 1 1.41 1.41L10 13.41a1 1 0 0 1-1.41 0Z"/>
                                        <path d="M7.05 7.05a7 7 0 0 1 9.9 0l.7.7a7 7 0 0 1 0 9.9l-1.59 1.59a7 7 0 0 1-9.9 0l-.7-.7a7 7 0 0 1 0-9.9l1.59-1.59a1 1 0 1 1 1.41 1.41L7.41 8.46a5 5 0 0 0 0 7.08l.7.7a5 5 0 0 0 7.08 0l1.59-1.59a5 5 0 0 0 0-7.08l-.7-.7a5 5 0 0 0-7.08 0L7.05 7.05Z"/>
                                    </svg>
                                    <div class="url-fields">
                                        <strong>Informe a URL da sua playlist M3U</strong>
                                        <input type="url" name="m3u_url" id="m3u_url" placeholder="https://url.com/lista.m3u" value="<?php echo isset($_POST['m3u_url']) ? htmlspecialchars((string)$_POST['m3u_url'], ENT_QUOTES, 'UTF-8') : ''; ?>" aria-label="URL da lista M3U"<?php echo $activeMode === 'url' ? ' required' : ''; ?>>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="progress" role="status" aria-live="polite">
                            <div class="progress-bar"></div>
                        </div>

                        <button type="submit">Processar lista</button>
                    </form>
                <?php else: ?>
                    <h2 class="split-card__title">Arquivos gerados</h2>
                    <div class="results">
                        <?php if ($results['zip']): ?>
                            <a class="results-all-link" href="<?php echo htmlspecialchars(publicPath($results['zip']), ENT_QUOTES, 'UTF-8'); ?>" download>
                                <span class="results-all-link__icon" aria-hidden="true"><i class="fa-solid fa-download"></i></span>
                                BAIXAR TODAS AS CATEGORIAS (.ZIP)
                            </a>
                        <?php endif; ?>
                        <p>Ou baixe apenas as categorias desejadas:</p>
                        <ul class="results-list">
                            <?php foreach ($results['files'] as $file): ?>
                                <li>
                                    <strong><?php echo htmlspecialchars($file['folder'], ENT_QUOTES, 'UTF-8'); ?></strong> / <?php echo htmlspecialchars($file['category'], ENT_QUOTES, 'UTF-8'); ?> —
                                    <a href="<?php echo htmlspecialchars(publicPath($file['path']), ENT_QUOTES, 'UTF-8'); ?>" download>Baixar M3U</a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="results-actions">
                        <a class="ghost-button" href="<?php echo htmlspecialchars($buildLocalUrl('index.php'), ENT_QUOTES, 'UTF-8'); ?>">Processar nova playlist</a>
                    </div>
                <?php endif; ?>
            </article>
        </div>
    </section>
</div>
<script src="assets/app.js"></script>
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
