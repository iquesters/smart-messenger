<script>
    // Keep client-side diagnostics logging centralized so UI debug flows stay consistent.
    function logDiagnosticsEvent(level, message, context = {}) {
        const method = typeof console[level] === 'function' ? level : 'log';
        console[method]('[SmartMessenger][Diagnostics]', {
            message,
            ...context,
        });
    }

    // Escape dynamic strings before rendering them into diagnostics HTML blocks.
    function escapeDiagnosticsHtml(text) {
        return String(text ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // Normalize duration labels so all diagnostics panels use one display format.
    function formatDiagnosticsDuration(ms) {
        const value = Number(ms || 0);
        return `${value} ms`;
    }

    // Safely stringify diagnostic objects without breaking the UI on malformed payloads.
    function prettyJson(obj) {
        try {
            return JSON.stringify(obj ?? {}, null, 2);
        } catch (error) {
            logDiagnosticsEvent('warn', 'Failed to stringify diagnostics payload', {
                error: error?.message || String(error),
            });
            return '{}';
        }
    }

    // Sum all nested processing-step durations so parent summaries reflect the full tree.
    function calculateNestedStepsDurationMs(steps) {
        if (!Array.isArray(steps) || steps.length === 0) {
            return 0;
        }

        return steps.reduce((total, step) => {
            const childSteps = Array.isArray(step?.steps)
                ? step.steps
                : (Array.isArray(step?.children) ? step.children : []);

            const ownDuration = Number(step?.duration_ms || 0);
            const childDuration = childSteps.length > 0
                ? calculateNestedStepsDurationMs(childSteps)
                : 0;

            return total + ownDuration + childDuration;
        }, 0);
    }

    // Prefer summed step duration for UI stats; fall back to wall-clock only if steps are missing.
    function calculateTotalDurationMs(data) {
        const steps = Array.isArray(data?.steps) ? data.steps : [];
        const stepsDuration = calculateNestedStepsDurationMs(steps);

        if (stepsDuration > 0) {
            return stepsDuration;
        }

        const startedAt = Date.parse(data?.started_at ?? '');
        const endedAt = Date.parse(data?.ended_at ?? '');

        if (!Number.isNaN(startedAt) && !Number.isNaN(endedAt) && endedAt >= startedAt) {
            return endedAt - startedAt;
        }

        return 0;
    }

    // Update the compact Dev Mode header summary after diagnostics data is loaded.
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

    // Reuse one toolbox template so Dev Mode and Processing Steps share the same controls.
    function createDiagnosticsToolboxHtml(scope = 'all') {
        return `
            <div class="d-flex flex-wrap gap-2 mb-2" data-dev-toolbox data-toolbox-scope="${escapeDiagnosticsHtml(scope)}">
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" data-toolbox-action="open" title="Open all">
                    <i class="fas fa-angles-down small"></i>
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" data-toolbox-action="close" title="Collapse all">
                    <i class="fas fa-angles-up small"></i>
                </button>
            </div>
        `;
    }

    // Build reusable nested accordion markup for each diagnostics section and step node.
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

    // Render the root diagnostics payload and all top-level processing steps into the Dev Mode UI.
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
            ${createDiagnosticsToolboxHtml('processing')}
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
        bindDiagnosticsToolboxes(collapseEl);
        enforceDevModeBounds(collapseEl);
    }

    // Enforce width constraints after dynamic accordion rendering to prevent horizontal overflow.
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

    // Keep toolbox button state updates shared because Dev Mode and Processing Steps use the same UI.
    function updateDiagnosticsToolboxState(toolboxEl, activeAction) {
        const buttons = toolboxEl.querySelectorAll('[data-toolbox-action]');
        buttons.forEach(button => {
            const isActive = button.dataset.toolboxAction === activeAction;
            button.classList.toggle('btn-secondary', isActive);
            button.classList.toggle('btn-outline-secondary', !isActive);
        });
    }

    // Reset a toolbox back to its neutral visual state when another scope changes.
    function resetDiagnosticsToolboxState(toolboxEl) {
        const buttons = toolboxEl.querySelectorAll('[data-toolbox-action]');
        buttons.forEach(button => {
            button.classList.remove('btn-secondary');
            button.classList.add('btn-outline-secondary');
        });
    }

    // Keep related toolbox states in sync so button colors match the scope that was actually changed.
    function syncDiagnosticsToolboxStates(collapseEl, activeToolboxEl, action, scope) {
        const allToolboxes = collapseEl.querySelectorAll('[data-dev-toolbox]');

        allToolboxes.forEach(toolboxEl => {
            if (scope === 'all') {
                updateDiagnosticsToolboxState(toolboxEl, action);
                return;
            }

            if (toolboxEl === activeToolboxEl) {
                updateDiagnosticsToolboxState(toolboxEl, action);
                return;
            }

            resetDiagnosticsToolboxState(toolboxEl);
        });
    }

    // Resolve the target accordion set for a toolbox so the same handlers can control different sections.
    function resolveToolboxTargetCollapses(collapseEl, scope) {
        if (scope === 'processing') {
            const processingContainer = collapseEl.querySelector('.diagnostics-processing-steps');
            if (!processingContainer) {
                return [];
            }

            return processingContainer.querySelectorAll('.diag-acc-l2 > .accordion-item > .accordion-collapse');
        }

        return collapseEl.querySelectorAll('.accordion-collapse');
    }

    // Execute one toolbox action against the selected accordion scope and preserve layout constraints.
    function runDiagnosticsToolboxAction(collapseEl, toolboxEl, action) {
        const scope = toolboxEl.dataset.toolboxScope || 'all';
        const targetCollapses = resolveToolboxTargetCollapses(collapseEl, scope);

        if (targetCollapses.length === 0) {
            logDiagnosticsEvent('warn', 'Diagnostics toolbox found no collapses for requested scope', {
                scope,
                action,
                collapseId: collapseEl.id || null,
            });
            return;
        }

        targetCollapses.forEach(element => {
            const instance = bootstrap.Collapse.getOrCreateInstance(element, { toggle: false });

            if (action === 'open') {
                element.addEventListener('shown.bs.collapse', function onShown() {
                    enforceDevModeBounds(collapseEl);
                    element.removeEventListener('shown.bs.collapse', onShown);
                });
                instance.show();
            } else {
                instance.hide();
            }
        });

        enforceDevModeBounds(collapseEl);
        setTimeout(() => enforceDevModeBounds(collapseEl), 400);
        syncDiagnosticsToolboxStates(collapseEl, toolboxEl, action, scope);
        logDiagnosticsEvent('info', 'Diagnostics toolbox action applied', {
            scope,
            action,
            collapseId: collapseEl.id || null,
            targetCount: targetCollapses.length,
        });
    }

    // Bind every diagnostics toolbox once so identical UI controls can share one behavior contract.
    function bindDiagnosticsToolboxes(collapseEl) {
        collapseEl.querySelectorAll('[data-dev-toolbox]').forEach(toolboxEl => {
            if (toolboxEl.dataset.toolboxBound === '1') {
                return;
            }

            toolboxEl.querySelectorAll('[data-toolbox-action]').forEach(button => {
                button.addEventListener('click', async function () {
                    await loadDiagnosticsForCollapse(collapseEl);
                    runDiagnosticsToolboxAction(collapseEl, toolboxEl, button.dataset.toolboxAction || 'close');
                });
            });

            toolboxEl.dataset.toolboxBound = '1';
        });
    }

    // Load diagnostics lazily when Dev Mode expands so we avoid unnecessary API calls on initial render.
    async function loadDiagnosticsForCollapse(collapseEl) {
        const container = collapseEl.querySelector('.diagnostics-processing-steps');
        if (!container) return;
        if (container.dataset.loaded === '1') return;

        const integrationId = collapseEl.dataset.integrationId || '';
        const messageId = collapseEl.dataset.messageId || '';

        if (!integrationId || !messageId) {
            logDiagnosticsEvent('warn', 'Diagnostics load skipped because identifiers are missing', {
                integrationId,
                messageId,
            });
            container.innerHTML = '<span class="text-muted fst-italic small">Missing integration/message id</span>';
            return;
        }

        logDiagnosticsEvent('info', 'Loading diagnostics for message', {
            integrationId,
            messageId,
        });
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
            logDiagnosticsEvent('info', 'Diagnostics loaded successfully', {
                integrationId,
                messageId,
                stepCount: Array.isArray(data?.steps) ? data.steps.length : 0,
            });
            updateDevModeHeaderStats(collapseEl, data);
            renderProcessingRootAccordion(collapseEl, data);
        } catch (error) {
            logDiagnosticsEvent('error', 'Failed to load processing steps', {
                integrationId,
                messageId,
                error: error?.message || String(error),
            });
            container.innerHTML = '<span class="text-danger small">Failed to load processing steps</span>';
        }
    }

    // Initialize all Dev Mode accordions on page load and on future partial re-renders.
    function initializeDevModeDiagnostics() {
        document.querySelectorAll('.dev-mode-collapse').forEach(collapseEl => {
            bindDiagnosticsToolboxes(collapseEl);

            collapseEl.addEventListener('shown.bs.collapse', function () {
                loadDiagnosticsForCollapse(collapseEl);
                requestAnimationFrame(() => enforceDevModeBounds(collapseEl));
            });
        });
    }

    initializeDevModeDiagnostics();
    window.initializeDevModeDiagnostics = initializeDevModeDiagnostics;
</script>
