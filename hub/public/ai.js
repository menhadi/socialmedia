document.querySelectorAll('.ai-request-form').forEach((form) => {
    form.addEventListener('submit', () => {
        const button = form.querySelector('[data-generate-button]');
        button.disabled = true;
        button.textContent = 'Generating…';
        form.querySelector('.generation-progress').hidden = false;
    });
});
window.addEventListener('pageshow', () => {
    document.querySelectorAll('[data-generate-button]').forEach((button) => {
        button.disabled = button.dataset.ready !== 'true';
        button.textContent = 'Generate with AI →';
    });
    document.querySelectorAll('.generation-progress').forEach((notice) => notice.hidden = true);
});
