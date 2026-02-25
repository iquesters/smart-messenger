<script>
    function escapeDiagnosticsHtml(text) {
        return String(text ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatDiagnosticsDuration(ms) {
        const value = Number(ms || 0);
        return `${value} ms`;
    }

    function prettyJson(obj) {
        try {
            return JSON.stringify(obj ?? {}, null, 2);
        } catch (error) {
            return '{}';
        }
    }

    function calculateTotalDurationMs(data) {
        const startedAt = Date.parse(data?.started_at ?? '');
        const endedAt = Date.parse(data?.ended_at ?? '');

        if (!Number.isNaN(startedAt) && !Number.isNaN(endedAt) && endedAt >= startedAt) {
            return endedAt - startedAt;
        }

        const steps = Array.isArray(data?.steps) ? data.steps : [];
        return steps.reduce((total, step) => total + Number(step?.duration_ms || 0), 0);
    }

    function updateDevModeHeaderStats(collapseEl, data) {
        const devItem = collapseEl.closest('.accordion-item');
        if (!devItem) return;

        const totalDuration = calculateTotalDurationMs(data);
        const stepCount = (Array.isArray(data?.steps) ? data.steps.length : 0) + 2;

        const durationEl = devItem.querySelector('[data-dev-total-duration]');
        const stepsEl = devItem.querySelector('[data-dev-total-steps]');
        const statsWrap = devItem.querySelector('[data-dev-stats]');

        if (durationEl) {
            durationEl.textContent = `Duration: ${formatDiagnosticsDuration(totalDuration)}`;
        }

        if (stepsEl) {
            stepsEl.textContent = `Steps: ${stepCount}`;
        }

        if (statsWrap) {
            statsWrap.classList.remove('d-none');
            statsWrap.classList.add('d-inline-flex');
        }
    }

    function createNestedAccordionItem(parentId, levelClass, idSuffix, headerHtml, bodyHtml) {
        const itemId = `${parentId}-${idSuffix}`;
        return `
            <div class="accordion-item border-0 rounded-2 w-100 overflow-hidden ${levelClass}">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed bg-transparent px-2 py-1 small"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#${itemId}"
                            style="--bs-accordion-btn-icon-width: .65rem;">
                        ${headerHtml}
                    </button>
                </h2>
                <div id="${itemId}" class="accordion-collapse collapse w-100">
                    <div class="accordion-body p-2 w-100 overflow-hidden">
                        ${bodyHtml}
                    </div>
                </div>
            </div>
        `;
    }

    function renderProcessingRootAccordion(collapseEl, data) {
        const container = collapseEl.querySelector('.diagnostics-processing-steps');
        if (!container) return;

        const rootId = collapseEl.id || `diag-${Date.now()}`;
        const diagnosticsMeta = {
            integration_id: data?.integration_id ?? null,
            message_id: data?.message_id ?? null,
            session_id: data?.session_id ?? null,
            channel: data?.channel ?? null,
            started_at: data?.started_at ?? null,
            ended_at: data?.ended_at ?? null,
            status: data?.status ?? null,
            summary: data?.summary ?? {},
        };

        let html = `
            <div class="mb-2">
                <div class="diag-json-scroll bg-white rounded small w-100 overflow-auto">
                    <pre class="mb-0 p-2 small">${escapeDiagnosticsHtml(prettyJson(diagnosticsMeta))}</pre>
                </div>
            </div>
            <div class="accordion accordion-flush w-100 diag-acc-l2 overflow-hidden">
        `;

        const steps = Array.isArray(data?.steps) ? data.steps : [];
        steps.forEach((step, index) => {
            const stepName = escapeDiagnosticsHtml(step?.name || `step-${index + 1}`);
            const stepStatus = escapeDiagnosticsHtml(step?.status || 'unknown');
            const stepDuration = formatDiagnosticsDuration(step?.duration_ms || 0);

            html += createNestedAccordionItem(
                rootId,
                'diag-acc-l3',
                `step-${index}`,
                `<small class="small fw-semibold">${stepName}</small>
                 <small class="ms-2 badge text-bg-${stepStatus} small">${stepStatus}</small>
                 <small class="ms-2 small text-muted">${stepDuration}</small>`,
                `<div class="diag-json-scroll bg-white rounded small w-100 overflow-auto"><pre class="mb-0 p-2 small">${escapeDiagnosticsHtml(prettyJson(step))}</pre></div>`
            );
        });

        html += '</div>';
        container.innerHTML = html;
        container.dataset.loaded = '1';
        enforceDevModeBounds(collapseEl);
    }

    function enforceDevModeBounds(collapseEl) {
        const boxNodes = collapseEl.querySelectorAll('.accordion, .accordion-item, .accordion-collapse, .accordion-body');
        boxNodes.forEach(node => {
            node.classList.add('w-100');
            node.style.maxWidth = '100%';
            node.style.minWidth = '0';
        });

        const scrollNodes = collapseEl.querySelectorAll('.diag-json-scroll');
        scrollNodes.forEach(node => {
            node.classList.add('w-100', 'overflow-auto');
            node.style.maxWidth = '100%';
        });
    }

    function toggleAllChildAccordions(collapseEl, action) {
        const childCollapses = collapseEl.querySelectorAll('.accordion-collapse');
        childCollapses.forEach(element => {
            const instance = bootstrap.Collapse.getOrCreateInstance(element, { toggle: false });
            if (action === 'open') {
                // Listen for when each child finishes opening, then re-enforce bounds
                element.addEventListener('shown.bs.collapse', function onShown() {
                    enforceDevModeBounds(collapseEl);
                    element.removeEventListener('shown.bs.collapse', onShown);
                });
                instance.show();
            } else {
                instance.hide();
            }
        });
    }

    function bindDevModeToolbox(collapseEl) {
        const body = collapseEl.querySelector('.accordion-body');
        if (!body || body.dataset.toolboxBound === '1') return;

        const openBtn = body.querySelector('.js-dev-open-all');
        const closeBtn = body.querySelector('.js-dev-close-all');

        if (openBtn) {
            openBtn.addEventListener('click', async function () {
                await loadDiagnosticsForCollapse(collapseEl);
                toggleAllChildAccordions(collapseEl, 'open');
                // Also enforce immediately and after a short delay as a safety net
                enforceDevModeBounds(collapseEl);
                setTimeout(() => enforceDevModeBounds(collapseEl), 400);
                openBtn.classList.remove('btn-outline-secondary');
                openBtn.classList.add('btn-secondary');
                if (closeBtn) {
                    closeBtn.classList.remove('btn-secondary');
                    closeBtn.classList.add('btn-outline-secondary');
                }
            });
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                toggleAllChildAccordions(collapseEl, 'close');
                closeBtn.classList.remove('btn-outline-secondary');
                closeBtn.classList.add('btn-secondary');
                if (openBtn) {
                    openBtn.classList.remove('btn-secondary');
                    openBtn.classList.add('btn-outline-secondary');
                }
            });
        }

        body.dataset.toolboxBound = '1';
    }

    async function loadDiagnosticsForCollapse(collapseEl) {
        const container = collapseEl.querySelector('.diagnostics-processing-steps');
        if (!container) return;
        if (container.dataset.loaded === '1') return;

        const integrationId = collapseEl.dataset.integrationId || '';
        const messageId = collapseEl.dataset.messageId || '';

        if (!integrationId || !messageId) {
            container.innerHTML = '<span class="text-muted fst-italic small">Missing integration/message id</span>';
            return;
        }

        container.innerHTML = '<span class="text-muted small">Loading processing steps...</span>';

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
            updateDevModeHeaderStats(collapseEl, data);
            renderProcessingRootAccordion(collapseEl, data);
        } catch (error) {
            console.error('Failed to load processing steps', error);
            container.innerHTML = '<span class="text-danger small">Failed to load processing steps</span>';
        }
    }

    function initializeDevModeDiagnostics() {
        document.querySelectorAll('.dev-mode-collapse').forEach(collapseEl => {
            bindDevModeToolbox(collapseEl);

            collapseEl.addEventListener('shown.bs.collapse', function () {
                loadDiagnosticsForCollapse(collapseEl);
                requestAnimationFrame(() => enforceDevModeBounds(collapseEl));
            });
        });
    }

    initializeDevModeDiagnostics();
    window.initializeDevModeDiagnostics = initializeDevModeDiagnostics;
</script>
