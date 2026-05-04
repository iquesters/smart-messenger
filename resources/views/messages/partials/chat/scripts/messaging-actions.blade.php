<script>
    $('#sendMessageForm').on('submit', function(e) {
        e.preventDefault();

        const form = $(this);

        $.post("{{ route('messages.send') }}", form.serialize())
            .done(function () {
                location.reload();
            })
            .fail(function () {
                alert('Failed to send message');
            });
    });

    function bindReturnToBotButton(button) {
        if (!button || button.dataset.bound === '1') {
            return;
        }

        button.dataset.bound = '1';
        button.addEventListener('click', async function () {
            const contactUid = button.dataset.contactUid;
            const chatbotIntegrationUid = button.dataset.chatbotIntegrationUid;
            const reason = button.dataset.reason || 'agent_returned_control_to_bot';

            if (!contactUid || !chatbotIntegrationUid) {
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

    bindReturnToBotButton(document.getElementById('returnToBotBtn'));
    bindReturnToBotButton(document.getElementById('returnToBotDropdownBtn'));

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
