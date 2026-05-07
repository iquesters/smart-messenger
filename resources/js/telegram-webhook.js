/**
 * Telegram Webhook Setup
 * Handles registering the webhook with Telegram API via JS
 */

function setupTelegramWebhook(botToken, webhookUrl, secretToken) {

    const btn = document.getElementById('setupWebhookBtn');
    const statusDiv = document.getElementById('webhookStatus');

    // Show loading state
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Setting up...';
    statusDiv.innerHTML = '';

    const telegramApiUrl = `https://api.telegram.org/bot${botToken}/setWebhook`;

    const params = new URLSearchParams({
        url: webhookUrl,
        secret_token: secretToken,
    });

    fetch(`${telegramApiUrl}?${params.toString()}`)
        .then(response => response.json())
        .then(data => {
            if (data.ok && data.result === true) {
                statusDiv.innerHTML = `
                    <div class="alert alert-success py-2 mt-2">
                        <i class="fas fa-check-circle me-2"></i>
                        Webhook registered successfully with Telegram! ✅
                    </div>`;

                btn.innerHTML = '<i class="fab fa-telegram me-1"></i> Setup Webhook';
                btn.disabled = false;
            } else {
                statusDiv.innerHTML = `
                    <div class="alert alert-danger py-2 mt-2">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        Failed: ${data.description ?? 'Unknown error'}
                    </div>`;

                btn.innerHTML = '<i class="fab fa-telegram me-1"></i> Setup Webhook';
                btn.disabled = false;
            }
        })
        .catch(error => {
            statusDiv.innerHTML = `
                <div class="alert alert-danger py-2 mt-2">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    Error: ${error.message}. Make sure you are using HTTPS.
                </div>`;

            btn.innerHTML = '<i class="fab fa-telegram me-1"></i> Setup Webhook';
            btn.disabled = false;
        });
}