(() => {
    const UNGROUPED_LABEL = 'Sem categoria';
    const DEFAULT_PAGE_SIZE = 10;
    const PAGINATION_KEYS = ['groups', 'selected', 'editAvailable', 'editSelected'];

    const state = {
        channels: [],
        selectedGroups: new Set(),
        search: '',
        activeGroup: null,
        fileName: null,
        groupExclusions: new Map(),
        pagination: {
            groups: 1,
            selected: 1,
            editAvailable: 1,
            editSelected: 1
        }
    };

    const landingScreen = document.getElementById('landingScreen');
    const editorShell = document.getElementById('editorShell');
    const landingTabs = Array.from(document.querySelectorAll('[data-landing-tab]'));
    const landingPanels = Array.from(document.querySelectorAll('[data-landing-panel]'));
    const landingFileInput = document.getElementById('landingFileInput');
    const landingDropZone = document.getElementById('landingDropZone');
    const landingChooseButton = document.getElementById('landingChooseButton');
    const landingStatus = document.getElementById('landingStatus');
    const landingUrlForm = document.getElementById('landingUrlForm');
    const landingUrlInput = document.getElementById('landingUrlInput');
    const landingDownloadButton = document.getElementById('landingDownloadButton');

    const fileInput = document.getElementById('fileInput');
    const fileLabel = document.getElementById('fileLabel');
    const pasteModal = document.getElementById('pasteModal');
    const btnOpenPaste = document.getElementById('btnOpenPaste');
    const btnCloseModal = document.getElementById('btnCloseModal');
    const btnImportText = document.getElementById('btnImportText');
    const m3uText = document.getElementById('m3uText');
    const groupSearch = document.getElementById('groupSearch');
    const groupsList = document.getElementById('groupsList');
    const selectedGroupsList = document.getElementById('selectedGroupsList');
    const boardGrid = document.getElementById('boardGrid');
    const groupsPagination = document.getElementById('groupsPagination');
    const selectedPagination = document.getElementById('selectedPagination');
    const btnDownload = document.getElementById('btnDownload');
    const btnExportSelection = document.getElementById('btnExportSelection');
    const exportPreview = document.getElementById('exportPreview');
    const groupsCountLabel = document.getElementById('groupsCount');
    const selectedCountLabel = document.getElementById('selectedCount');
    const editPanel = document.getElementById('editPanel');
    const editModalTitle = document.getElementById('editPanelTitle');
    const editModalSubtitle = document.getElementById('editPanelSubtitle');
    const editAvailableList = document.getElementById('editAvailableList');
    const editSelectedList = document.getElementById('editSelectedList');
    const editAvailablePagination = document.getElementById('editAvailablePagination');
    const editSelectedPagination = document.getElementById('editSelectedPagination');
    const editAvailableCount = document.getElementById('editAvailableCount');
    const editSelectedCount = document.getElementById('editSelectedCount');
    const btnCloseEdit = document.getElementById('btnCloseEdit');
    const uploadProgress = document.getElementById('uploadProgress');
    const uploadProgressFill = document.getElementById('uploadProgressFill');
    const uploadProgressLabel = document.getElementById('uploadProgressLabel');

    const knownAttributes = ['tvg-id', 'tvg-name', 'tvg-logo', 'group-title'];
    const uploadEndpointMeta = document.querySelector('meta[name="edit-m3u-upload-endpoint"]');
    const uploadEndpoint = uploadEndpointMeta?.content?.trim() || 'proces_edit_m3u.php';

    let editingGroup = null;
    let landingBusy = false;

    function setLandingStatus(message, type = 'info') {
        if (!landingStatus) {
            return;
        }
        const text = message?.trim() ?? '';
        landingStatus.textContent = text;
        landingStatus.dataset.status = type;
        landingStatus.hidden = text === '';
    }

    function clearLandingStatus() {
        setLandingStatus('', 'info');
    }

    function setLandingBusy(isBusy) {
        landingBusy = isBusy;
        if (landingScreen) {
            landingScreen.classList.toggle('is-loading', isBusy);
        }

        const toggleDisabled = (element) => {
            if (element) {
                element.disabled = isBusy;
            }
        };

        toggleDisabled(landingDownloadButton);
        toggleDisabled(landingChooseButton);
        toggleDisabled(landingUrlInput);
        toggleDisabled(landingFileInput);

        landingTabs.forEach((tab) => {
            tab.disabled = isBusy;
        });
    }

    function showUploadProgress(message = 'Enviando playlist...') {
        if (!uploadProgress) {
            return;
        }

        uploadProgress.hidden = false;
        uploadProgress.classList.remove('is-indeterminate');

        if (uploadProgressFill) {
            uploadProgressFill.style.width = '0%';
            uploadProgressFill.style.transform = 'translateX(0)';
        }

        if (uploadProgressLabel && typeof message === 'string') {
            uploadProgressLabel.textContent = message;
        }
    }

    function updateUploadProgress({ loaded = 0, total = null, message = null, isComplete = false } = {}) {
        if (!uploadProgress) {
            return;
        }

        if (typeof message === 'string' && uploadProgressLabel) {
            uploadProgressLabel.textContent = message;
        }

        if (typeof total === 'number' && total > 0) {
            const percent = isComplete ? 100 : Math.min(100, Math.round((loaded / total) * 100));
            uploadProgress.classList.remove('is-indeterminate');
            if (uploadProgressFill) {
                uploadProgressFill.style.width = `${percent}%`;
                uploadProgressFill.style.transform = 'translateX(0)';
            }
        } else {
            uploadProgress.classList.add('is-indeterminate');
        }
    }

    function hideUploadProgress() {
        if (!uploadProgress) {
            return;
        }

        uploadProgress.hidden = true;
        uploadProgress.classList.remove('is-indeterminate');

        if (uploadProgressFill) {
            uploadProgressFill.style.width = '0%';
            uploadProgressFill.style.transform = 'translateX(0)';
        }
    }

    function switchLandingTab(targetKey) {
        if (!targetKey) {
            return;
        }

        landingTabs.forEach((tab) => {
            const isActive = tab.dataset.landingTab === targetKey;
            tab.classList.toggle('active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        landingPanels.forEach((panel) => {
            const isActive = panel.dataset.landingPanel === targetKey;
            panel.classList.toggle('hidden', !isActive);
            panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
        });
    }

    function enterEditor() {
        if (landingScreen) {
            landingScreen.classList.add('hidden');
        }
        if (editorShell) {
            editorShell.classList.remove('hidden');
        }
        clearLandingStatus();
    }

    async function uploadPlaylistPayload({ file, url, onProgress } = {}) {
        const formData = new FormData();
        if (file) {
            formData.append('playlist', file);
        }
        if (url) {
            formData.append('playlist_url', url);
        }

        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            const initialTotal = typeof file?.size === 'number' ? file.size : null;

            const emitProgress = (details) => {
                if (typeof onProgress === 'function') {
                    onProgress(details);
                }
            };

            xhr.open('POST', uploadEndpoint, true);
            xhr.responseType = 'json';
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            emitProgress({
                loaded: 0,
                total: initialTotal,
                lengthComputable: typeof initialTotal === 'number',
            });

            if (xhr.upload && typeof onProgress === 'function') {
                xhr.upload.addEventListener('progress', (event) => {
                    emitProgress({
                        loaded: event.loaded,
                        total: event.lengthComputable ? event.total : initialTotal,
                        lengthComputable: event.lengthComputable,
                    });
                });
            }

            xhr.onload = () => {
                const status = xhr.status;
                let payload = xhr.response;

                if (!payload || typeof payload !== 'object') {
                    const raw = typeof xhr.responseText === 'string' ? xhr.responseText.trim() : '';
                    if (raw) {
                        try {
                            payload = JSON.parse(raw);
                        } catch (parseError) {
                            payload = null;
                        }
                    }
                }

                if (status >= 200 && status < 300 && payload?.success === true) {
                    emitProgress({
                        loaded: initialTotal ?? 0,
                        total: initialTotal,
                        lengthComputable: typeof initialTotal === 'number',
                        isComplete: true,
                    });
                    resolve(payload);
                    return;
                }

                const message = typeof payload?.error === 'string' && payload.error.trim()
                    ? payload.error.trim()
                    : 'Não foi possível processar a playlist enviada.';
                reject(new Error(message));
            };

            xhr.onerror = () => {
                reject(new Error('Não foi possível enviar a playlist. Verifique sua conexão e tente novamente.'));
            };

            xhr.onabort = () => {
                reject(new Error('O envio da playlist foi cancelado.'));
            };

            xhr.send(formData);
        });
    }

    function handlePlaylistPayload(payload) {
        if (!payload || typeof payload !== 'object') {
            throw new Error('Resposta inválida do servidor.');
        }

        const content = typeof payload.content === 'string' ? payload.content : '';
        if (!content.trim()) {
            throw new Error('A playlist retornou vazia.');
        }

        const channels = parseM3U(content);
        if (!channels.length) {
            throw new Error('Não foram encontrados canais na playlist informada.');
        }

        const fileName = payload.fileName || payload.originalName || payload.storedName || 'playlist.m3u';
        state.fileName = fileName;
        updateFileLabel(fileName);
        setChannels(channels);
        enterEditor();
    }

    function normalizeGroup(name) {
        const trimmed = (name ?? '').trim();
        return trimmed.length ? trimmed : UNGROUPED_LABEL;
    }

    function generateId() {
        if (typeof crypto !== 'undefined' && crypto.randomUUID) {
            return crypto.randomUUID();
        }
        return 'ch-' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36);
    }

    function parseM3U(text) {
        const lines = text.replace(/\r\n?/g, '\n').split('\n');
        const parsed = [];
        let current = null;

        for (const rawLine of lines) {
            const line = rawLine.trim();
            if (!line) continue;

            if (line.startsWith('#EXTINF')) {
                const info = line.substring(line.indexOf(':') + 1).trim();
                const lastComma = info.lastIndexOf(',');
                const attributesPart = lastComma >= 0 ? info.substring(0, lastComma).trim() : '';
                const title = lastComma >= 0 ? info.substring(lastComma + 1).trim() : info;
                const attributes = {};
                const extras = [];

                const regex = /(\w[\w-]*)="([^"]*)"/g;
                let match;
                while ((match = regex.exec(attributesPart)) !== null) {
                    const [, key, value] = match;
                    if (knownAttributes.includes(key)) {
                        attributes[key] = value;
                    } else {
                        extras.push({ key, value });
                    }
                }

                current = {
                    uid: generateId(),
                    name: attributes['tvg-name'] || title || '',
                    tvgName: attributes['tvg-name'] || title || '',
                    group: attributes['group-title'] || '',
                    url: '',
                    tvgId: attributes['tvg-id'] || '',
                    logo: attributes['tvg-logo'] || '',
                    extras
                };
                continue;
            }

            if (!line.startsWith('#') && current) {
                current.url = line;
                parsed.push(current);
                current = null;
            }
        }

        return parsed;
    }

    function escapeAttribute(value) {
        if (value == null) {
            return '';
        }

        return String(value)
            .replace(/[\r\n]+/g, ' ')
            .trim();
    }

    function serializeM3U(channels) {
        const lines = ['#EXTM3U'];

        channels.forEach((channel) => {
            const attributes = [];
            if (channel.tvgId) attributes.push(`tvg-id="${escapeAttribute(channel.tvgId)}"`);
            const tvgName = channel.tvgName || channel.name;
            if (tvgName) attributes.push(`tvg-name="${escapeAttribute(tvgName)}"`);
            if (channel.logo) attributes.push(`tvg-logo="${escapeAttribute(channel.logo)}"`);
            if (channel.group) attributes.push(`group-title="${escapeAttribute(channel.group)}"`);

            if (Array.isArray(channel.extras)) {
                channel.extras.forEach(({ key, value }) => {
                    if (!key) return;
                    if (knownAttributes.includes(key)) return;
                    const safeValue = typeof value === 'string' ? value : '';
                    attributes.push(`${key}="${escapeAttribute(safeValue)}"`);
                });
            }

            const displayName = channel.name || tvgName || 'Canal sem nome';
            const infoLine = attributes.length
                ? `#EXTINF:-1 ${attributes.join(' ')},${displayName}`
                : `#EXTINF:-1,${displayName}`;
            lines.push(infoLine);
            lines.push(channel.url || '');
        });

        return lines.join('\n');
    }

    function computeGroups() {
        const groups = new Map();
        state.channels.forEach((channel) => {
            const key = normalizeGroup(channel.group);
            if (!groups.has(key)) {
                groups.set(key, []);
            }
            groups.get(key).push(channel);
        });

        return Array.from(groups.entries())
            .map(([name, items]) => {
                const exclusions = state.groupExclusions.get(name);
                const available = exclusions
                    ? items.filter((item) => !exclusions.has(item.uid))
                    : items.slice();
                const sampleSource = available.length ? available : items;
                const logoSource = sampleSource.find((item) => item.logo) || items.find((item) => item.logo);

                return {
                    name,
                    label: name,
                    size: available.length,
                    total: items.length,
                    sample: sampleSource.slice(0, 3).map((item) => item.name || item.tvgName || 'Canal sem nome'),
                    logo: logoSource?.logo || '',
                    allRemoved: available.length === 0 && items.length > 0
                };
            })
            .sort((a, b) => a.name.localeCompare(b.name, 'pt-BR', { sensitivity: 'base' }));
    }

    function getExportableChannels() {
        return state.channels.filter((channel) => {
            const groupName = normalizeGroup(channel.group);
            if (state.selectedGroups.size && !state.selectedGroups.has(groupName)) {
                return false;
            }
            const exclusions = state.groupExclusions.get(groupName);
            return !(exclusions && exclusions.has(channel.uid));
        });
    }

    function getChannelsForActiveGroup() {
        if (!state.activeGroup) {
            return [];
        }
        const exclusions = state.groupExclusions.get(state.activeGroup);
        return state.channels.filter((channel) => {
            if (normalizeGroup(channel.group) !== state.activeGroup) {
                return false;
            }
            return !(exclusions && exclusions.has(channel.uid));
        });
    }

    function ensureActiveGroup(groups) {
        if (!groups.length) {
            state.activeGroup = null;
            return;
        }

        if (state.activeGroup && groups.some((group) => group.name === state.activeGroup)) {
            return;
        }

        if (state.selectedGroups.size) {
            const firstSelected = Array.from(state.selectedGroups).find((name) => groups.some((group) => group.name === name));
            state.activeGroup = firstSelected ?? groups[0].name;
        } else {
            state.activeGroup = groups[0].name;
        }
    }

    function setChannels(channels) {
        state.channels = channels.map((channel) => ({
            uid: channel.uid || generateId(),
            name: channel.name || '',
            tvgName: channel.tvgName || channel.name || '',
            group: channel.group || '',
            url: channel.url || '',
            tvgId: channel.tvgId || '',
            logo: channel.logo || '',
            extras: Array.isArray(channel.extras) ? channel.extras : []
        }));
        state.selectedGroups.clear();
        state.groupExclusions.clear();
        state.activeGroup = null;
        resetPagination();
        if (isEditPanelOpen()) {
            closeEditPanel();
        }
        render();
    }

    function updateFileLabel(name) {
        if (!fileLabel) return;
        fileLabel.textContent = name || 'Enviar arquivo M3U';
    }

    function updateCounts(groups) {
        const availableCount = groups.reduce((count, group) => (
            state.selectedGroups.has(group.name) ? count : count + 1
        ), 0);
        groupsCountLabel.textContent = String(availableCount);
        selectedCountLabel.textContent = String(state.selectedGroups.size);
        btnExportSelection.textContent = state.selectedGroups.size
            ? `Exportar seleção (${state.selectedGroups.size})`
            : 'Exportar seleção';
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function ensurePaginationState() {
        if (!state.pagination) {
            state.pagination = {};
        }
    }

    function resetPagination(keys = PAGINATION_KEYS) {
        ensurePaginationState();
        const targets = Array.isArray(keys) && keys.length ? keys : PAGINATION_KEYS;
        targets.forEach((key) => {
            state.pagination[key] = 1;
        });
    }

    function paginateCollection(items, key, pageSize = DEFAULT_PAGE_SIZE) {
        ensurePaginationState();
        const source = Array.isArray(items) ? items : [];
        const totalItems = source.length;
        const totalPages = totalItems ? Math.ceil(totalItems / pageSize) : 0;
        let currentPage = Math.max(1, state.pagination[key] ?? 1);

        if (totalPages > 0 && currentPage > totalPages) {
            currentPage = totalPages;
        }

        if (totalPages === 0) {
            currentPage = 1;
        }

        state.pagination[key] = currentPage;

        const startIndex = totalPages > 0 ? (currentPage - 1) * pageSize : 0;
        const pageItems = totalPages > 0 ? source.slice(startIndex, startIndex + pageSize) : [];

        return {
            items: pageItems,
            currentPage,
            totalPages,
            totalItems
        };
    }

    function renderPaginationControls(container, { currentPage = 1, totalPages = 0, totalItems = 0 } = {}) {
        if (!container) {
            return;
        }

        if (!totalItems || totalPages <= 1) {
            container.hidden = true;
            container.innerHTML = '';
            container.dataset.totalPages = '0';
            container.dataset.currentPage = '1';
            return;
        }

        container.hidden = false;
        container.dataset.totalPages = String(totalPages);
        container.dataset.currentPage = String(currentPage);
        container.innerHTML = `
            <button type="button" class="pagination-button" data-page-action="first" aria-label="Primeira página" title="Primeira página"${currentPage === 1 ? ' disabled' : ''}>&laquo;</button>
            <button type="button" class="pagination-button" data-page-action="prev" aria-label="Página anterior" title="Página anterior"${currentPage === 1 ? ' disabled' : ''}>&lsaquo;</button>
            <span class="pagination-info">Página ${currentPage} de ${totalPages}</span>
            <button type="button" class="pagination-button" data-page-action="next" aria-label="Próxima página" title="Próxima página"${currentPage === totalPages ? ' disabled' : ''}>&rsaquo;</button>
            <button type="button" class="pagination-button" data-page-action="last" aria-label="Última página" title="Última página"${currentPage === totalPages ? ' disabled' : ''}>&raquo;</button>
        `;
    }

    function setupPagination(container, key) {
        if (!container) {
            return;
        }

        container.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-page-action]');
            if (!button) {
                return;
            }

            const totalPages = Number(container.dataset.totalPages || '0');
            if (!totalPages) {
                return;
            }

            const action = button.dataset.pageAction;
            const current = Math.max(1, state.pagination?.[key] ?? 1);
            let target = current;

            if (action === 'first') {
                target = 1;
            } else if (action === 'prev') {
                target = Math.max(1, current - 1);
            } else if (action === 'next') {
                target = Math.min(totalPages, current + 1);
            } else if (action === 'last') {
                target = totalPages;
            } else {
                return;
            }

            if (target !== current) {
                ensurePaginationState();
                state.pagination[key] = target;
                render();
            }
        });
    }

    function getExclusionSet(groupName, create = true) {
        if (!state.groupExclusions.has(groupName)) {
            if (!create) {
                return null;
            }
            state.groupExclusions.set(groupName, new Set());
        }
        return state.groupExclusions.get(groupName) || null;
    }

    function pruneMissingSelections(groups) {
        const validNames = new Set(groups.map((group) => group.name));
        let changed = false;

        state.selectedGroups.forEach((name) => {
            if (!validNames.has(name)) {
                state.selectedGroups.delete(name);
                state.groupExclusions.delete(name);
                if (state.activeGroup === name) {
                    state.activeGroup = null;
                }
                changed = true;
            }
        });

        Array.from(state.groupExclusions.keys()).forEach((name) => {
            if (!validNames.has(name)) {
                state.groupExclusions.delete(name);
                changed = true;
            }
        });

        return changed;
    }

    function createGroupLogoMarkup(group) {
        if (group.logo) {
            return `<img src="${escapeHtml(group.logo)}" alt="Logo de ${escapeHtml(group.label)}">`;
        }
        const initial = (group.label || '').trim().charAt(0).toUpperCase() || '#';
        return `<span>${escapeHtml(initial)}</span>`;
    }

    function createGroupSubtitle(group) {
        const baseLabel = group.total !== group.size
            ? `${group.size} de ${group.total} canal${group.total === 1 ? '' : 's'}`
            : `${group.size} canal${group.size === 1 ? '' : 's'}`;
        return escapeHtml(baseLabel);
    }

    function createChannelThumb(channel) {
        if (channel.logo) {
            return `<img src="${escapeHtml(channel.logo)}" alt="Logo de ${escapeHtml(channel.name || channel.tvgName || 'Canal')}">`;
        }
        const label = channel.name || channel.tvgName || 'Canal';
        const initial = label.trim().charAt(0).toUpperCase() || '#';
        return `<span>${escapeHtml(initial)}</span>`;
    }

    function createDualItem(channel, action) {
        const label = channel.name || channel.tvgName || 'Canal sem nome';
        const category = (channel.group || '').trim();
        const subtitleRaw = category
            ? `Categoria: ${category}`
            : (channel.tvgId ? `ID: ${channel.tvgId}` : channel.url || '');
        const subtitle = subtitleRaw ? `<small title="${escapeHtml(subtitleRaw)}">${escapeHtml(subtitleRaw)}</small>` : '';
        const buttonLabel = action === 'remove-channel' ? 'Remover' : 'Adicionar';
        const buttonClass = action === 'remove-channel' ? 'icon-button danger' : 'icon-button';

        return `
            <article class="dual-item" data-uid="${escapeHtml(channel.uid)}">
                <div class="dual-info">
                    <div class="channel-thumb">${createChannelThumb(channel)}</div>
                    <div>
                        <strong title="${escapeHtml(label)}">${escapeHtml(label)}</strong>
                        ${subtitle}
                    </div>
                </div>
                <button type="button" class="${buttonClass}" data-action="${action}" data-uid="${escapeHtml(channel.uid)}">${buttonLabel}</button>
            </article>
        `;
    }

    function renderGroups(groups) {
        const available = groups.filter((group) => !state.selectedGroups.has(group.name));
        const filtered = available.filter((group) => {
            if (!state.search) return true;
            return group.name.toLowerCase().includes(state.search.toLowerCase());
        });

        if (!filtered.length) {
            const message = available.length
                ? 'Nenhuma categoria encontrada para este filtro.'
                : 'Todas as Categorias foram selecionadas.';
            groupsList.innerHTML = `<p class="empty-state">${message}</p>`;
            renderPaginationControls(groupsPagination, { currentPage: 1, totalPages: 0, totalItems: 0 });
            return;
        }

        const pagination = paginateCollection(filtered, 'groups');
        const items = pagination.items
            .map((group) => {
                const isActive = state.activeGroup === group.name;
                return `
                    <article class="group-card${isActive ? ' active' : ''}" data-group="${escapeHtml(group.name)}">
                        <div class="group-info">
                            <div class="group-logo">${createGroupLogoMarkup(group)}</div>
                            <div class="group-meta">
                                <h4>${escapeHtml(group.label)}</h4>
                                <small>${createGroupSubtitle(group)}</small>
                            </div>
                        </div>
                        <div class="group-actions">
                            <button class="icon-button" type="button" data-role="toggle-selection" data-group="${escapeHtml(group.name)}">Adicionar</button>
                        </div>
                    </article>
                `;
            })
            .join('');

        groupsList.innerHTML = items;
        renderPaginationControls(groupsPagination, pagination);
    }

    function renderSelected(groups) {
        if (!state.selectedGroups.size) {
            selectedGroupsList.innerHTML = '<p class="empty-state">Escolha Categorias à esquerda para incluí-las aqui.</p>';
            renderPaginationControls(selectedPagination, { currentPage: 1, totalPages: 0, totalItems: 0 });
            return;
        }

        const lookup = new Map(groups.map((group) => [group.name, group]));
        const ordered = Array.from(state.selectedGroups)
            .filter((name) => lookup.has(name))
            .map((name) => lookup.get(name));

        if (!ordered.length) {
            selectedGroupsList.innerHTML = '<p class="empty-state">Escolha Categorias à esquerda para incluí-las aqui.</p>';
            renderPaginationControls(selectedPagination, { currentPage: 1, totalPages: 0, totalItems: 0 });
            return;
        }

        const pagination = paginateCollection(ordered, 'selected');
        const items = pagination.items
            .map((group) => {
                const isActive = state.activeGroup === group.name;
                return `
                    <article class="group-card${isActive ? ' active' : ''}" data-group="${escapeHtml(group.name)}">
                        <div class="group-info">
                            <div class="group-logo">${createGroupLogoMarkup(group)}</div>
                            <div class="group-meta">
                                <h4>${escapeHtml(group.label)}</h4>
                                <small>${createGroupSubtitle(group)}</small>
                            </div>
                        </div>
                        <div class="group-actions">
                            <button class="icon-button neutral" type="button" data-role="edit-group" data-group="${escapeHtml(group.name)}">Editar</button>
                            <button class="icon-button danger" type="button" data-role="remove-selection" data-group="${escapeHtml(group.name)}">Remover</button>
                        </div>
                    </article>
                `;
            })
            .join('');

        selectedGroupsList.innerHTML = items;
        renderPaginationControls(selectedPagination, pagination);
    }

    function updateExportPreview() {
        const channels = getExportableChannels();
        const text = channels.length ? serializeM3U(channels) : '';
        exportPreview.value = text;
    }

    function updateActionsState() {
        const exportable = getExportableChannels();
        const canExport = exportable.length > 0;
        const hasSelection = state.selectedGroups.size > 0;
        const canExportSelection = hasSelection && canExport;

        if (btnDownload) {
            btnDownload.disabled = !canExport;
        }
        if (btnExportSelection) {
            btnExportSelection.disabled = !canExportSelection;
        }
    }

    function downloadPlaylist() {
        const channels = getExportableChannels();
        if (!channels.length) {
            alert('Adicione ou importe canais antes de exportar.');
            return;
        }

        const blob = new Blob([serializeM3U(channels)], { type: 'audio/x-mpegurl' });
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = state.fileName ? `custom-${state.fileName}` : 'playlist.m3u';
        document.body.appendChild(anchor);
        anchor.click();
        document.body.removeChild(anchor);
        URL.revokeObjectURL(url);
    }

    function render() {
        const groups = computeGroups();
        pruneMissingSelections(groups);
        ensureActiveGroup(groups);
        renderGroups(groups);
        renderSelected(groups);
        updateCounts(groups);
        updateExportPreview();
        updateActionsState();
        if (editingGroup && !state.selectedGroups.has(editingGroup)) {
            closeEditPanel();
        } else if (editingGroup && isEditPanelOpen()) {
            renderEditPanel();
        }
    }

    function isEditPanelOpen() {
        return Boolean(editPanel && !editPanel.classList.contains('hidden'));
    }

    function toggleEditPanel(isVisible) {
        if (!editPanel) {
            return;
        }

        editPanel.classList.toggle('hidden', !isVisible);
        if (boardGrid) {
            boardGrid.classList.toggle('hidden', isVisible);
        }
    }

    function renderEditPanel() {
        if (!editingGroup) {
            return;
        }

        const normalized = editingGroup;
        const channels = state.channels.filter((channel) => normalizeGroup(channel.group) === normalized);
        const exclusions = getExclusionSet(normalized, false);
        const excludedSet = exclusions ?? new Set();
        const selected = channels.filter((channel) => !excludedSet.has(channel.uid));
        const available = channels.filter((channel) => excludedSet.has(channel.uid));

        const displayName = channels.find((channel) => (channel.group || '').trim())?.group?.trim() || normalized;
        editModalTitle.textContent = `Editar categoria: ${displayName}`;
        editModalSubtitle.textContent = channels.length
            ? `${selected.length} de ${channels.length} canais serão exportados.`
            : 'Esta categoria não possui canais.';

        editSelectedCount.textContent = String(selected.length);
        editAvailableCount.textContent = String(available.length);

        const selectedPagination = paginateCollection(selected, 'editSelected');
        const availablePagination = paginateCollection(available, 'editAvailable');

        editSelectedList.innerHTML = selectedPagination.totalItems
            ? selectedPagination.items.map((channel) => createDualItem(channel, 'remove-channel')).join('')
            : '<p class="empty-state small">Nenhum canal selecionado para exportação.</p>';

        editAvailableList.innerHTML = availablePagination.totalItems
            ? availablePagination.items.map((channel) => createDualItem(channel, 'restore-channel')).join('')
            : '<p class="empty-state small">Nenhum canal disponível para esta categoria.</p>';

        renderPaginationControls(editSelectedPagination, selectedPagination);
        renderPaginationControls(editAvailablePagination, availablePagination);
    }

    function openEditPanel(groupName) {
        editingGroup = groupName;
        resetPagination(['editAvailable', 'editSelected']);
        toggleEditPanel(true);
        renderEditPanel();
        // A rolagem automática para o painel de edição fazia a página descer
        // inesperadamente sempre que o usuário tentava editar uma categoria.
        // Mantemos o foco visual no ponto atual removendo o scroll forçado,
        // permitindo que o usuário controle manualmente a navegação.
    }

    function closeEditPanel() {
        editingGroup = null;
        toggleEditPanel(false);
    }

    function handleEditListAction(event) {
        const button = event.target.closest('button[data-action][data-uid]');
        if (!button || !editingGroup) {
            return;
        }

        const { action, uid } = button.dataset;
        if (!uid || !action) {
            return;
        }

        if (action === 'remove-channel') {
            const exclusions = getExclusionSet(editingGroup, true);
            exclusions?.add(uid);
        } else if (action === 'restore-channel') {
            const exclusions = getExclusionSet(editingGroup, false);
            if (exclusions) {
                exclusions.delete(uid);
                if (!exclusions.size) {
                    state.groupExclusions.delete(editingGroup);
                }
            }
        } else {
            return;
        }

        render();
    }

    async function importFromFile(file, { fromLanding = false, inputElement = null } = {}) {
        if (!file) return;

        const resetInput = () => {
            if (inputElement) {
                inputElement.value = '';
            }
        };

        const fileSize = typeof file.size === 'number' && Number.isFinite(file.size) ? file.size : null;
        const uploadMessage = `Enviando ${file.name || 'playlist.m3u'}...`;

        const handleProgress = ({ loaded = 0, total = null, isComplete = false } = {}) => {
            const effectiveTotal = typeof total === 'number' && total > 0 ? total : fileSize;
            const numericLoaded = typeof loaded === 'number' && loaded >= 0 ? loaded : 0;
            const limitedLoaded = effectiveTotal ? Math.min(numericLoaded, effectiveTotal) : numericLoaded;

            let message = uploadMessage;
            if (effectiveTotal) {
                const percent = isComplete ? 100 : Math.min(100, Math.round((limitedLoaded / effectiveTotal) * 100));
                message = `${uploadMessage} (${percent}%)`;
            }

            if (isComplete) {
                message = 'Processando playlist...';
            }

            updateUploadProgress({
                loaded: limitedLoaded,
                total: effectiveTotal,
                message,
                isComplete,
            });
        };

        try {
            if (fromLanding) {
                setLandingBusy(true);
                setLandingStatus(`Processando ${file.name || 'playlist.m3u'}...`, 'info');
            }

            showUploadProgress(uploadMessage);
            handleProgress({ loaded: 0, total: fileSize, isComplete: false });

            const payload = await uploadPlaylistPayload({
                file,
                onProgress: handleProgress,
            });
            handlePlaylistPayload(payload);

            if (fromLanding) {
                setLandingStatus(`Arquivo ${payload.fileName || file.name} importado com sucesso!`, 'success');
            }
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Não foi possível importar a playlist.';
            if (fromLanding) {
                setLandingStatus(message, 'error');
            } else {
                alert(message);
            }
            throw error;
        } finally {
            if (fromLanding) {
                setLandingBusy(false);
            }
            hideUploadProgress();
            resetInput();
        }
    }

    async function importFromUrl(url, { fromLanding = false } = {}) {
        if (!url) return;

        try {
            if (fromLanding) {
                setLandingBusy(true);
                setLandingStatus('Baixando playlist da URL informada...', 'info');
            }

            const message = 'Baixando playlist...';
            showUploadProgress(message);
            updateUploadProgress({
                loaded: 0,
                total: null,
                message,
                isComplete: false,
            });

            const payload = await uploadPlaylistPayload({
                url,
                onProgress: ({ isComplete = false } = {}) => {
                    updateUploadProgress({
                        loaded: 0,
                        total: null,
                        message: isComplete ? 'Processando playlist...' : message,
                        isComplete,
                    });
                },
            });
            handlePlaylistPayload(payload);

            if (fromLanding) {
                if (landingUrlInput) {
                    landingUrlInput.value = '';
                }
                setLandingStatus('Playlist baixada com sucesso!', 'success');
            }
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Não foi possível importar a playlist.';
            if (fromLanding) {
                setLandingStatus(message, 'error');
            } else {
                alert(message);
            }
            throw error;
        } finally {
            if (fromLanding) {
                setLandingBusy(false);
            }
            hideUploadProgress();
        }
    }

    function openPasteModal() {
        pasteModal.hidden = false;
        requestAnimationFrame(() => {
            m3uText.focus();
        });
    }

    function closePasteModal() {
        pasteModal.hidden = true;
        m3uText.value = '';
    }

    btnOpenPaste.addEventListener('click', openPasteModal);
    btnCloseModal.addEventListener('click', closePasteModal);

    pasteModal.addEventListener('click', (event) => {
        if (event.target === pasteModal) {
            closePasteModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            if (isEditPanelOpen()) {
                closeEditPanel();
                return;
            }
            if (!pasteModal.hidden) {
                closePasteModal();
            }
        }
    });

    btnImportText.addEventListener('click', () => {
        const value = m3uText.value.trim();
        if (!value) {
            alert('Cole o conteúdo da lista M3U antes de importar.');
            return;
        }
        try {
            const channels = parseM3U(value);
            if (!channels.length) {
                alert('Não foi possível encontrar canais no texto informado.');
                return;
            }
            state.fileName = 'playlist.m3u';
            updateFileLabel('Conteúdo colado');
            setChannels(channels);
            enterEditor();
            closePasteModal();
        } catch (error) {
            console.error(error);
            alert('Ocorreu um erro ao interpretar o conteúdo informado.');
        }
    });

    btnCloseEdit.addEventListener('click', () => {
        closeEditPanel();
    });

    editSelectedList.addEventListener('click', handleEditListAction);
    editAvailableList.addEventListener('click', handleEditListAction);

    if (landingTabs.length) {
        switchLandingTab('file');
        landingTabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                if (landingBusy) return;
                const target = tab.dataset.landingTab;
                if (target) {
                    switchLandingTab(target);
                    if (target === 'url' && landingUrlInput) {
                        landingUrlInput.focus();
                    }
                }
            });
        });
    }

    if (landingChooseButton) {
        landingChooseButton.addEventListener('click', () => {
            if (landingBusy) return;
            landingFileInput?.click();
        });
    }

    if (landingDropZone) {
        const clearDragState = () => landingDropZone.classList.remove('is-dragover');

        landingDropZone.addEventListener('click', () => {
            if (landingBusy) return;
            landingFileInput?.click();
        });

        landingDropZone.addEventListener('keydown', (event) => {
            if (landingBusy) return;
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                landingFileInput?.click();
            }
        });

        ['dragenter', 'dragover'].forEach((type) => {
            landingDropZone.addEventListener(type, (event) => {
                event.preventDefault();
                if (landingBusy) return;
                landingDropZone.classList.add('is-dragover');
            });
        });

        ['dragleave', 'dragend'].forEach((type) => {
            landingDropZone.addEventListener(type, clearDragState);
        });

        landingDropZone.addEventListener('drop', (event) => {
            event.preventDefault();
            clearDragState();
            if (landingBusy) return;
            const files = event.dataTransfer?.files;
            if (files?.length) {
                importFromFile(files[0], { fromLanding: true, inputElement: landingFileInput })
                    .catch((error) => console.error(error));
            }
        });
    }

    if (landingFileInput) {
        landingFileInput.addEventListener('change', () => {
            if (landingBusy) return;
            if (landingFileInput.files?.length) {
                importFromFile(landingFileInput.files[0], { fromLanding: true, inputElement: landingFileInput })
                    .catch((error) => console.error(error));
            }
        });
    }

    if (landingUrlForm) {
        landingUrlForm.addEventListener('submit', (event) => {
            event.preventDefault();
            if (landingBusy) return;
            const url = landingUrlInput?.value.trim() ?? '';
            if (!url) {
                setLandingStatus('Informe a URL da playlist antes de continuar.', 'error');
                landingUrlInput?.focus();
                return;
            }
            importFromUrl(url, { fromLanding: true }).catch((error) => console.error(error));
        });
    }

    fileInput.addEventListener('change', () => {
        if (fileInput.files?.length) {
            importFromFile(fileInput.files[0], { inputElement: fileInput }).catch((error) => {
                console.error(error);
            });
        } else {
            updateFileLabel(null);
        }
    });

    groupSearch.addEventListener('input', (event) => {
        state.search = event.target.value;
        resetPagination(['groups']);
        render();
    });

    setupPagination(groupsPagination, 'groups');
    setupPagination(selectedPagination, 'selected');
    setupPagination(editAvailablePagination, 'editAvailable');
    setupPagination(editSelectedPagination, 'editSelected');

    groupsList.addEventListener('click', (event) => {
        const toggleButton = event.target.closest('[data-role="toggle-selection"]');
        if (toggleButton) {
            const groupName = toggleButton.dataset.group;
            if (!groupName) return;
            if (state.selectedGroups.has(groupName)) {
                state.selectedGroups.delete(groupName);
                state.groupExclusions.delete(groupName);
                if (state.activeGroup === groupName) {
                    state.activeGroup = null;
                }
            } else {
                state.selectedGroups.add(groupName);
                state.activeGroup = groupName;
            }
            render();
            return;
        }

        const card = event.target.closest('.group-card');
        if (!card) return;
        const groupName = card.dataset.group;
        if (!groupName) return;
        state.activeGroup = groupName;
        render();
    });

    selectedGroupsList.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-role="remove-selection"]');
        if (removeButton) {
            const groupName = removeButton.dataset.group;
            if (!groupName) return;
            state.selectedGroups.delete(groupName);
            state.groupExclusions.delete(groupName);
            if (state.activeGroup === groupName) {
                state.activeGroup = null;
            }
            render();
            return;
        }

        const editButton = event.target.closest('[data-role="edit-group"]');
        if (editButton) {
            const groupName = editButton.dataset.group;
            if (!groupName) return;
            state.activeGroup = groupName;
            render();
            openEditPanel(groupName);
            return;
        }

        const card = event.target.closest('.group-card');
        if (!card) return;
        const groupName = card.dataset.group;
        if (!groupName) return;
        state.activeGroup = groupName;
        render();
    });

    if (btnExportSelection) {
        btnExportSelection.addEventListener('click', downloadPlaylist);
    }

    if (btnDownload) {
        btnDownload.addEventListener('click', downloadPlaylist);
    }

    // Interface inicial
    hideUploadProgress();
    updateFileLabel(null);
    render();
})();
