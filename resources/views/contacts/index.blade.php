@extends('userinterface::layouts.app')

@section('page-title', \Iquesters\Foundation\Helpers\MetaHelper::make(['Contact']))
@section('meta-description', \Iquesters\Foundation\Helpers\MetaHelper::description('List of Contact'))

@section('content')
<div class="container-fluid p-0">
    <div class="row g-0" style="height: calc(100vh - 100px);">

        <!-- LEFT SIDEBAR - Contacts List -->
        <div class="col-md-4 col-lg-3 border-end d-flex flex-column bg-light">
            <!-- Header -->
            <div class="p-3 border-bottom bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="fs-6 text-muted mb-0">Contacts
                        (<span id="contactCount" class="badge text-primary rounded-pill">0</span>)
                    </h5>
                </div>
            </div>

            <!-- Search Bar -->
            <div class="input-group input-group-sm">
                <span class="input-group-text bg-white py-2">
                    <i class="fas fa-fw fa-search"></i>
                </span>
                <input type="text" 
                       id="searchInput" 
                       class="form-control border-start-0 py-2" 
                       placeholder="Search contacts...">
            </div>

            <!-- Contacts List -->
            <div id="contactList" class="flex-grow-1 overflow-auto">
                <div class="p-4 text-center text-muted">
                    <div class="spinner-border spinner-border-sm mb-2" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <div class="small">Loading contacts...</div>
                </div>
            </div>
        </div>

        <!-- RIGHT PANEL - Contact Details -->
        <div class="col-md-8 col-lg-9 d-flex flex-column">
            <div id="contactDetails" class="flex-grow-1 d-flex align-items-center justify-content-center bg-white">
                <div class="text-center text-muted">
                    <div class="display-1 mb-3 opacity-25">👤</div>
                    <h5 class="fs-6 text-muted mb-2">Select a contact</h5>
                    <p class="text-muted small">Choose a contact from the list to view details</p>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Edit Contact Modal -->
<div class="modal fade" id="editContactModal" tabindex="-1" aria-labelledby="editContactModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editContactModalLabel">Edit Contact</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editContactForm">
                    <input type="hidden" id="editContactUid" name="uid">
                    
                    <!-- Name Field -->
                    <div class="mb-3">
                        <label for="editContactName" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" 
                               class="form-control" 
                               id="editContactName" 
                               name="name" 
                               placeholder="Enter contact name"
                               required>
                        <div class="invalid-feedback">Please enter a name</div>
                    </div>

                    <!-- Identifier Field (Disabled) -->
                    <div class="mb-3">
                        <label for="editContactIdentifier" class="form-label">Identifier</label>
                        <input type="text" 
                               class="form-control" 
                               id="editContactIdentifier" 
                               name="identifier" 
                               disabled
                               readonly>
                        <small class="text-muted">Identifier cannot be changed</small>
                    </div>

                    <!-- Error Alert -->
                    <div id="editContactError" class="alert alert-danger d-none" role="alert"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-dark" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-outline-primary" id="saveContactBtn">
                    <span id="saveContactBtnText">Save Changes</span>
                    <span id="saveContactBtnSpinner" class="spinner-border spinner-border-sm d-none" role="status">
                        <span class="visually-hidden">Saving...</span>
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.contact-item {
    transition: background-color 0.2s ease;
    cursor: pointer;
    border-left: 3px solid transparent;
}

.contact-item:hover {
    background-color: #f8f9fa !important;
    border-left-color: #0d6efd;
}

.contact-item.active {
    background-color: #e7f1ff !important;
    border-left-color: #0d6efd;
}
</style>
@endsection

@push('scripts')
<script>
let allContacts = [];
let selectedContactUid = null;
let editModal = null;

document.addEventListener('DOMContentLoaded', () => {
    // Initialize Bootstrap modal
    editModal = new bootstrap.Modal(document.getElementById('editContactModal'));
    
    loadContacts();
    setupSearchFilter();
    setupEditContactForm();
});

/**
 * Load all contacts from API
 */
function loadContacts() {
    fetch('/api/smart-messenger/contacts', {
        method: 'GET',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        credentials: 'same-origin'
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            allContacts = data.data;
            renderContacts(allContacts);
            updateContactCount(allContacts.length);
        } else {
            showError(data.message || 'Failed to load contacts');
        }
    })
    .catch(error => {
        console.error('Error fetching contacts:', error);
        showError('Failed to load contacts. Please try again.');
    });
}

/**
 * Render contacts list
 */
function renderContacts(contactList) {
    const list = document.getElementById('contactList');
    
    if (!contactList || contactList.length === 0) {
        list.innerHTML = `
            <div class="p-5 text-center text-muted">
                <div class="display-4 mb-3 opacity-25">📭</div>
                <div class="fw-semibold mb-1">No contacts found</div>
                <small class="text-muted">Contacts will appear here once you start conversations</small>
            </div>
        `;
        return;
    }
    
    list.innerHTML = '';

    contactList.forEach((contact, index) => {
        const initials = getInitials(contact.name);
        const lastTwo = contact.identifier.slice(-2);
        
        const item = document.createElement('div');
        item.className = `contact-item p-3 border-bottom bg-white ${selectedContactUid === contact.uid ? 'active' : ''}`;
        item.dataset.uid = contact.uid;
        
        item.innerHTML = `
            <div class="d-flex align-items-center">
                <div class="position-relative me-3">
                    <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center"
                         style="width:45px;height:45px;">
                        <strong>${escapeHtml(lastTwo)}</strong>
                    </div>
                    ${contact.status && contact.meta?.profile_details?.provider?.icon ? `
                        <span class="position-absolute rounded-circle d-flex align-items-center justify-content-center"
                            style="width:20px;height:20px;right:0;bottom:0;border:2px solid white;background:#fff;">
                            ${contact.meta.profile_details.provider.icon}
                        </span>
                    ` : ''}
                </div>
                
                <div class="flex-grow-1 overflow-hidden">
                    <div class="d-flex justify-content-between align-items-start">
                        <p class="small fw-semibold mb-0 text-truncate">${escapeHtml(contact.name)}</p>
                    </div>
                    <div class="text-muted small text-truncate">
                        ${escapeHtml(contact.identifier)}
                    </div>
                </div>
            </div>
        `;
        
        item.addEventListener('click', () => showDetails(contact));
        list.appendChild(item);
    });
}

/**
 * Show contact details in right panel
 */
function showDetails(contact) {
    selectedContactUid = contact.uid;
    
    // Update active state in list
    document.querySelectorAll('.contact-item').forEach(item => {
        item.classList.remove('active');
        if (item.dataset.uid === contact.uid) {
            item.classList.add('active');
        }
    });
    
    const lastTwo = contact.identifier.slice(-2);
    const organisationInfo = contact.organisations && contact.organisations.length
    ? `
        <div class="mb-3 d-flex flex-column align-items-start justify-content-center w-50">
            <h6 class="mb-2">Organisation</h6>
            <div class="d-flex flex-wrap gap-2">
                ${contact.organisations.map(org => `
                    <span class="badge bg-primary">
                        ${escapeHtml(org.name)}
                    </span>
                `).join('')}
            </div>
        </div>
    `
    : '';

    const profileInfo = contact.metas && contact.metas.length ? `
        <div class="w-50">
            <h6 class="text-start text-muted mb-2">Profile Information</h6>
            ${contact.metas.map(meta => `
                <div class="mb-2 p-2 border rounded">
                    <div class="fw-semibold">${escapeHtml(meta.integration_name)} (${escapeHtml(meta.integration_type || 'Channel')})</div>
                    <div>Name: ${escapeHtml(meta.name)}</div>
                    <div>Identifier: ${escapeHtml(meta.identifier)}</div>
                    <div>Status: ${escapeHtml(meta.status)}</div>
                </div>
            `).join('')}
        </div>
    ` : '';

    
    document.getElementById('contactDetails').innerHTML = `
        <div class="p-5 text-center w-100">
            <div class="d-flex align-items-center justify-content-end gap-2">
                ${contact.status ? `<x-userinterface::status :status="active">
                            ${escapeHtml(contact.status)}
                        </x-userinterface::status>` : ''}
                <button class="btn btn-sm btn-outline-dark" onclick="openEditModal('${contact.uid}')">
                    <i class="fas fa-fw fa-edit"></i>
                </button>
            </div>
            <div class="d-flex align-items-center justify-content-start gap-4 mb-4">
                <div class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center shadow"
                    style="width:120px;height:120px;font-size:36px;">
                    ${escapeHtml(lastTwo)}
                </div>
                <div class="fs-4">${escapeHtml(contact.name)}</div>
            </div>
            <div class="mb-3 d-flex flex-column align-items-start justify-content-center w-50">
                <h6 class="mb-2">Identifier</h6>
                <p class="text-muted">${escapeHtml(contact.identifier)}</p>
            </div>
            ${organisationInfo}     
            ${profileInfo}
            
            <div class="mt-5">
                <small class="text-muted">
                    Contact since ${formatDate(contact.created_at)}
                </small>
            </div>
        </div>
    `;
}

/**
 * Open edit modal
 */
function openEditModal(uid) {
    const contact = allContacts.find(c => c.uid === uid);
    
    if (!contact) {
        alert('Contact not found');
        return;
    }
    
    // Populate form
    document.getElementById('editContactUid').value = contact.uid;
    document.getElementById('editContactName').value = contact.name;
    document.getElementById('editContactIdentifier').value = contact.identifier;
    
    // Clear any previous errors
    document.getElementById('editContactError').classList.add('d-none');
    document.getElementById('editContactForm').classList.remove('was-validated');
    
    // Show modal
    editModal.show();
}

/**
 * Setup edit contact form
 */
function setupEditContactForm() {
    const form = document.getElementById('editContactForm');
    const saveBtn = document.getElementById('saveContactBtn');
    
    saveBtn.addEventListener('click', async (e) => {
        e.preventDefault();
        
        // Validate form
        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            return;
        }
        
        const uid = document.getElementById('editContactUid').value;
        const name = document.getElementById('editContactName').value.trim();
        
        // Show loading state
        document.getElementById('saveContactBtnText').classList.add('d-none');
        document.getElementById('saveContactBtnSpinner').classList.remove('d-none');
        saveBtn.disabled = true;
        
        try {
            const response = await fetch(`/api/smart-messenger/contacts/${uid}`, {
                method: 'PUT',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    name: name
                })
            });
            
            const data = await response.json();
            
            if (response.ok && data.success) {
                // Update local contact data
                const contactIndex = allContacts.findIndex(c => c.uid === uid);
                if (contactIndex !== -1) {
                    allContacts[contactIndex] = { ...allContacts[contactIndex], ...data.data };
                    
                    // Re-render contacts
                    renderContacts(allContacts);
                    
                    // Update details panel if this contact is selected
                    if (selectedContactUid === uid) {
                        showDetails(allContacts[contactIndex]);
                    }
                }
                
                // Close modal
                editModal.hide();
                
                // Show success message (optional - you can add a toast notification)
                console.log('Contact updated successfully');
            } else {
                throw new Error(data.message || 'Failed to update contact');
            }
        } catch (error) {
            console.error('Error updating contact:', error);
            const errorDiv = document.getElementById('editContactError');
            errorDiv.textContent = error.message;
            errorDiv.classList.remove('d-none');
        } finally {
            // Reset button state
            document.getElementById('saveContactBtnText').classList.remove('d-none');
            document.getElementById('saveContactBtnSpinner').classList.add('d-none');
            saveBtn.disabled = false;
        }
    });
}

/**
 * Setup search filter
 */
function setupSearchFilter() {
    const searchInput = document.getElementById('searchInput');
    
    searchInput.addEventListener('input', (e) => {
        const searchTerm = e.target.value.toLowerCase().trim();
        
        if (!searchTerm) {
            renderContacts(allContacts);
            updateContactCount(allContacts.length);
            return;
        }
        
        const filtered = allContacts.filter(contact => {
            return contact.name.toLowerCase().includes(searchTerm) ||
                   contact.identifier.toLowerCase().includes(searchTerm);
        });
        
        renderContacts(filtered);
        updateContactCount(filtered.length);
    });
}

/**
 * Show error message
 */
function showError(message) {
    const list = document.getElementById('contactList');
    list.innerHTML = `
        <div class="p-4 text-center">
            <div class="text-danger mb-3 display-5">⚠️</div>
            <div class="text-muted mb-3">${escapeHtml(message)}</div>
            <button class="btn btn-sm btn-primary" onclick="loadContacts()">
                Retry
            </button>
        </div>
    `;
}

/**
 * Update contact count badge
 */
function updateContactCount(count) {
    const badge = document.getElementById('contactCount');
    if (badge) {
        badge.textContent = count;
    }
}

/**
 * Get initials from name
 */
function getInitials(name) {
    if (!name) return '?';
    const parts = name.trim().split(' ');
    if (parts.length >= 2) {
        return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
    }
    return name.charAt(0).toUpperCase();
}

/**
 * Format date
 */
function formatDate(dateString) {
    if (!dateString) return 'Unknown';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    });
}

/**
 * Escape HTML to prevent XSS
 */
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
</script>
@endpush