(() => {
    const state = {
        channels: [],
        filter: ''
    };

    const fileInput = document.getElementById('fileInput');
    const importFileButton = document.getElementById('btnImportFile');
    const importTextButton = document.getElementById('btnImportText');
    const clearButton = document.getElementById('btnClearList');
    const addButton = document.getElementById('btnAddChannel');
    const downloadButton = document.getElementById('btnDownload');
    const copyButton = document.getElementById('btnCopy');
    const searchInput = document.getElementById('searchInput');
    const exportPreview = document.getElementById('exportPreview');
    const tableBody = document.querySelector('#channelsTable tbody');

    const knownAttributes = ['tvg-id', 'tvg-name', 'tvg-logo', 'group-title'];

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
                    const safeValue = typeof value === 'string' ? value : '';
                    if (knownAttributes.includes(key)) return;
                    attributes.push(`${key}="${escapeAttribute(safeValue)}"`);
                });
            }

            const infoLine = attributes.length
                ? `#EXTINF:-1 ${attributes.join(' ')},${channel.name || tvgName || 'Canal Sem Nome'}`
                : `#EXTINF:-1,${channel.name || 'Canal Sem Nome'}`;
            lines.push(infoLine);
            lines.push(channel.url || '');
        });

        return lines.join('\n');
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
        render();
    }

    function updateExportPreview() {
        exportPreview.value = serializeM3U(state.channels);
    }

    function render() {
        tableBody.innerHTML = '';
        const filter = state.filter.trim().toLowerCase();
        const rows = state.channels
            .map((channel, index) => ({ channel, index }))
            .filter(({ channel }) => {
                if (!filter) return true;
                const haystack = [channel.name, channel.group, channel.url, channel.tvgId]
                    .join(' ') 
                    .toLowerCase();
                return haystack.includes(filter);
            });

        if (!rows.length) {
            const emptyRow = document.createElement('tr');
            emptyRow.className = 'empty-row';
            const cell = document.createElement('td');
            cell.colSpan = 6;
            cell.textContent = 'Nenhum canal disponível.';
            emptyRow.appendChild(cell);
            tableBody.appendChild(emptyRow);
        } else {
            rows.forEach(({ channel, index }) => {
                const tr = document.createElement('tr');
                tr.dataset.uid = channel.uid;
                tr.dataset.index = String(index);

                const logoCell = document.createElement('td');
                const img = document.createElement('img');
                img.className = 'channel-logo';
                img.alt = channel.name ? `Logo de ${channel.name}` : 'Logo do canal';
                if (channel.logo) {
                    img.src = channel.logo;
                } else {
                    img.src = 'data:image/gif;base64,R0lGODlhAQABAAAAACw=';
                }
                logoCell.appendChild(img);
                tr.appendChild(logoCell);

                const nameCell = document.createElement('td');
                nameCell.appendChild(createInput('text', channel.name, 'name'));
                tr.appendChild(nameCell);

                const groupCell = document.createElement('td');
                groupCell.appendChild(createInput('text', channel.group, 'group'));
                tr.appendChild(groupCell);

                const urlCell = document.createElement('td');
                urlCell.appendChild(createInput('url', channel.url, 'url'));
                tr.appendChild(urlCell);

                const idCell = document.createElement('td');
                idCell.appendChild(createInput('text', channel.tvgId, 'tvgId'));
                tr.appendChild(idCell);

                const actionsCell = document.createElement('td');
                const actions = document.createElement('div');
                actions.className = 'channel-actions';
                actions.innerHTML = `
                    <button type="button" data-action="move-up" title="Mover para cima">⬆️</button>
                    <button type="button" data-action="move-down" title="Mover para baixo">⬇️</button>
                    <button type="button" data-action="duplicate" title="Duplicar canal">📄</button>
                    <button type="button" data-action="remove" class="danger" title="Remover canal">Excluir</button>
                `;
                actionsCell.appendChild(actions);
                tr.appendChild(actionsCell);

                tableBody.appendChild(tr);
            });
        }

        updateExportPreview();
    }

    function createInput(type, value, field) {
        const input = document.createElement('input');
        input.type = type;
        input.value = value || '';
        input.className = 'channel-input';
        input.dataset.field = field;
        return input;
    }

    function addChannel(channel = null) {
        const newChannel = channel ? { ...channel, uid: generateId() } : {
            uid: generateId(),
            name: 'Novo Canal',
            tvgName: 'Novo Canal',
            group: '',
            url: '',
            tvgId: '',
            logo: '',
            extras: []
        };
        state.channels.push(newChannel);
        render();
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

    function setFileLabel(text) {
        const labelSpan = fileInput.previousElementSibling;
        if (labelSpan) {
            labelSpan.textContent = text;
        }
    }

    function importFromFile(file) {
        if (!file) return;
        const reader = new FileReader();
        reader.onload = (event) => {
            try {
                const channels = parseM3U(String(event.target?.result || ''));
                if (!channels.length) {
                    alert('Não foi possível encontrar canais no arquivo informado.');
                    return;
                }
                setChannels(channels);
            } catch (error) {
                console.error(error);
                alert('Ocorreu um erro ao ler o arquivo M3U.');
            }
        };
        reader.readAsText(file);
    }

    importFileButton.addEventListener('click', () => {
        if (!fileInput.files?.length) {
            fileInput.click();
            return;
        }
        importFromFile(fileInput.files[0]);
    });

    fileInput.addEventListener('change', () => {
        if (fileInput.files?.length) {
            const name = fileInput.files[0].name;
            setFileLabel(name);
            importFromFile(fileInput.files[0]);
        } else {
            setFileLabel('Escolher arquivo M3U');
        }
    });

    importTextButton.addEventListener('click', () => {
        const value = document.getElementById('m3uText').value.trim();
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
            setChannels(channels);
        } catch (error) {
            console.error(error);
            alert('Ocorreu um erro ao interpretar o conteúdo informado.');
        }
    });

    clearButton.addEventListener('click', () => {
        if (!state.channels.length) return;
        if (confirm('Tem certeza de que deseja limpar todos os canais?')) {
            setChannels([]);
            document.getElementById('m3uText').value = '';
            if (fileInput.files?.length) {
                fileInput.value = '';
            }
            setFileLabel('Escolher arquivo M3U');
        }
    });

    addButton.addEventListener('click', () => addChannel());

    searchInput.addEventListener('input', (event) => {
        state.filter = event.target.value;
        render();
    });

    tableBody.addEventListener('input', (event) => {
        const target = event.target;
        if (!target || target.tagName?.toLowerCase() !== 'input') return;
        const input = target;
        const field = input.dataset.field;
        if (!field) return;
        const uid = input.closest('tr')?.dataset.uid;
        if (!uid) return;
        const channel = state.channels.find((item) => item.uid === uid);
        if (!channel) return;
        channel[field] = input.value;
        if (field === 'name') {
            channel.tvgName = input.value;
        }
        updateExportPreview();
    });

    tableBody.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) return;
        const uid = button.closest('tr')?.dataset.uid;
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

    downloadButton.addEventListener('click', () => {
        if (!state.channels.length) {
            alert('Adicione ou importe canais antes de exportar.');
            return;
        }
        const blob = new Blob([serializeM3U(state.channels)], { type: 'audio/x-mpegurl' });
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = 'playlist.m3u';
        document.body.appendChild(anchor);
        anchor.click();
        document.body.removeChild(anchor);
        URL.revokeObjectURL(url);
    });

    copyButton.addEventListener('click', async () => {
        if (!state.channels.length) {
            alert('Adicione ou importe canais antes de copiar.');
            return;
        }
        try {
            await navigator.clipboard.writeText(serializeM3U(state.channels));
            copyButton.textContent = 'Copiado!';
            setTimeout(() => {
                copyButton.textContent = 'Copiar para área de transferência';
            }, 2000);
        } catch (error) {
            console.error(error);
            alert('Não foi possível copiar para a área de transferência.');
        }
    });

    // Inicializa preview vazio
    updateExportPreview();
})();
