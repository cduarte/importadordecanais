<?php
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$scriptDir = str_replace('\\', '/', dirname($scriptName));
if ($scriptDir === '/' || $scriptDir === '\\' || $scriptDir === '.') {
    $scriptDir = '';
}
$uploadEndpoint = $scriptDir . '/upload.php';

$buildLocalUrl = static function (string $script, array $params = []) use ($scriptName) {
    $scriptPath = $scriptName !== '' ? $scriptName : ($_SERVER['PHP_SELF'] ?? '');
    $directory = str_replace('\\\\', '/', dirname($scriptPath));

    if ($directory === '/' || $directory === '\\' || $directory === '.') {
        $directory = '';
    }

    $normalizedDirectory = trim($directory, '/');
    $basePath = '';

    if ($normalizedDirectory !== '') {
        $segments = explode('/', $normalizedDirectory);
        $basePath = '/' . $segments[0];
    }

    $path = $script === '' ? '' : '/' . ltrim($script, '/');
    $url = ($basePath === '' ? '' : $basePath) . $path;

    if (!empty($params)) {
        $queryString = http_build_query($params);
        if ($queryString !== '') {
            $url .= '?' . $queryString;
        }
    }

    return $url;
};

$currentNavKey = 'edit_m3u';
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Studio M3U - Editor de playlists IPTV</title>
    <meta name="edit-m3u-upload-endpoint" content="<?= htmlspecialchars($uploadEndpoint, ENT_QUOTES, 'UTF-8'); ?>">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/navigation_menu.php'; ?>
    <div class="app-shell">
        <section class="landing" id="landingScreen">
            <header class="landing-topbar">
                <div class="landing-brand">M3U<span>EDIT</span></div>
            </header>

            <div class="landing-hero">
                <p class="landing-subtitle">Seus canais, do seu jeito</p>
                <h1>Personalize sua playlist M3U</h1>
                <p class="landing-description">
                    Carregue, filtre, personalize e exporte suas playlists IPTV M3U/EPG — sem bagunça, só os canais que você quer!
                </p>
            </div>

            <div class="landing-selector" role="tablist" aria-label="Opções de importação">
                <button class="landing-tab active" type="button" data-landing-tab="file" aria-selected="true">POR ARQUIVO</button>
                <button class="landing-tab" type="button" data-landing-tab="url" aria-selected="false">POR URL</button>
            </div>

            <div class="landing-panels">
                <div class="landing-card" data-landing-panel="file">
                    <input type="file" id="landingFileInput" accept=".m3u,.m3u8,.txt" hidden>
                    <div class="drop-zone" id="landingDropZone" role="button" tabindex="0" aria-controls="landingFileInput">
                        <div class="drop-zone-inner">
                            <button class="primary-button" type="button" id="landingChooseButton">Escolher arquivo</button>
                            <p class="drop-hint">ou solte seu M3U aqui</p>
                        </div>
                    </div>
                    <p class="landing-footnote">Formatos suportados: .m3u, .m3u8, .txt (UTF-8)</p>
                </div>

                <form class="landing-card hidden" id="landingUrlForm" data-landing-panel="url">
                    <label for="landingUrlInput" class="landing-url-label">Cole a URL de origem</label>
                    <div class="landing-url-field">
                        <input type="url" id="landingUrlInput" name="playlist_url" placeholder="https://yourprovider.com/path-to.m3u" required>
                        <button class="primary-button" type="submit" id="landingDownloadButton">Baixar playlist</button>
                    </div>
                    <p class="landing-footnote">O sistema fará o download do arquivo, armazenará e manterá uma cópia no seu histórico.</p>
                </form>
            </div>

            <p class="landing-status" id="landingStatus" role="status" hidden></p>
        </section>

        <div class="editor-shell hidden" id="editorShell">
            <header class="topbar">
                <div class="brand">
                    <div class="brand-badge">M3U</div>
                    <div class="brand-text">
                        <strong>Playlist Studio</strong>
                        <small>Monte listas personalizadas em segundos</small>
                    </div>
                </div>
                <div class="topbar-actions">
                    <label class="upload-button" id="uploadButton">
                        <input type="file" id="fileInput" accept=".m3u,.m3u8,.txt">
                        <span id="fileLabel">Enviar arquivo M3U</span>
                    </label>
                    <button id="btnOpenPaste" class="ghost-button">Colar playlist</button>
                </div>
            </header>

            <main class="workspace">
                <section class="card how-to">
                    <div class="how-to-header">
                        <h2>Como usar</h2>
                        <p>Arraste grupos, ajuste a seleção e exporte apenas o que importa.</p>
                    </div>
                    <ol>
                        <li><strong>Envie</strong> sua playlist pelo botão acima ou cole o conteúdo M3U.</li>
                        <li><strong>Explore</strong> os grupos na coluna da esquerda e clique em <em>Adicionar</em> para movê-los para a seleção.</li>
                        <li><strong>Pré-visualize</strong> os canais do grupo ativo e ajuste informações caso precise.</li>
                        <li><strong>Exporte</strong> somente os grupos selecionados, baixando o arquivo ou copiando o texto.</li>
                    </ol>
                </section>

                <section class="board-grid">
                    <article class="card board">
                        <header class="board-header">
                            <div>
                                <h3>Grupos (<span id="groupsCount">0</span>)</h3>
                                <small>Importe uma playlist para listar todos os grupos disponíveis.</small>
                            </div>
                            <div class="board-search">
                                <input type="search" id="groupSearch" placeholder="Buscar grupos">
                            </div>
                        </header>
                        <div class="board-body" id="groupsList">
                            <p class="empty-state">Nenhum arquivo importado ainda.</p>
                        </div>
                    </article>

                    <article class="card board">
                        <header class="board-header">
                            <div>
                                <h3>Grupos selecionados (<span id="selectedCount">0</span>)</h3>
                                <small>Somente esses grupos serão exportados.</small>
                            </div>
                            <button id="btnExportSelection" class="primary-button" disabled>Exportar seleção</button>
                        </header>
                        <div class="board-body" id="selectedGroupsList">
                            <p class="empty-state">Escolha grupos à esquerda para incluí-los aqui.</p>
                        </div>
                    </article>
                </section>

                <section class="card preview-panel">
                    <header class="preview-header">
                        <div>
                            <h3>Pré-visualização</h3>
                            <small>Conteúdo M3U gerado a partir da seleção atual.</small>
                        </div>
                        <div class="preview-actions">
                            <button id="btnDownload" class="primary-button" disabled>Baixar M3U</button>
                            <button id="btnCopy" class="ghost-button" disabled>Copiar playlist</button>
                            <button id="btnClearList" class="danger-button" disabled>Limpar tudo</button>
                        </div>
                    </header>
                    <textarea id="exportPreview" rows="8" readonly placeholder="A exportação aparecerá aqui assim que você importar uma playlist."></textarea>
                </section>
            </main>

            <footer class="footer">
                <small>Ferramenta inspirada em m3uedit.com. Os dados permanecem apenas no seu navegador.</small>
            </footer>
        </div>
    </div>

    <div class="modal" id="pasteModal" hidden>
        <div class="modal-card">
            <header>
                <h2>Colar playlist M3U</h2>
                <p>Cole abaixo o conteúdo bruto da sua lista M3U.</p>
            </header>
            <textarea id="m3uText" rows="12" placeholder="#EXTM3U
#EXTINF:-1 tvg-id=&quot;...&quot; tvg-logo=&quot;...&quot; group-title=&quot;...&quot;,Nome do Canal
http://exemplo.com/stream"></textarea>
            <footer class="modal-actions">
                <button id="btnImportText" class="primary-button">Importar</button>
                <button id="btnCloseModal" class="ghost-button">Cancelar</button>
            </footer>
        </div>
    </div>

    <div class="modal" id="editGroupModal" hidden>
        <div class="modal-card modal-large">
            <header>
                <h2 id="editModalTitle">Editar grupo</h2>
                <p id="editModalSubtitle">Gerencie os canais que fazem parte desta categoria.</p>
            </header>
            <div class="dual-lists">
                <section class="dual-column">
                    <h3>Canais disponíveis <span id="editAvailableCount">0</span></h3>
                    <div class="dual-list" id="editAvailableList">
                        <p class="empty-state small">Nenhum canal disponível para este grupo.</p>
                    </div>
                </section>
                <section class="dual-column">
                    <h3>Canais selecionados <span id="editSelectedCount">0</span></h3>
                    <div class="dual-list" id="editSelectedList">
                        <p class="empty-state small">Nenhum canal selecionado para exportação.</p>
                    </div>
                </section>
            </div>
            <footer class="modal-actions">
                <button id="btnCloseEdit" class="primary-button">Concluir edição</button>
            </footer>
        </div>
    </div>

    <div class="upload-progress" id="uploadProgress" hidden role="status" aria-live="polite">
        <div class="upload-progress-bar">
            <div class="upload-progress-fill" id="uploadProgressFill"></div>
        </div>
        <span class="upload-progress-label" id="uploadProgressLabel">Enviando playlist...</span>
    </div>

    <script src="app.js"></script>
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
