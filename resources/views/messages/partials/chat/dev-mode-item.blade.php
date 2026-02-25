<div class="w-75 mx-auto mb-3 dev-mode-section d-none overflow-hidden">
    <div class="accordion accordion-flush w-100 overflow-hidden" id="devModeAccordion-{{ $index }}">
        <div class="accordion-item rounded-2 w-100 overflow-hidden">
            <h5 class="accordion-header">
                <button class="accordion-button collapsed bg-dark-subtle px-2 py-1 d-flex align-items-center gap-2 small"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#devMode-{{ $index }}"
                        style="--bs-accordion-btn-icon-width: .72rem;">
                    <i class="fas fa-code text-muted small"></i>
                    <span class="fw-semibold small">Dev Mode</span>
                    <span class="small text-muted d-none align-items-center gap-2" data-dev-stats>
                        <small data-dev-total-duration></small>
                        <small data-dev-total-steps></small>
                    </span>
                </button>
            </h5>

            <div id="devMode-{{ $index }}"
                 class="accordion-collapse collapse dev-mode-collapse w-100"
                 data-integration-id="{{ $integrationUid }}"
                 data-message-id="{{ $msg->message_id ?? $msg->id }}">
                <div class="accordion-body p-2 bg-dark-subtle w-100 overflow-hidden">
                    <div class="d-flex flex-wrap gap-2 mb-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 js-dev-open-all" title="Open all">
                            <i class="fas fa-angles-down small"></i>
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 js-dev-close-all" title="Collapse all">
                            <i class="fas fa-angles-up small"></i>
                        </button>
                    </div>

                    <div class="accordion accordion-flush w-100 diag-acc-l1 overflow-hidden">
                        <div class="accordion-item border-0 rounded-2 mb-2 w-100 overflow-hidden">
                            <h5 class="accordion-header">
                                <button class="accordion-button collapsed bg-transparent shadow-none px-2 py-1 small"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#devApiRequest-{{ $index }}"
                                        style="--bs-accordion-btn-icon-width: .68rem;">
                                    <small class="small fw-semibold">API Request</small>
                                </button>
                            </h5>
                            <div id="devApiRequest-{{ $index }}" class="accordion-collapse collapse w-100">
                                <div class="accordion-body p-2 w-100 overflow-hidden">
                                    @if(!empty($msg->api_request))
                                        <div class="diag-json-scroll bg-white rounded small w-100 overflow-auto">
                                            <pre class="mb-0 p-2 small">{{ json_encode($msg->api_request, JSON_PRETTY_PRINT) }}</pre>
                                        </div>
                                    @else
                                        <small class="text-muted fst-italic small">No API request data</small>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item border-0 rounded-2 mb-2 w-100 overflow-hidden">
                            <h5 class="accordion-header">
                                <button class="accordion-button collapsed bg-transparent shadow-none px-2 py-1 small"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#devProcessing-{{ $index }}"
                                        style="--bs-accordion-btn-icon-width: .68rem;">
                                    <small class="small fw-semibold">Processing Steps</small>
                                </button>
                            </h5>
                            <div id="devProcessing-{{ $index }}" class="accordion-collapse collapse w-100">
                                <div class="accordion-body p-2 w-100 overflow-hidden">
                                    <div class="diagnostics-processing-steps w-100" data-loaded="0">
                                        <span class="text-muted fst-italic small">Expand Dev Mode to load steps...</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="accordion-item border-0 rounded-2 w-100 overflow-hidden">
                            <h5 class="accordion-header">
                                <button class="accordion-button collapsed bg-transparent shadow-none px-2 py-1 small"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#devApiResponse-{{ $index }}"
                                        style="--bs-accordion-btn-icon-width: .68rem;">
                                    <small class="small fw-semibold">API Response</small>
                                </button>
                            </h5>
                            <div id="devApiResponse-{{ $index }}" class="accordion-collapse collapse w-100">
                                <div class="accordion-body p-2 w-100 overflow-hidden">
                                    @if(!empty($msg->api_response))
                                        <div class="diag-json-scroll bg-white rounded small w-100 overflow-auto">
                                            <pre class="mb-0 p-2 small">{{ json_encode($msg->api_response, JSON_PRETTY_PRINT) }}</pre>
                                        </div>
                                    @else
                                        <small class="text-muted fst-italic small">No API response data</small>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
