<div class="row g-0 smart-chat-layout" id="smartChatLayout" data-auto-viewport-height data-viewport-height-offset="8" data-viewport-height-min="320" data-viewport-height-breakpoint="768" data-viewport-height-mobile="auto" data-viewport-height-watch="#chatView">

    @include('smartmessenger::messages.partials.chat.left-panel')

    {{-- RIGHT CHAT PANEL --}}
    <div class="col-md-8 p-0 d-flex smart-chat-main" style="height:100%;">
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
{{-- @todo Move these chat layout styles into a dedicated CSS asset once the UI structure is finalized. --}}
<style>
    .smart-chat-layout {
        min-height: 20rem;
        overflow: hidden;
    }

    .smart-chat-layout > [class*='col-'] {
        min-height: 0;
    }

    .smart-chat-main {
        min-height: 0;
        overflow: hidden;
    }

    .smart-chat-details:not(.d-none) {
        display: flex;
        flex-direction: column;
        min-height: 0;
        overflow: hidden;
    }

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

    @media (max-width: 767.98px) {
        .smart-chat-layout {
            height: auto !important;
            min-height: 0;
            overflow: visible;
        }

        .smart-chat-sidebar {
            height: 50vh !important;
            min-height: 14rem !important;
            flex: 0 0 auto;
        }

        .smart-chat-main {
            height: 80vh !important;
            min-height: 18rem;
            overflow: hidden;
        }

        .smart-chat-details:not(.d-none) {
            width: 100% !important;
            max-width: 100%;
            flex: 0 0 auto !important;
            border-left: 0 !important;
            border-top: 1px solid var(--bs-border-color);
        }
    }

</style>
@endpush
