<div class="row" style="height: 550px;">

    @include('smartmessenger::messages.partials.chat.left-panel')

    {{-- RIGHT CHAT PANEL --}}
    <div class="col-md-8 p-0 d-flex" style="height:100%;">
        @include('smartmessenger::messages.partials.chat.chat-panel')
        @include('smartmessenger::messages.partials.chat.details-panel')
    </div>
</div>

{{-- This need to change and this should come from generic modal --}}
<div class="modal fade" id="feedbackModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">We value your feedback</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <input type="hidden" id="ratingValue">
                <input type="hidden" id="messageId">

                <div class="mb-3">
                    <label class="form-label">Your feedback</label>
                    <textarea class="form-control" id="feedbackText" rows="3"
                              placeholder="Tell us what we can improve..."></textarea>
                </div>
            </div>

            <div class="modal-footer">
                <button class="btn btn-sm btn-outline-secondary disabled" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-sm btn-outline-primary disabled" id="submitFeedback">Submit</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
@include('smartmessenger::messages.partials.chat.scripts.state-country')
@include('smartmessenger::messages.partials.chat.scripts.navigation-contacts')
@include('smartmessenger::messages.partials.chat.scripts.messaging-actions')
@include('smartmessenger::messages.partials.chat.scripts.ui-extras')
@include('smartmessenger::messages.partials.chat.scripts.diagnostics')
<style>
    .hover-bg-light:hover {
        background-color: #f8f9fa;
    }
    
    .all-contact-item:hover {
        background-color: #f8f9fa !important;
    }
    
    .contact-item.active {
        background-color: #e7f1ff !important;
        border-left: 3px solid #0d6efd;
    }

    .dev-mode-collapse .accordion,
    .dev-mode-collapse .accordion-item,
    .dev-mode-collapse .accordion-collapse,
    .dev-mode-collapse .accordion-body {
        max-width: 100% !important;
        min-width: 0 !important;
        box-sizing: border-box;
    }

    .dev-mode-collapse pre {
        white-space: pre-wrap;
        word-break: break-all;
        max-width: 100%;
    }

    .diag-json-scroll {
        max-width: 100%;
        overflow-x: auto;
    }

    .handover-summary-wrapper {
        max-width: calc(100% - 34px);
        margin-left: 34px;
    }

    .handover-summary-card {
        border: 1px solid #f1dba3;
        border-radius: 12px;
        background: linear-gradient(180deg, #fff8e9 0%, #fffdf5 100%);
        color: #4d3f1f;
        padding: 10px 12px;
    }

    .handover-summary-icon {
        color: #a6781a;
    }

    .handover-summary-label {
        color: #87671f;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .03em;
        text-transform: uppercase;
        margin-bottom: 2px;
    }

    .handover-next-best {
        background-color: #fff2cc;
        border: 1px solid #ebcd80;
    }

    .handover-turns-toggle {
        background: transparent !important;
        color: #6e5725 !important;
    }

    .handover-turns-toggle:not(.collapsed) {
        background: transparent !important;
        color: #6e5725 !important;
    }

    .handover-turns-toggle::after {
        filter: sepia(1) saturate(2.5) hue-rotate(355deg) brightness(.75);
        opacity: .8;
    }

    .handover-turn-item {
        border: 1px solid #ecdba9;
        background-color: #fffef9;
    }

    .handover-turn-label {
        font-weight: 700;
        color: #6b5320;
    }

    @media (max-width: 767.98px) {
        .handover-summary-wrapper {
            max-width: 100%;
            margin-left: 0;
        }
    }

</style>
@endpush
