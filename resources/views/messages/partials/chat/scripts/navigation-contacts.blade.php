<script>
    function showView(view, title = 'New Chat') {
        contactsView.classList.add('d-none');
        chatOptions.classList.add('d-none');
        newContactForm.classList.add('d-none');
        newGroupForm.classList.add('d-none');

        if (view === 'contacts') {
            mainHeader.classList.remove('d-none');
            backHeader.classList.add('d-none');
            mainSearchBox.classList.remove('d-none');
            chatSearchInput.placeholder = 'Search';
        } else {
            mainHeader.classList.add('d-none');
            backHeader.classList.remove('d-none');
            backHeaderTitle.textContent = title;
            mainSearchBox.classList.remove('d-none');
            
            if (view === 'newGroup') {
                chatSearchInput.placeholder = 'Search contacts...';
            } else if (view === 'options') {
                chatSearchInput.placeholder = 'Search contacts...';
            } else {
                chatSearchInput.placeholder = 'Search';
            }
        }

        chatSearchInput.value = '';
        
        document.querySelectorAll('.contact-item').forEach(item => item.style.display = '');
        document.querySelectorAll('.group-contact-item').forEach(item => item.style.display = '');
        document.querySelectorAll('.all-contact-item').forEach(item => item.style.display = '');

        switch(view) {
            case 'contacts':
                contactsView.classList.remove('d-none');
                break;
            case 'options':
                chatOptions.classList.remove('d-none');
                loadAllContacts(); // Load all contacts when showing options
                break;
            case 'newContact':
                newContactForm.classList.remove('d-none');
                break;
            case 'newGroup':
                newGroupForm.classList.remove('d-none');
                break;
        }

        currentView = view;
    }

    // Load all contacts via AJAX
    async function loadAllContacts() {
        const container = document.getElementById('allContactsList');
        
        try {
            const response = await fetch('/api/smart-messenger/contacts', {
                method: 'GET',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.success && data.data) {
                allContactsData = data.data;
                renderAllContacts(data.data);
            } else {
                container.innerHTML = `
                    <div class="p-3 text-center text-muted">
                        <div class="small">No contacts found</div>
                    </div>
                `;
            }
        } catch (error) {
            console.error('Error loading contacts:', error);
            container.innerHTML = `
                <div class="p-3 text-center text-muted">
                    <div class="small text-danger">Failed to load contacts</div>
                    <button class="btn btn-sm btn-link" onclick="loadAllContacts()">Retry</button>
                </div>
            `;
        }
    }

    // Render all contacts in the new chat view
    function renderAllContacts(contacts) {
        const container = document.getElementById('allContactsList');
        
        if (!contacts || contacts.length === 0) {
            container.innerHTML = `
                <div class="p-4 text-center text-muted">
                    <div class="display-4 mb-3 opacity-25">📭</div>
                    <div class="small">No contacts found</div>
                </div>
            `;
            return;
        }
        
        container.innerHTML = contacts.map(contact => {
            const lastTwo = contact.identifier.slice(-2);
            const providerIcon = contact.meta?.profile_details?.provider?.icon || '';
            
            return `
                <div class="all-contact-item p-3 border-bottom bg-white hover-bg-light" 
                     style="cursor:pointer;"
                     data-identifier="${escapeHtml(contact.identifier)}"
                     data-name="${escapeHtml(contact.name)}"
                     onclick="openContactChat('${escapeHtml(contact.identifier)}', '${escapeHtml(contact.name)}')">
                    
                    <div class="d-flex align-items-center w-100 overflow-hidden">
                        
                        <div class="position-relative me-2 flex-shrink-0">
                            <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center"
                                style="width:45px;height:45px;">
                                <strong>${escapeHtml(lastTwo)}</strong>
                            </div>
                            
                            ${providerIcon ? `
                                <small class="position-absolute text-muted bg-white rounded-circle d-flex align-items-center justify-content-center"
                                    style="width:20px;height:20px;right:0;bottom:0;">
                                    ${providerIcon}
                                </small>
                            ` : ''}
                        </div>
                        
                        <div class="flex-grow-1" style="min-width:0;">
                            <p class="small fw-semibold mb-0 text-truncate">
                                ${escapeHtml(contact.name)}
                            </p>
                            <div class="text-muted small text-truncate">
                                ${escapeHtml(contact.identifier)}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    }

    // Open chat with a contact
    function openContactChat(identifier, name) {
        // Hide empty state if visible
        const emptyState = document.getElementById('emptyState');
        if (emptyState) {
            emptyState.classList.add('d-none');
        }
        
        // Show/create chat panel
        let chatPanel = document.getElementById('chatPanel');
        if (!chatPanel) {
            chatPanel = document.createElement('div');
            chatPanel.id = 'chatPanel';
            chatPanel.className = 'flex-grow-1 d-flex flex-column';
            document.querySelector('.col-md-8').appendChild(chatPanel);
        } else {
            chatPanel.classList.remove('d-none');
        }
        
        // Update chat header
        const chatHeaderInitials = document.getElementById('chatHeaderInitials');
        const chatHeaderName = document.getElementById('chatHeaderName');
        if (chatHeaderInitials) chatHeaderInitials.textContent = identifier.slice(-2);
        if (chatHeaderName) chatHeaderName.textContent = name;
        
        // Update details panel
        const detailsInitials = document.getElementById('detailsInitials');
        const detailsNumber = document.getElementById('detailsNumber');
        const detailsPhone = document.getElementById('detailsPhone');
        if (detailsInitials) detailsInitials.textContent = identifier.slice(-2);
        if (detailsNumber) detailsNumber.textContent = identifier;
        if (detailsPhone) detailsPhone.textContent = identifier;
        
        // Update message form
        const messageTo = document.getElementById('messageTo');
        if (messageTo) messageTo.value = identifier;
        
        // Clear messages container - show empty state for new conversation
        const messagesContainer = document.getElementById('messagesContainer');
        if (messagesContainer) {
            messagesContainer.innerHTML = `
                <div class="d-flex align-items-center justify-content-center h-100 text-muted">
                    <div class="text-center">
                        <div class="display-1 mb-3 opacity-25">💬</div>
                        <h6 class="fs-6 text-muted mb-2">No messages yet</h6>
                        <p class="text-muted small">Start the conversation with ${escapeHtml(name)}</p>
                    </div>
                </div>
            `;
        }
        
        // Navigate back to contacts view
        showView('contacts');
        
        // You can add AJAX call here to fetch existing messages if needed
        // fetchMessagesForContact(identifier);
    }

    // Helper function to escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    newChatBtn.addEventListener('click', () => {
        showView('options', 'New Chat');
    });

    backBtn.addEventListener('click', () => {
        if (currentView === 'newContact' || currentView === 'newGroup') {
            showView('options', 'New Chat');
        } else if (currentView === 'options') {
            showView('contacts');
        }
    });

    newContactBtn.addEventListener('click', () => {
        showView('newContact', 'New Contact');
    });

    newGroupBtn.addEventListener('click', () => {
        showView('newGroup', 'New Group');
    });

    chatSearchInput.addEventListener('input', function () {
        const q = this.value.toLowerCase();
        
        if (currentView === 'contacts') {
            document.querySelectorAll('.contact-item').forEach(item => {
                const number = item.dataset.number || '';
                const name = item.dataset.name || '';
                const message = item.dataset.message || '';
                const provider = item.dataset.provider || '';
                
                const match =
                    number.toLowerCase().includes(q) ||
                    name.toLowerCase().includes(q) ||
                    message.toLowerCase().includes(q) ||
                    provider.toLowerCase().includes(q);

                item.style.display = match ? '' : 'none';
            });
        }
        
        if (currentView === 'options') {
            document.querySelectorAll('.all-contact-item').forEach(item => {
                const identifier = item.dataset.identifier || '';
                const name = item.dataset.name || '';
                
                const match = 
                    identifier.toLowerCase().includes(q) ||
                    name.toLowerCase().includes(q);
                    
                item.style.display = match ? '' : 'none';
            });
        }
        
        if (currentView === 'newGroup') {
            document.querySelectorAll('.group-contact-item').forEach(item => {
                const match = item.dataset.contactName.includes(q);
                item.style.display = match ? '' : 'none';
            });
        }
    });

    function selectContact(contactNumber) {
        const contactInput = document.getElementById('selectedContactInput');
        const form = document.getElementById('numberForm');

        if (!contactInput || !form) {
            console.error('Contact input or form not found');
            return;
        }

        contactInput.value = contactNumber;
        form.submit();
    }
</script>
