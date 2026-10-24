/*
 * Nova Upload – Anzahl und Größe der gewählten Dateien schon im Browser prüfen,
 * damit niemand erst nach dem Hochladen eine Fehlermeldung bekommt.
 * Der Server prüft zusätzlich (UploadRules).
 */
const MB = 1024 * 1024;

function check(input) {
    const files = Array.from(input.files || []);
    const maxFiles = Number(input.dataset.novaMaxFiles) || 5;
    const maxMb = Number(input.dataset.novaMaxMb) || 10;
    const maxTotalMb = Number(input.dataset.novaMaxTotalMb) || 25;
    const tooBig = files.find((file) => file.size > maxMb * MB);
    const total = files.reduce((sum, file) => sum + file.size, 0);

    let message = '';
    if (files.length > maxFiles) {
        message = input.dataset.novaErrorCount;
    } else if (tooBig) {
        message = input.dataset.novaErrorSize.replace('%name%', tooBig.name);
    } else if (total > maxTotalMb * MB) {
        message = input.dataset.novaErrorTotal;
    }

    input.setCustomValidity(message);
    input.classList.toggle('is-invalid', message !== '');
    if (message) {
        input.reportValidity();
    }
}

document.addEventListener('change', (event) => {
    const input = event.target;
    if (input instanceof HTMLInputElement && input.type === 'file' && input.dataset.novaUpload) {
        check(input);
    }
});
