(() => {
    const form = document.getElementById('upload-form');
    if (!form) return;

    const dropzone = document.getElementById('dropzone');
    const input = document.getElementById('file-input');
    const chosen = document.getElementById('file-chosen');
    const fileName = document.getElementById('file-name');
    const clearButton = document.getElementById('file-clear');
    const submitButton = document.getElementById('upload-submit');
    const progress = document.getElementById('upload-progress');
    const progressBar = progress?.querySelector('.progress-bar');
    const error = document.getElementById('upload-error');

    const maxMb = Number(form.dataset.maxMb || 50);
    const allowed = new Set((form.dataset.allowed || '').split(',').map(v => v.trim().toLowerCase()).filter(Boolean));

    function showError(message) {
        if (!error) return;
        error.textContent = message;
        error.classList.remove('d-none');
    }

    function clearError() {
        if (!error) return;
        error.textContent = '';
        error.classList.add('d-none');
    }

    function selectFile(file) {
        clearError();
        if (!file) return;

        const lowerName = file.name.toLowerCase();
        const extension = lowerName.includes('.') ? lowerName.split('.').pop() : '';
        if (extension !== 'zip' && !allowed.has(extension)) {
            showError(`Unsupported file type .${extension || 'unknown'}.`);
            input.value = '';
            chosen.classList.add('d-none');
            return;
        }

        if (file.size > maxMb * 1024 * 1024) {
            showError(`File is larger than ${maxMb} MB.`);
            input.value = '';
            chosen.classList.add('d-none');
            return;
        }

        const transfer = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;
        fileName.textContent = file.name;
        chosen.classList.remove('d-none');
        chosen.classList.add('d-flex');
    }

    dropzone.addEventListener('click', () => input.click());
    dropzone.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            input.click();
        }
    });

    input.addEventListener('change', () => selectFile(input.files[0]));

    ['dragenter', 'dragover'].forEach(type => dropzone.addEventListener(type, event => {
        event.preventDefault();
        dropzone.classList.add('np-dropzone-active');
    }));

    ['dragleave', 'drop'].forEach(type => dropzone.addEventListener(type, event => {
        event.preventDefault();
        dropzone.classList.remove('np-dropzone-active');
    }));

    dropzone.addEventListener('drop', event => selectFile(event.dataTransfer.files[0]));

    clearButton.addEventListener('click', () => {
        input.value = '';
        chosen.classList.add('d-none');
        chosen.classList.remove('d-flex');
        clearError();
    });

    form.addEventListener('submit', event => {
        event.preventDefault();
        clearError();
        if (!input.files.length) {
            showError('Choose a file first.');
            return;
        }

        submitButton.disabled = true;
        submitButton.textContent = 'Uploading...';
        progress.classList.remove('d-none');

        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.action, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.addEventListener('progress', event => {
            if (!event.lengthComputable) return;
            const percent = Math.round((event.loaded / event.total) * 100);
            progressBar.style.width = `${percent}%`;
            progressBar.textContent = `${percent}%`;
        });

        xhr.addEventListener('load', () => {
            if (xhr.status >= 200 && xhr.status < 400) {
                window.location.reload();
                return;
            }

            let message = 'Upload failed.';
            try {
                const response = JSON.parse(xhr.responseText);
                message = response.message || Object.values(response.errors || {}).flat()[0] || message;
            } catch (_) {}
            showError(message);
            submitButton.disabled = false;
            submitButton.textContent = 'Upload';
            progress.classList.add('d-none');
        });

        xhr.addEventListener('error', () => {
            showError('Network error while uploading.');
            submitButton.disabled = false;
            submitButton.textContent = 'Upload';
            progress.classList.add('d-none');
        });

        xhr.send(new FormData(form));
    });
})();
