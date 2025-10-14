(() => {
    const UNGROUPED_LABEL = 'Sem grupo';

    const state = {
        channels: [],
        selectedGroups: new Set(),
        search: '',
        activeGroup: null,
        fileName: null
    };

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
    const channelsTable = document.getElementById('channelsTable');
    const channelsTitle = document.getElementById('channelsTitle');
    const channelsSubtitle = document.getElementById('channelsSubtitle');
    const btnDownload = document.getElementById('btnDownload');
    const btnCopy = document.getElementById('btnCopy');
    const btnExportSelection = document.getElementById('btnExportSelection');
    const btnClearList = document.getElementById('btnClearList');
    const exportPreview = document.getElementById('exportPreview');
    const groupsCountLabel = document.getElementById('groupsCount');
    const selectedCountLabel = document.getElementById('selectedCount');

    const knownAttributes = ['tvg-id', 'tvg-name', 'tvg-logo', 'group-title'];

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
            .map(([name, items]) => ({
                name,
                label: name,
                size: items.length,
                sample: items.slice(0, 3).map((item) => item.name || item.tvgName || 'Canal sem nome')
            }))
            .sort((a, b) => a.name.localeCompare(b.name, 'pt-BR', { sensitivity: 'base' }));
    }

    function getExportableChannels() {
        if (state.selectedGroups.size) {
            return state.channels.filter((channel) => state.selectedGroups.has(normalizeGroup(channel.group)));
        }
        return state.channels;
    }

    function getChannelsForActiveGroup() {
        if (!state.activeGroup) {
            return [];
        }
        return state.channels.filter((channel) => normalizeGroup(channel.group) === state.activeGroup);
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
        state.activeGroup = null;
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
                const sample = group.sample.filter(Boolean).map(escapeHtml).join(' • ');
                const buttonLabel = isSelected ? 'Remover' : 'Adicionar';
                const buttonClass = isSelected ? 'icon-button danger' : 'icon-button';
                return `
                    <article class="group-card${isActive ? ' active' : ''}${isSelected ? ' is-selected' : ''}" data-group="${escapeHtml(group.name)}">
                        <div class="group-meta">
                            <h4>${escapeHtml(group.label)}</h4>
                            <small>${group.size} canal${group.size === 1 ? '' : 's'}${sample ? ' • ' + sample : ''}</small>
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
                const sample = group.sample.filter(Boolean).map(escapeHtml).join(' • ');
                return `
                    <article class="group-card${isActive ? ' active' : ''}" data-group="${escapeHtml(name)}">
                        <div class="group-meta">
                            <h4>${escapeHtml(group.label)}</h4>
                            <small>${group.size} canal${group.size === 1 ? '' : 's'}${sample ? ' • ' + sample : ''}</small>
                        </div>
                        <div class="group-actions">
                            <button class="icon-button danger" type="button" data-role="remove-selection" data-group="${escapeHtml(name)}">Remover</button>
                        </div>
                    </article>
                `;
            })
            .join('');

        selectedGroupsList.innerHTML = items;
    }

    function renderChannels() {
        if (!state.channels.length) {
            channelsTitle.textContent = 'Canais';
            channelsSubtitle.textContent = 'Selecione um arquivo para começar.';
            channelsTable.innerHTML = '<p class="empty-state">Importe um arquivo para começar.</p>';
            return;
        }

        if (!state.activeGroup) {
            channelsTitle.textContent = 'Canais';
            channelsSubtitle.textContent = 'Selecione um grupo para visualizar os canais disponíveis.';
            channelsTable.innerHTML = '<p class="empty-state">Escolha um grupo para visualizar seus canais.</p>';
            return;
        }

        const channels = getChannelsForActiveGroup();
        channelsTitle.textContent = `${state.activeGroup} • ${channels.length} canal${channels.length === 1 ? '' : 's'}`;
        channelsSubtitle.textContent = state.selectedGroups.size
            ? 'Apenas os grupos selecionados serão exportados.'
            : 'Selecione grupos para exportar apenas o que desejar.';

        if (!channels.length) {
            channelsTable.innerHTML = '<p class="empty-state">Nenhum canal neste grupo.</p>';
            return;
        }

        const rows = channels
            .map((channel) => `
                <tr data-uid="${escapeHtml(channel.uid)}">
                    <td><img class="channel-logo" src="${channel.logo ? escapeHtml(channel.logo) : 'data:image/gif;base64,R0lGODlhAQABAAAAACw='}" alt="Logo de ${escapeHtml(channel.name || channel.tvgName || 'Canal')}"></td>
                    <td><input class="channel-input" type="text" value="${escapeHtml(channel.name)}" data-field="name"></td>
                    <td><input class="channel-input" type="text" value="${escapeHtml(channel.group)}" data-field="group"></td>
                    <td><input class="channel-input" type="url" value="${escapeHtml(channel.url)}" data-field="url"></td>
                    <td><input class="channel-input" type="text" value="${escapeHtml(channel.tvgId)}" data-field="tvgId"></td>
                    <td>
                        <div class="channel-actions">
                            <button type="button" data-action="move-up" title="Mover para cima">▲</button>
                            <button type="button" data-action="move-down" title="Mover para baixo">▼</button>
                            <button type="button" data-action="duplicate" title="Duplicar canal">⧉</button>
                            <button type="button" data-action="remove" class="danger" title="Remover canal">✕</button>
                        </div>
                    </td>
                </tr>
            `)
            .join('');

        channelsTable.innerHTML = `
            <table>
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
                <tbody>${rows}</tbody>
            </table>
        `;
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

        btnDownload.disabled = !canExport;
        btnCopy.disabled = !canExport;
        btnClearList.disabled = !hasChannels;
        btnExportSelection.disabled = !canExport;
    }

    function render() {
        const groups = computeGroups();
        ensureActiveGroup(groups);
        renderGroups(groups);
        renderSelected(groups);
        renderChannels();
        updateCounts(groups);
        updateExportPreview();
        updateActionsState();
    }

    function moveChannel(uid, direction) {
        const index = state.channels.findIndex((channel) => channel.uid === uid);
        if (index === -1) return;
        const newIndex = index + direction;
        if (newIndex < 0 || newIndex >= state.channels.length) return;
        const [channel] = state.channels.splice(index, 1);
        state.channels.splice(newIndex, 0, channel);
        render();
    }

    function removeChannel(uid) {
        state.channels = state.channels.filter((channel) => channel.uid !== uid);
        render();
    }

    function duplicateChannel(uid) {
        const channel = state.channels.find((item) => item.uid === uid);
        if (!channel) return;
        const clone = {
            ...channel,
            uid: generateId(),
            name: `${channel.name || 'Canal'} (cópia)`
        };
        const index = state.channels.findIndex((item) => item.uid === uid);
        state.channels.splice(index + 1, 0, clone);
        render();
    }

    function importFromFile(file) {
        if (!file) return;
        const reader = new FileReader();
        reader.onload = (event) => {
            try {
                const content = String(event.target?.result || '');
                const channels = parseM3U(content);
                if (!channels.length) {
                    alert('Não foi possível encontrar canais no arquivo informado.');
                    return;
                }
                state.fileName = file.name;
                updateFileLabel(file.name);
                setChannels(channels);
            } catch (error) {
                console.error(error);
                alert('Ocorreu um erro ao ler o arquivo M3U.');
            }
        };
        reader.readAsText(file);
    }

    function openModal() {
        pasteModal.hidden = false;
        requestAnimationFrame(() => {
            m3uText.focus();
        });
    }

    function closeModal() {
        pasteModal.hidden = true;
        m3uText.value = '';
    }

    btnOpenPaste.addEventListener('click', openModal);
    btnCloseModal.addEventListener('click', closeModal);

    pasteModal.addEventListener('click', (event) => {
        if (event.target === pasteModal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !pasteModal.hidden) {
            closeModal();
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
            closeModal();
        } catch (error) {
            console.error(error);
            alert('Ocorreu um erro ao interpretar o conteúdo informado.');
        }
    });

    fileInput.addEventListener('change', () => {
        if (fileInput.files?.length) {
            importFromFile(fileInput.files[0]);
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
            } else {
                state.selectedGroups.add(groupName);
            }
            if (!state.selectedGroups.size) {
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
            if (state.activeGroup === groupName) {
                state.activeGroup = null;
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

    channelsTable.addEventListener('input', (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement)) return;
        const field = input.dataset.field;
        if (!field) return;
        const row = input.closest('tr');
        if (!row) return;
        const uid = row.dataset.uid;
        if (!uid) return;
        const channel = state.channels.find((item) => item.uid === uid);
        if (!channel) return;
        channel[field] = input.value;
        if (field === 'name') {
            channel.tvgName = input.value;
        }
        updateExportPreview();
        updateActionsState();
    });

    channelsTable.addEventListener('change', (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement)) return;
        if (!input.dataset.field) return;
        render();
    });

    channelsTable.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) return;
        const row = button.closest('tr');
        if (!row) return;
        const uid = row.dataset.uid;
        if (!uid) return;

        switch (button.dataset.action) {
            case 'move-up':
                moveChannel(uid, -1);
                break;
            case 'move-down':
                moveChannel(uid, 1);
                break;
            case 'remove':
                if (confirm('Remover este canal da lista?')) {
                    removeChannel(uid);
                }
                break;
            case 'duplicate':
                duplicateChannel(uid);
                break;
            default:
                break;
        }
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
