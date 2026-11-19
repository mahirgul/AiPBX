(() => {
    const form = document.getElementById('ai-gateway-chat');
    if (!form) return;
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = form.querySelector('button');
        const result = document.getElementById('ai-gateway-result');
        button.disabled = true;
        result.textContent = '…';
        try {
            const response = await fetch('/api/ai_gateway.php', {method: 'POST', body: new FormData(form)});
            const data = await response.json();
            result.textContent = data.success ? data.text : data.error;
        } catch (_) {
            result.textContent = form.dataset.error;
        } finally {
            button.disabled = false;
        }
    });
})();
