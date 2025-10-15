(() => {
    const UNGROUPED_LABEL = 'Sem grupo';

    const state = {
        channels: [],
        selectedGroups: new Set(),
        search: '',
        activeGroup: null,
        fileName: null,
        groupExclusions: new Map()
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
    const btnDownload = document.getElementById('btnDownload');
    const btnCopy = document.getElementById('btnCopy');
    const btnExportSelection = document.getElementById('btnExportSelection');
    const btnClearList = document.getElementById('btnClearList');
    const exportPreview = document.getElementById('exportPreview');
    const groupsCountLabel = document.getElementById('groupsCount');
    const selectedCountLabel = document.getElementById('selectedCount');
    const editGroupModal = document.getElementById('editGroupModal');
    const editModalTitle = document.getElementById('editModalTitle');
    const editModalSubtitle = document.getElementById('editModalSubtitle');
    const editAvailableList = document.getElementById('editAvailableList');
    const editSelectedList = document.getElementById('editSelectedList');
    const editAvailableCount = document.getElementById('editAvailableCount');
    const editSelectedCount = document.getElementById('editSelectedCount');
    const btnCloseEdit = document.getElementById('btnCloseEdit');

    const knownAttributes = ['tvg-id', 'tvg-name', 'tvg-logo', 'group-title'];
    const uploadEndpointMeta = document.querySelector('meta[name="edit-m3u-upload-endpoint"]');
    const uploadEndpoint = uploadEndpointMeta?.content?.trim() || 'upload.php';

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

    async function uploadPlaylistPayload({ file, url }) {
        const formData = new FormData();
        if (file) {
            formData.append('playlist', file);
        }
        if (url) {
            formData.append('playlist_url', url);
        }

        const response = await fetch(uploadEndpoint, {
            method: 'POST',
            body: formData,
        });

        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('Resposta inválida do servidor.');
        }

        if (!response.ok || !payload || payload.success !== true) {
            const message = typeof payload?.error === 'string' && payload.error.trim()
                ? payload.error.trim()
                : 'Não foi possível processar a playlist enviada.';
            throw new Error(message);
        }

        return payload;
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
        return value.replace(/"/g, '\\"');
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
        if (!editGroupModal.hidden) {
            closeEditModal();
        }
        render();
    }

    function updateFileLabel(name) {
        if (!fileLabel) return;
        fileLabel.textContent = name || 'Enviar arquivo M3U';
    }

    function updateCounts(groups) {
        groupsCountLabel.textContent = String(groups.length);
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
        const descriptor = escapeHtml(baseLabel);

        if (group.allRemoved) {
            return `${descriptor} • todos os canais removidos`;
        }

        const sample = group.sample.filter(Boolean).map(escapeHtml).join(' • ');
        return sample ? `${descriptor} • ${sample}` : descriptor;
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
        const subtitleRaw = channel.tvgId ? `ID: ${channel.tvgId}` : channel.url || '';
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
        const filtered = groups.filter((group) => {
            if (!state.search) return true;
            return group.name.toLowerCase().includes(state.search.toLowerCase());
        });

        if (!filtered.length) {
            groupsList.innerHTML = '<p class="empty-state">Nenhum grupo encontrado para este filtro.</p>';
            return;
        }

        const items = filtered
            .map((group) => {
                const isActive = state.activeGroup === group.name;
                const isSelected = state.selectedGroups.has(group.name);
                const buttonLabel = isSelected ? 'Remover' : 'Adicionar';
                const buttonClass = isSelected ? 'icon-button danger' : 'icon-button';
                return `
                    <article class="group-card${isActive ? ' active' : ''}${isSelected ? ' is-selected' : ''}" data-group="${escapeHtml(group.name)}">
                        <div class="group-info">
                            <div class="group-logo">${createGroupLogoMarkup(group)}</div>
                            <div class="group-meta">
                                <h4>${escapeHtml(group.label)}</h4>
                                <small>${createGroupSubtitle(group)}</small>
                            </div>
                        </div>
                        <div class="group-actions">
                            <button class="${buttonClass}" type="button" data-role="toggle-selection" data-group="${escapeHtml(group.name)}">${buttonLabel}</button>
                        </div>
                    </article>
                `;
            })
            .join('');

        groupsList.innerHTML = items;
    }

    function renderSelected(groups) {
        if (!state.selectedGroups.size) {
            selectedGroupsList.innerHTML = '<p class="empty-state">Escolha grupos à esquerda para incluí-los aqui.</p>';
            return;
        }

        const lookup = new Map(groups.map((group) => [group.name, group]));
        const items = Array.from(state.selectedGroups)
            .filter((name) => lookup.has(name))
            .map((name) => {
                const group = lookup.get(name);
                const isActive = state.activeGroup === name;
                return `
                    <article class="group-card${isActive ? ' active' : ''}" data-group="${escapeHtml(name)}">
                        <div class="group-info">
                            <div class="group-logo">${createGroupLogoMarkup(group)}</div>
                            <div class="group-meta">
                                <h4>${escapeHtml(group.label)}</h4>
                                <small>${createGroupSubtitle(group)}</small>
                            </div>
                        </div>
                        <div class="group-actions">
                            <button class="icon-button neutral" type="button" data-role="edit-group" data-group="${escapeHtml(name)}">Editar</button>
                            <button class="icon-button danger" type="button" data-role="remove-selection" data-group="${escapeHtml(name)}">Remover</button>
                        </div>
                    </article>
                `;
            })
            .join('');

        selectedGroupsList.innerHTML = items;
    }

    function updateExportPreview() {
        const channels = getExportableChannels();
        const text = channels.length ? serializeM3U(channels) : '';
        exportPreview.value = text;
    }

    function updateActionsState() {
        const hasChannels = state.channels.length > 0;
        const exportable = getExportableChannels();
        const canExport = exportable.length > 0;
        const hasSelection = state.selectedGroups.size > 0;
        const canExportSelection = hasSelection && canExport;

        btnDownload.disabled = !canExport;
        btnCopy.disabled = !canExport;
        btnClearList.disabled = !hasChannels;
        btnExportSelection.disabled = !canExportSelection;
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
            closeEditModal();
        } else if (editingGroup && !editGroupModal.hidden) {
            renderEditModal();
        }
    }

    function renderEditModal() {
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
        editModalTitle.textContent = `Editar grupo: ${displayName}`;
        editModalSubtitle.textContent = channels.length
            ? `${selected.length} de ${channels.length} canais serão exportados.`
            : 'Este grupo não possui canais.';

        editSelectedCount.textContent = String(selected.length);
        editAvailableCount.textContent = String(available.length);

        editSelectedList.innerHTML = selected.length
            ? selected.map((channel) => createDualItem(channel, 'remove-channel')).join('')
            : '<p class="empty-state small">Nenhum canal selecionado para exportação.</p>';

        editAvailableList.innerHTML = available.length
            ? available.map((channel) => createDualItem(channel, 'restore-channel')).join('')
            : '<p class="empty-state small">Nenhum canal disponível para este grupo.</p>';
    }

    function openEditModal(groupName) {
        editingGroup = groupName;
        editGroupModal.hidden = false;
        renderEditModal();
    }

    function closeEditModal() {
        editingGroup = null;
        editGroupModal.hidden = true;
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

        try {
            if (fromLanding) {
                setLandingBusy(true);
                setLandingStatus(`Processando ${file.name || 'playlist.m3u'}...`, 'info');
            }

            const payload = await uploadPlaylistPayload({ file });
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

            const payload = await uploadPlaylistPayload({ url });
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
            if (!editGroupModal.hidden) {
                closeEditModal();
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
        closeEditModal();
    });

    editGroupModal.addEventListener('click', (event) => {
        if (event.target === editGroupModal) {
            closeEditModal();
        }
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
        render();
    });

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
            openEditModal(groupName);
            return;
        }

        const card = event.target.closest('.group-card');
        if (!card) return;
        const groupName = card.dataset.group;
        if (!groupName) return;
        state.activeGroup = groupName;
        render();
    });

    btnExportSelection.addEventListener('click', () => {
        updateExportPreview();
        exportPreview.classList.remove('flash');
        void exportPreview.offsetWidth; // reinicia animação
        exportPreview.classList.add('flash');
        exportPreview.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });

    btnDownload.addEventListener('click', () => {
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
    });

    btnCopy.addEventListener('click', async () => {
        const channels = getExportableChannels();
        if (!channels.length) {
            alert('Adicione ou importe canais antes de copiar.');
            return;
        }
        try {
            await navigator.clipboard.writeText(serializeM3U(channels));
            btnCopy.textContent = 'Copiado!';
            setTimeout(() => {
                btnCopy.textContent = 'Copiar playlist';
            }, 2000);
        } catch (error) {
            console.error(error);
            alert('Não foi possível copiar para a área de transferência.');
        }
    });

    btnClearList.addEventListener('click', () => {
        if (!state.channels.length) return;
        if (!confirm('Tem certeza de que deseja limpar todos os canais?')) return;
        state.channels = [];
        state.selectedGroups.clear();
        state.activeGroup = null;
        state.fileName = null;
        updateFileLabel(null);
        fileInput.value = '';
        render();
    });

    // Interface inicial
    updateFileLabel(null);
    render();
})();
