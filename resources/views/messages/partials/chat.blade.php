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

</style>
@endpush
