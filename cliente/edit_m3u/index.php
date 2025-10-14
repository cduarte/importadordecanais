<?php
// Página principal do editor de listas M3U
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Editor de Listas M3U</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header class="app-header">
        <h1>Editor de Listas M3U</h1>
        <p>Importe, visualize, edite e exporte listas de IPTV em formato M3U diretamente no navegador.</p>
    </header>

    <main class="app-main">
        <section class="panel">
            <h2>Importação</h2>
            <div class="import-actions">
                <label class="file-input">
                    <span>Escolher arquivo M3U</span>
                    <input type="file" id="fileInput" accept=".m3u,.m3u8,.txt">
                </label>
                <button id="btnImportFile" class="primary">Importar arquivo</button>
            </div>
            <div class="text-import">
                <label for="m3uText">Ou cole o conteúdo da sua lista M3U:</label>
                <textarea id="m3uText" rows="6" placeholder="#EXTM3U\n#EXTINF:-1 tvg-id=&quot;...&quot; tvg-logo=&quot;...&quot; group-title=&quot;...&quot;,Nome do Canal\nhttp://..."></textarea>
                <div class="text-import-actions">
                    <button id="btnImportText" class="primary">Importar do texto</button>
                    <button id="btnClearList" class="danger" title="Remove todos os canais carregados">Limpar lista</button>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header">
                <h2>Lista de Canais</h2>
                <div class="panel-tools">
                    <input type="search" id="searchInput" placeholder="Filtrar por nome, grupo ou URL">
                    <button id="btnAddChannel">Adicionar canal</button>
                </div>
            </div>
            <div class="table-wrapper">
                <table id="channelsTable">
                    <thead>
                        <tr>
                            <th>Logo</th>
                            <th>Nome</th>
                            <th>Grupo</th>
                            <th>URL</th>
                            <th>ID</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="empty-row">
                            <td colspan="6">Nenhum canal importado ainda.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <h2>Exportação</h2>
            <p>Baixe sua lista editada ou copie o conteúdo em formato M3U.</p>
            <div class="export-actions">
                <button id="btnDownload" class="primary">Baixar M3U</button>
                <button id="btnCopy" class="secondary">Copiar para área de transferência</button>
            </div>
            <textarea id="exportPreview" rows="8" readonly placeholder="A lista exportada aparecerá aqui..."></textarea>
        </section>
    </main>

    <footer class="app-footer">
        <small>Ferramenta inspirada no m3uedit.com. Todos os dados são processados localmente no navegador.</small>
    </footer>

    <script src="app.js"></script>
</body>
</html>
