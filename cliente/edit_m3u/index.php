<?php
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Studio M3U - Editor de playlists IPTV</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="app-shell">
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
                    <h2>How To Use</h2>
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

            <section class="card channels-panel">
                <header class="channels-header">
                    <div>
                        <h3 id="channelsTitle">Canais</h3>
                        <small id="channelsSubtitle">Selecione um grupo para visualizar os canais disponíveis.</small>
                    </div>
                    <div class="channels-actions">
                        <button id="btnDownload" class="primary-button" disabled>Baixar M3U</button>
                        <button id="btnCopy" class="ghost-button" disabled>Copiar playlist</button>
                    </div>
                </header>
                <div class="channels-table" id="channelsTable">
                    <p class="empty-state">Importe um arquivo para começar.</p>
                </div>
            </section>

            <section class="card preview-panel">
                <header class="preview-header">
                    <div>
                        <h3>Pré-visualização</h3>
                        <small>Conteúdo M3U gerado a partir da seleção atual.</small>
                    </div>
                    <button id="btnClearList" class="danger-button" disabled>Limpar tudo</button>
                </header>
                <textarea id="exportPreview" rows="8" readonly placeholder="A exportação aparecerá aqui assim que você importar uma playlist."></textarea>
            </section>
        </main>

        <footer class="footer">
            <small>Ferramenta inspirada em m3uedit.com. Os dados permanecem apenas no seu navegador.</small>
        </footer>
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

    <script src="app.js"></script>
</body>
</html>
