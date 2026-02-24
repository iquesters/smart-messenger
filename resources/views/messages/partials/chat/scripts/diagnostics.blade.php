<script>
    function escapeDiagnosticsHtml(text) {
        return String(text ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    async function loadDiagnosticsForCollapse(collapseEl) {
        const stepsContainer = collapseEl.querySelector('.diagnostics-steps');
        if (!stepsContainer) return;
        if (stepsContainer.dataset.loaded === '1') return;

        const integrationId = collapseEl.dataset.integrationId || '';
        const messageId = collapseEl.dataset.messageId || '';

        if (!integrationId || !messageId) {
            stepsContainer.innerHTML = '<span class="text-muted fst-italic small">Missing integration/message id</span>';
            return;
        }

        stepsContainer.innerHTML = '<span class="text-muted small">Loading processing steps...</span>';

        try {
            const url = `/api/smart-messenger/diagnostics/${encodeURIComponent(integrationId)}/message/${encodeURIComponent(messageId)}`;
            const response = await fetch(url, {
                method: 'GET',
                headers: {
                    'Accept': 'application/json'
                }
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const data = await response.json();
            const steps = Array.isArray(data.steps) ? data.steps : [];

            if (steps.length === 0) {
                stepsContainer.innerHTML = '<span class="text-muted fst-italic small">No processing steps</span>';
                stepsContainer.dataset.loaded = '1';
                return;
            }

            const parts = [];
            steps.forEach((step, index) => {
                const name = escapeDiagnosticsHtml(step.name || 'unknown');
                parts.push('<span class="badge rounded-pill text-bg-light border fw-semibold">' + name + '</span>');

                if (index < steps.length - 1) {
                    parts.push('<span class="text-primary small"><i class="fas fa-chevron-right"></i></span>');
                }
            });

            stepsContainer.innerHTML = '<div class="d-flex align-items-center justify-content-center flex-wrap gap-1">' + parts.join('') + '</div>';
            stepsContainer.dataset.loaded = '1';
        } catch (error) {
            console.error('Failed to load processings steps', error);
            stepsContainer.innerHTML = '<span class="text-danger small">Failed to load processings steps</span>';
        }
    }

    document.querySelectorAll('.dev-mode-collapse').forEach(collapseEl => {
        collapseEl.addEventListener('shown.bs.collapse', function () {
            loadDiagnosticsForCollapse(collapseEl);
        });
    });
</script>
