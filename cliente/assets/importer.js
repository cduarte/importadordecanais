(function(window) {
    'use strict';

    const DEFAULT_POLL_INTERVAL_MS = 5000;

    const DEFAULT_STATE_TITLES = {
        queued: 'Job na fila',
        running: 'Processando itens',
        done: 'Importação concluída',
        failed: 'Falha na importação',
        error: 'Erro'
    };

    const DEFAULT_TOTALS_LABELS = {
        added: 'Itens adicionados',
        skipped: 'Itens ignorados',
        errors: 'Erros'
    };

    const DEFAULT_MESSAGES = {
        preparingTitle: 'Preparando importação',
        preparingMessage: 'Aguarde, estamos validando as credenciais...',
        jobCreated: jobId => `Job #${jobId} criado com sucesso. O processamento será iniciado em breve.`,
        queued: 'Job aguardando processamento...',
        running: 'Processando itens...',
        done: 'Importação finalizada.',
        failed: 'Ocorreu um erro durante o processamento.',
        statusUnknown: 'Status desconhecido retornado pelo servidor.',
        errorTitle: 'Erro na importação'
    };

    const RESPONSE_STATES = {
        queued: { headerClass: 'warning', icon: 'fa-clock' },
        running: { headerClass: 'warning', icon: 'fa-spinner fa-spin' },
        done: { headerClass: 'success', icon: 'fa-circle-check' },
        failed: { headerClass: '', icon: 'fa-triangle-exclamation' },
        error: { headerClass: '', icon: 'fa-triangle-exclamation' }
    };

    function formatBrazilianNumber(value) {
        if (typeof value !== 'number' || Number.isNaN(value)) {
            return value;
        }

        try {
            return value.toLocaleString('pt-BR');
        } catch (error) {
            return String(value);
        }
    }

    function createImportJobController(options) {
        if (!options || typeof options !== 'object') {
            throw new Error('createImportJobController requer um objeto de configuração.');
        }

        const form = options.form;
        const submitBtn = options.submitButton;
        if (!form || !submitBtn) {
            throw new Error('Formulário e botão de submit são obrigatórios.');
        }

        const elements = options.elements || {};
        const {
            responseBox,
            responseHeader,
            responseIcon,
            responseTitle,
            responseMessage,
            progressWrapper,
            progressBar,
            progressText
        } = elements;

        const requiredElements = {
            responseBox,
            responseHeader,
            responseIcon,
            responseTitle,
            responseMessage,
            progressWrapper,
            progressBar,
            progressText
        };

        Object.entries(requiredElements).forEach(([key, value]) => {
            if (!value) {
                throw new Error(`Elemento necessário não encontrado: ${key}`);
            }
        });

        const urls = options.urls || {};
        const actionUrl = urls.action;
        const statusUrl = urls.status;
        if (!actionUrl || !statusUrl) {
            throw new Error('URLs de ação e status são obrigatórias.');
        }

        const pollInterval = typeof options.pollIntervalMs === 'number' && options.pollIntervalMs > 0
            ? options.pollIntervalMs
            : DEFAULT_POLL_INTERVAL_MS;

        const stateTitles = Object.assign({}, DEFAULT_STATE_TITLES, options.stateTitles || {});
        const totalsLabels = Object.assign({}, DEFAULT_TOTALS_LABELS, options.totalsLabels || {});
        const messages = Object.assign({}, DEFAULT_MESSAGES, options.messages || {});

        const defaultJobCreated = DEFAULT_MESSAGES.jobCreated;
        if (typeof messages.jobCreated !== 'function') {
            const template = messages.jobCreated;
            if (typeof template === 'string') {
                messages.jobCreated = jobId => template.replace('{jobId}', jobId);
            } else {
                messages.jobCreated = defaultJobCreated;
            }
        }

        const submitBtnOriginal = submitBtn.innerHTML;
        let pollingHandle = null;
        let currentJobId = null;

        function showResponseBox() {
            responseBox.classList.remove('hidden');
        }

        function resetProgress() {
            progressWrapper.classList.add('hidden');
            progressBar.style.width = '0%';
            progressText.textContent = '0%';
        }

        function updateProgress(value) {
            if (typeof value !== 'number' || Number.isNaN(value)) {
                progressWrapper.classList.add('hidden');
                return;
            }

            const safeValue = Math.min(100, Math.max(0, Math.round(value)));
            progressWrapper.classList.remove('hidden');
            progressBar.style.width = `${safeValue}%`;
            progressText.textContent = `${safeValue}%`;
        }

        function updateMessage(message) {
            responseMessage.innerHTML = '';
            if (!message) {
                return;
            }

            String(message).split('\n').forEach(line => {
                const trimmed = line.trim();
                if (!trimmed) {
                    return;
                }
                const div = document.createElement('div');
                div.className = 'message-line';
                div.textContent = trimmed;
                responseMessage.appendChild(div);
            });
        }

        function setHeader(stateKey, customTitle = null) {
            const state = RESPONSE_STATES[stateKey] || RESPONSE_STATES.error;
            responseHeader.classList.remove('success', 'warning');
            if (state.headerClass) {
                responseHeader.classList.add(state.headerClass);
            }
            responseIcon.className = `fas ${state.icon}`;
            const fallbackTitle = stateTitles[stateKey] || stateTitles.error || 'Resultado';
            responseTitle.textContent = customTitle || fallbackTitle;
            showResponseBox();
        }

        function setLoadingState() {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<div class="loading"></div> A processar...';
        }

        function restoreButton() {
            submitBtn.disabled = false;
            submitBtn.innerHTML = submitBtnOriginal;
        }

        function handleError(message) {
            stopPolling();
            setHeader('error', messages.errorTitle);
            updateMessage(message);
            resetProgress();
            restoreButton();
            showResponseBox();
        }

        function formatJobCreated(jobId) {
            try {
                return messages.jobCreated(jobId);
            } catch (error) {
                return defaultJobCreated(jobId);
            }
        }

        async function submitForm(event) {
            event.preventDefault();

            if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
                return;
            }

            stopPolling();
            currentJobId = null;
            setHeader('queued', messages.preparingTitle);
            updateMessage(messages.preparingMessage);
            resetProgress();
            showResponseBox();
            setLoadingState();

            const formData = new FormData(form);

            try {
                const response = await fetch(actionUrl, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json().catch(() => null);

                if (!response.ok || !data) {
                    const errorMsg = data && data.error ? data.error : `Falha na requisição (${response.status})`;
                    handleError(errorMsg);
                    return;
                }

                if (data.error) {
                    handleError(data.error);
                    return;
                }

                if (!data.job_id) {
                    handleError('Resposta inesperada do servidor.');
                    return;
                }

                currentJobId = data.job_id;
                setHeader('queued');
                updateMessage(formatJobCreated(currentJobId));
                updateProgress(0);
                startPolling(currentJobId);
            } catch (error) {
                handleError(`Erro de rede ao contactar o servidor: ${error.message}`);
            }
        }

        function buildStatusEndpoint(jobId) {
            if (!statusUrl) {
                throw new Error('URL de status não configurada.');
            }

            const trimmedStatusUrl = statusUrl.trim();
            const hashIndex = trimmedStatusUrl.indexOf('#');
            const hash = hashIndex >= 0 ? trimmedStatusUrl.slice(hashIndex) : '';
            const urlWithoutHash = hashIndex >= 0 ? trimmedStatusUrl.slice(0, hashIndex) : trimmedStatusUrl;

            try {
                const parsed = new URL(urlWithoutHash, window.location.href);
                parsed.searchParams.set('job_id', jobId);
                return `${parsed.toString()}${hash}`;
            } catch (error) {
                const questionMarkIndex = urlWithoutHash.indexOf('?');
                const basePath = questionMarkIndex >= 0 ? urlWithoutHash.slice(0, questionMarkIndex) : urlWithoutHash;
                const queryString = questionMarkIndex >= 0 ? urlWithoutHash.slice(questionMarkIndex + 1) : '';

                const params = new URLSearchParams(queryString);
                params.set('job_id', jobId);

                const finalQuery = params.toString();
                const separator = finalQuery ? '?' : '';

                return `${basePath}${separator}${finalQuery}${hash}`;
            }
        }

        async function fetchStatus(jobId) {
            try {
                const statusEndpoint = buildStatusEndpoint(jobId);

                const response = await fetch(statusEndpoint, {
                    cache: 'no-store'
                });

                const data = await response.json().catch(() => null);

                if (!response.ok || !data) {
                    const errorMsg = data && data.error ? data.error : `Falha ao obter status (${response.status})`;
                    handleError(errorMsg);
                    return;
                }

                if (data.error) {
                    handleError(data.error);
                    return;
                }

                renderStatus(data);
            } catch (error) {
                updateMessage(`Aviso: não foi possível atualizar o status no momento (${error.message}).`);
            }
        }

        function renderStatus(data) {
            const status = data.status;
            const message = data.message ?? '';
            const progress = typeof data.progress === 'number' ? data.progress : null;
            const totals = data.totals || {};

            updateProgress(progress);

            const totalsLines = [];
            Object.entries(totalsLabels).forEach(([key, label]) => {
                if (typeof totals[key] === 'number' && label) {
                    if (key === 'errors' && totals[key] === 0) {
                        return;
                    }
                    const messageAlreadyHasLabel = typeof message === 'string' && message.includes(label);
                    if (!messageAlreadyHasLabel) {
                        totalsLines.push(`${label}: ${formatBrazilianNumber(totals[key])}`);
                    }
                }
            });

            const combinedMessage = (() => {
                const extra = totalsLines.length ? totalsLines.join('\n') : '';
                if (message && extra) {
                    return `${message}\n${extra}`;
                }
                return message || extra;
            })();

            if (status === 'queued') {
                setHeader('queued');
                updateMessage(combinedMessage || messages.queued);
                return;
            }

            if (status === 'running') {
                setHeader('running');
                updateMessage(combinedMessage || messages.running);
                return;
            }

            if (status === 'done') {
                setHeader('done');
                updateMessage(combinedMessage || messages.done);
                updateProgress(100);
                stopPolling();
                restoreButton();
                return;
            }

            if (status === 'failed') {
                setHeader('failed');
                updateMessage(combinedMessage || messages.failed);
                updateProgress(100);
                stopPolling();
                restoreButton();
                return;
            }

            setHeader('error');
            updateMessage(combinedMessage || messages.statusUnknown);
            stopPolling();
            restoreButton();
        }

        function startPolling(jobId) {
            stopPolling();
            showResponseBox();
            fetchStatus(jobId);
            pollingHandle = setInterval(() => fetchStatus(jobId), pollInterval);
        }

        function stopPolling() {
            if (pollingHandle) {
                clearInterval(pollingHandle);
                pollingHandle = null;
            }
        }

        form.addEventListener('submit', submitForm);

        return {
            startPolling,
            stopPolling,
            resetProgress,
            restoreButton
        };
    }

    window.createImportJobController = createImportJobController;
})(window);
