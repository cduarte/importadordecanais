document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('form[data-default-mode]');
    if (!form) {
        return;
    }

    const fileInput = form.querySelector('#m3u_file');
    const fileDropzone = form.querySelector('[data-role="file"]');
    const progress = form.querySelector('.progress');
    const submitButton = form.querySelector('button[type="submit"]');
    const urlInput = form.querySelector('#m3u_url');
    const modeButtons = form.querySelectorAll('.mode-button');
    const panes = form.querySelectorAll('.mode-pane');

    const dropzoneTitle = fileDropzone?.querySelector('strong') ?? null;
    const dropzoneSubtitle = fileDropzone?.querySelector('span') ?? null;
    const defaultTitle = dropzoneTitle?.textContent ?? '';
    const defaultSubtitle = dropzoneSubtitle?.textContent ?? '';
    let currentMode = form.dataset.defaultMode || 'file';

    const toggleProgress = (show) => {
        if (!progress) {
            return;
        }
        progress.style.display = show ? 'block' : 'none';
    };

    const triggerAutomaticSubmission = () => {
        if (!form || (submitButton && submitButton.disabled)) {
            return;
        }

        if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
            return;
        }

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit(submitButton ?? undefined);
        } else {
            form.submit();
        }
    };

    const updateDropzoneCopy = () => {
        if (!dropzoneTitle || !dropzoneSubtitle) {
            return;
        }

        if (fileInput?.files?.length) {
            const [file] = fileInput.files;
            dropzoneTitle.textContent = file.name;
            dropzoneSubtitle.textContent = 'Arquivo pronto para upload';
        } else {
            dropzoneTitle.textContent = defaultTitle;
            dropzoneSubtitle.textContent = defaultSubtitle;
        }
    };

    const handleFiles = (files) => {
        if (!fileInput || !files || !files.length) {
            return;
        }

        if (typeof DataTransfer !== 'undefined') {
            const dataTransfer = new DataTransfer();
            Array.from(files).forEach((file) => dataTransfer.items.add(file));
            fileInput.files = dataTransfer.files;
        } else {
            fileInput.files = files;
        }

        fileInput.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const setMode = (mode, { preserveValues = false } = {}) => {
        if (mode !== 'file' && mode !== 'url') {
            return;
        }

        currentMode = mode;

        modeButtons.forEach((button) => {
            button.classList.toggle('active', button.dataset.mode === mode);
        });

        panes.forEach((pane) => {
            pane.classList.toggle('active', pane.dataset.mode === mode);
        });

        if (urlInput) {
            if (mode === 'url') {
                urlInput.setAttribute('required', 'required');
            } else {
                urlInput.removeAttribute('required');
                if (!preserveValues) {
                    urlInput.value = '';
                }
            }
        }

        if (fileDropzone) {
            fileDropzone.classList.remove('dragover');
        }

        if (fileInput && mode === 'url' && !preserveValues) {
            fileInput.value = '';
            fileInput.dispatchEvent(new Event('change', { bubbles: true }));
        }

        updateDropzoneCopy();
    };

    if (fileDropzone && fileInput) {
        fileDropzone.addEventListener('click', () => fileInput.click());

        fileDropzone.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                fileInput.click();
            }
        });

        fileDropzone.addEventListener('dragover', (event) => {
            if (currentMode !== 'file') {
                return;
            }
            event.preventDefault();
            fileDropzone.classList.add('dragover');
        });

        ['dragleave', 'dragend', 'drop'].forEach((eventName) => {
            fileDropzone.addEventListener(eventName, () => fileDropzone.classList.remove('dragover'));
        });

        fileDropzone.addEventListener('drop', (event) => {
            if (currentMode !== 'file') {
                return;
            }
            event.preventDefault();
            if (event.dataTransfer?.files?.length) {
                handleFiles(event.dataTransfer.files);
            }
        });
    }

    if (fileInput) {
        fileInput.addEventListener('change', () => {
            updateDropzoneCopy();

            if (currentMode === 'file' && fileInput.files?.length) {
                triggerAutomaticSubmission();
            }
        });
    }

    modeButtons.forEach((button) => {
        button.addEventListener('click', () => {
            const mode = button.dataset.mode;
            if (!mode || mode === currentMode) {
                return;
            }
            setMode(mode);
        });
    });

    form.addEventListener('submit', () => {
        toggleProgress(true);
        if (submitButton) {
            submitButton.disabled = true;
        }
    });

    setMode(currentMode, { preserveValues: true });
});
