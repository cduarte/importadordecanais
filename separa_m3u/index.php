<?php
declare(strict_types=1);

$error = null;
$results = null;

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
    <title>Separador de Listas M3U</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
    <section class="card">
        <header>
            <h1>Separador de Listas M3U</h1>
            <p>Envie sua lista, acompanhe o processamento e receba as categorias organizadas em pastas de Canais, Filmes e Séries.</p>
        </header>

        <?php if ($error): ?>
            <div class="alert error">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <div class="form-grid">
                <div class="input-group">
                    <label for="m3u_url">URL da lista M3U</label>
                    <input type="url" name="m3u_url" id="m3u_url" placeholder="https://exemplo.com/lista.m3u" value="<?php echo isset($_POST['m3u_url']) ? htmlspecialchars((string)$_POST['m3u_url'], ENT_QUOTES, 'UTF-8') : ''; ?>">
                </div>

                <div class="input-group">
                    <label>Upload da lista</label>
                    <div class="dropzone">
                        <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M12 16a1 1 0 0 1-1-1V9.41l-1.3 1.3a1 1 0 1 1-1.4-1.42l3-3a1 1 0 0 1 1.4 0l3 3a1 1 0 1 1-1.4 1.42L13 9.41V15a1 1 0 0 1-1 1Z"/>
                            <path d="M6 20a4 4 0 0 1-4-4 4 4 0 0 1 3-3.86A6 6 0 0 1 11 5a6 6 0 0 1 5.61 3.8A5 5 0 0 1 22 13a5 5 0 0 1-5 5H6Zm0-2h11a3 3 0 1 0-.28-5.99 1 1 0 0 1-1-.65A4 4 0 0 0 11 7a4 4 0 0 0-3.63 2.25 1 1 0 0 1-.83.57A2 2 0 0 0 4 12a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1Zm0 0"/>
                        </svg>
                        <strong>Arraste e solte a sua lista M3U</strong>
                        <span>ou clique para selecionar um arquivo</span>
                    </div>
                    <input type="file" name="m3u_file" id="m3u_file" accept=".m3u,.txt" hidden>
                </div>
            </div>

            <div class="progress">
                <div class="progress-bar"></div>
            </div>

            <footer>
                <button type="submit">Processar lista</button>
                <p style="margin-top: 12px;">Você pode informar uma URL ou enviar um arquivo diretamente.</p>
            </footer>
        </form>

        <?php if ($results): ?>
            <section class="results">
                <h2>Arquivos gerados</h2>
                <p>Os arquivos foram separados por categoria nas pastas de Canais, Filmes e Séries.</p>
                <?php if ($results['zip']): ?>
                    <p><a href="<?php echo htmlspecialchars(publicPath($results['zip']), ENT_QUOTES, 'UTF-8'); ?>" download>Baixar todas as categorias (.zip)</a></p>
                <?php endif; ?>
                <ul>
                    <?php foreach ($results['files'] as $file): ?>
                        <li>
                            <strong><?php echo htmlspecialchars($file['folder'], ENT_QUOTES, 'UTF-8'); ?></strong> / <?php echo htmlspecialchars($file['category'], ENT_QUOTES, 'UTF-8'); ?> —
                            <a href="<?php echo htmlspecialchars(publicPath($file['path']), ENT_QUOTES, 'UTF-8'); ?>" download>Baixar M3U</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </section>
</main>
<script src="assets/app.js"></script>
</body>
</html>
