<script>
   $('#sendMessageForm').on('submit', async function(e) {
    e.preventDefault();

    const $form = $(this);
    const $submitBtn = $form.find('button[type="submit"]');
    const originalHtml = $submitBtn.html();
    $submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>');

    const formData = new FormData(this);
    const fileInput = document.getElementById('mediaFileInput');
    const mediaFile = fileInput?.files?.[0];
    const isVideoFile = mediaFile?.type?.startsWith('video/');

    try {
        if (mediaFile && isVideoFile) {
            const messageId = 'mock-video-' + Date.now();
            const normalizeFormData = new FormData();
            normalizeFormData.append('file', mediaFile);
            normalizeFormData.append('message_id', messageId);
            normalizeFormData.append('quality', 'sd');

            const uploadResponse = await fetch('/mock/media/normalize-video', {
                method: 'POST',
                body: normalizeFormData,
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            });

            if (!uploadResponse.ok) {
                const errorBody = await uploadResponse.text();
                throw new Error(errorBody || 'Video normalize upload failed');
            }

            await waitForMockJobReady(messageId);
            
            // Add message_id and channel_id to the form data for mock send to reference the conversion
            formData.append('message_id', messageId);
            formData.append('channel_id', document.querySelector('input[name="profile_id"]').value);
        }

        $.ajax({
            url: "{{ route('messages.send') }}",
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                document.getElementById('mediaFileInput').value = '';
                document.getElementById('mediaPreview')?.classList.add('d-none');
                document.getElementById('mediaPreviewImg').src = '';
                document.getElementById('mediaPreviewVideo').src = '';
                location.reload();
            },
            error: function(xhr) {
                try {
                    const res = JSON.parse(xhr.responseText);
                    alert(res.error || res.message || 'Failed to send message');
                } catch (e) {
                    alert('Failed to send message');
                }
                $submitBtn.prop('disabled', false).html(originalHtml);
            }
        });
    } catch (error) {
        console.error(error);
        alert(error.message || 'Failed to process video');
        $submitBtn.prop('disabled', false).html(originalHtml);
    }
});
async function waitForMockJobReady(messageId) {
    const maxAttempts = 10;
    const delayMs = 1000;

    for (let attempt = 0; attempt < maxAttempts; attempt++) {
        const response = await fetch(`/mock/media/jobs/${messageId}`, {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            credentials: 'same-origin'
        });

        if (!response.ok) {
            throw new Error('Failed to check mock video job status');
        }

        const job = await response.json();

        if (job.state === 'ready') {
            return job;
        }

        if (job.state === 'failed') {
            throw new Error(job.error_message || 'Mock video processing failed');
        }

        await new Promise((resolve) => setTimeout(resolve, delayMs));
    }

    throw new Error('Mock video processing timed out');
}

    function bindReturnToBotButton(button) {
        if (!button || button.dataset.bound === '1') {
            return;
        }

        button.dataset.bound = '1';
        button.addEventListener('click', async function () {
            const sessionId = button.dataset.sessionId;
            const contactUid = button.dataset.contactUid;
            const chatbotIntegrationUid = button.dataset.chatbotIntegrationUid;
            const reason = button.dataset.reason || 'agent_returned_control_to_bot';

            if (!sessionId || !contactUid || !chatbotIntegrationUid) {
                alert('Missing chat session routing identifiers');
                return;
            }

            if (!confirm('Return this conversation to the chatbot?')) {
                return;
            }

            button.disabled = true;

            try {
                const response = await fetch("{{ route('smart-messenger.chat-sessions.handover.return-to-bot') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document
                            .querySelector('meta[name=\"csrf-token\"]')
                            .getAttribute('content')
                    },
                    body: JSON.stringify({
                        session_id: sessionId,
                        contact_uid: contactUid,
                        chatbot_integration_uid: chatbotIntegrationUid,
                        reason: reason
                    })
                });

                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw result;
                }

                location.reload();
            } catch (error) {
                console.error(error);
                alert(error.message || 'Failed to return control to chatbot');
            } finally {
                button.disabled = false;
            }
        });
    }

    function bindActivateHumanHandoverButton(button) {
        if (!button || button.dataset.bound === '1') {
            return;
        }

        button.dataset.bound = '1';
        button.addEventListener('click', async function () {
            const sessionId = button.dataset.sessionId;
            const contactUid = button.dataset.contactUid;
            const chatbotIntegrationUid = button.dataset.chatbotIntegrationUid;
            const reason = button.dataset.reason || 'manual_human_handover';

            if (!sessionId || !contactUid || !chatbotIntegrationUid) {
                alert('Missing chat session routing identifiers');
                return;
            }

            if (!confirm('Move this conversation to human handover?')) {
                return;
            }

            button.disabled = true;

            try {
                const response = await fetch("{{ route('smart-messenger.chat-sessions.handover.activate') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document
                            .querySelector('meta[name=\"csrf-token\"]')
                            .getAttribute('content')
                    },
                    body: JSON.stringify({
                        session_id: sessionId,
                        contact_uid: contactUid,
                        chatbot_integration_uid: chatbotIntegrationUid,
                        reason: reason
                    })
                });

                const result = await response.json();

                if (!response.ok || !result.success) {
                    throw result;
                }

                location.reload();
            } catch (error) {
                console.error(error);
                alert(error.message || 'Failed to activate human handover');
            } finally {
                button.disabled = false;
            }
        });
    }

    bindReturnToBotButton(document.getElementById('returnToBotDropdownBtn'));
    bindActivateHumanHandoverButton(document.getElementById('activateHumanHandoverDropdownBtn'));

    // Contact creation
    window.currentMessagingProfileId = @json(
        collect($numbers)
            ->firstWhere('number', $selectedNumber)['profile_id']
            ?? null
    );
    const saveContactBtn = document.getElementById('saveContactBtn');

    if (saveContactBtn) {
        saveContactBtn.addEventListener('click', async function () {

            const name = document.getElementById('contactName').value.trim();
            const number = document.getElementById('contactNumber').value.trim();
            const countryCode = document
                .getElementById('selectedCode')
                .innerText.replace('+', '');

            if (!name || !number) {
                alert('Name and number are required');
                return;
            }

            if (!window.currentMessagingProfileId) {
                alert('Messaging profile not selected');
                return;
            }

            const payload = {
                name: name,
                identifier: countryCode + number,
                messaging_profile_id: window.currentMessagingProfileId
            };

            saveContactBtn.disabled = true;
            saveContactBtn.innerText = 'Saving...';

            try {
                const response = await fetch('/api/smart-messenger/contacts', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document
                            .querySelector('meta[name="csrf-token"]')
                            .getAttribute('content')
                    },
                    body: JSON.stringify(payload)
                });

                const result = await response.json();

                if (!response.ok) {
                    throw result;
                }

                // ✅ Success
                alert('Contact created successfully');

                // Reset form
                document.getElementById('contactName').value = '';
                document.getElementById('contactNumber').value = '';

                // Go back to contacts list
                showView('contacts');

                // Reload to show new contact in chat list
                location.reload();

            } catch (error) {
                console.error(error);

                if (error.errors) {
                    alert(Object.values(error.errors).flat().join('\n'));
                } else {
                    alert(error.message || 'Failed to create contact');
                }
            } finally {
                saveContactBtn.disabled = false;
                saveContactBtn.innerText = 'Save Contact';
            }
        });
    }
</script>
