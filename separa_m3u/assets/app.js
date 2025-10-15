document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('form');
    const fileInput = document.querySelector('#m3u_file');
    const dropzone = document.querySelector('.dropzone');
    const progress = document.querySelector('.progress');
    const submitButton = document.querySelector('button[type="submit"]');

    if (!form || !dropzone) {
        return;
    }

    const toggleProgress = (show) => {
        if (!progress) return;
        progress.style.display = show ? 'block' : 'none';
    };

    const handleFiles = (files) => {
        if (!files || !files.length) return;
        fileInput.files = files;
        const event = new Event('change', { bubbles: true });
        fileInput.dispatchEvent(event);
    };

    dropzone.addEventListener('click', () => fileInput.click());

    dropzone.addEventListener('dragover', (event) => {
        event.preventDefault();
        dropzone.classList.add('dragover');
    });

    ['dragleave', 'dragend', 'drop'].forEach((evtName) => {
        dropzone.addEventListener(evtName, () => dropzone.classList.remove('dragover'));
    });

    dropzone.addEventListener('drop', (event) => {
        event.preventDefault();
        if (event.dataTransfer?.files?.length) {
            handleFiles(event.dataTransfer.files);
        }
    });

    fileInput.addEventListener('change', () => {
        if (fileInput.files?.length) {
            dropzone.querySelector('strong').textContent = fileInput.files[0].name;
            dropzone.querySelector('span').textContent = 'Arquivo pronto para upload';
        } else {
            dropzone.querySelector('strong').textContent = 'Arraste e solte a sua lista M3U';
            dropzone.querySelector('span').textContent = 'ou clique para selecionar um arquivo';
        }
    });

    form.addEventListener('submit', () => {
        toggleProgress(true);
        submitButton.disabled = true;
    });
});
