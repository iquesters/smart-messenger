@extends('userinterface::layouts.app')

@section('content')
<div class="container-fluid p-0">
    <div class="row g-0" style="height: calc(100vh - 100px);">

        <!-- LEFT SIDEBAR - Contacts List -->
        <div class="col-md-4 col-lg-3 border-end d-flex flex-column bg-light">
            <!-- Header -->
            <div class="p-3 border-bottom bg-white">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="fs-6 text-muted mb-0">Contacts
                        <span id="contactCount" class="badge text-primary rounded-pill">0</span>
                    </h5>
                </div>
            </div>

            <!-- Search Bar -->
            <div class="p-3 border-bottom bg-white">
                <input type="text" 
                       id="searchInput" 
                       class="form-control form-control-sm" 
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

document.addEventListener('DOMContentLoaded', () => {
    loadContacts();
    setupSearchFilter();
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
                        <small class="text-muted ms-2" style="font-size:10px;white-space:nowrap;">
                            ${formatTime(contact.created_at)}
                        </small>
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
    
    const profileInfo = contact ? `
        <div class=" w-50">
            <h6 class="text-start text-muted mb-2">Profile Information</h6>

            ${contact.meta?.profile_details?.provider?.icon ? `
                <div class="mb-3">
                    <div class="fw-medium d-flex align-items-center gap-2">
                        ${contact.meta?.profile_details?.provider?.icon}
                        ${contact.meta.profile_details.profile_name ? `
                                <div class="">${escapeHtml(contact.meta.profile_details.profile_name)}</div>
                        ` : ''}
                    </div>
                </div>
            ` : ''}
        </div>
    ` : '';

    
    document.getElementById('contactDetails').innerHTML = `
        <div class="p-5 text-center w-100">
            <div class="d-flex align-items-center justify-content-end gap-2">
                ${contact.status ? `<span class="badge badge-active">${escapeHtml(contact.status)}</span>` : ''}
                <button class="btn btn-sm btn-outline-secondary">
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
 * Format time (HH:mm)
 */
function formatTime(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleTimeString('en-US', { 
        hour: '2-digit', 
        minute: '2-digit',
        hour12: false
    });
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