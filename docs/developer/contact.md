# Contact Module — Developer Reference

## Table of Contents
- #overview
- key-features
- #folder-structure
- #key-files-and-responsibilities
- #database-schema
- #model-relationships
- #api-endpoints
- #events-and-observability
- #authorization-and-security
- testing-strategy
- #development-guidelines
- #logging
- #performance-considerations
- #future-enhancements

---

## Overview

The `contact` module is part of the `iquesters/smart-messenger` package. It provides a comprehensive contact management system for storing, organizing, and managing contacts with flexible metadata storage and support for multi-channel integration.

---

## Key Features

- **Model-based architecture** using Eloquent ORM
- **RESTful API endpoints** for full CRUD operations
- **Service layer** isolating business logic
- **Event-driven architecture** for hooks and observability
- **Comprehensive logging** for debugging and monitoring

---

## Folder Structure

```
smart-messenger/
│
├── 📁 src/
│   ├── Models/
│   │   ├── Contact.php                     # Main contact model with relationships
│   │   └── ContactMeta.php                 # Metadata model for flexible attributes
│   │
│   ├── Http/
│   │   └── Controllers/
│   │       └── ContactController.php       # REST endpoints for contact operations
│   │
│   ├── Services/
│   │   └── ContactService.php              # Business logic and operations
│   │
│   ├── Events/
│   │   ├── ContactCreatedEvent.php         # Fired when contact is created
│   │   ├── ContactUpdatedEvent.php         # Fired when contact is updated
│   │   └── ContactDeletedEvent.php         # Fired when contact is deleted
│   │
│   ├── Listeners/
│   │   └── ContactEventListeners.php       # Handles contact lifecycle events
│   │
│   └── Jobs/
│       └── ContactJobs/                    # Async jobs for contact operations
│
├── 📁 database/
│   ├── migrations/
│   │   ├── 2024_12_11_000003_create_contacts_table.php       # Main contacts table
│   │   └── 2024_12_11_000004_create_contact_metas_table.php  # Metadata table
│   │
│   └── seeders/
│       └── SmartMessengerSeeder.php        # Test data seeding
│
├── 📁 resources/
│   └── views/
│       └── contacts/
│           ├── index.blade.php             # Contact list view
│
└── 📁 tests/
    ├── Feature/
    │   └── ContactFeatureTest.php          # Integration tests
    └── Unit/
        └── ContactModelTest.php            # Unit tests for model logic
```

---

## Key Files and Responsibilities

| File | Purpose |
|------|---------|
| `src/Models/Contact.php` | Eloquent model with relationships, accessors, and mutators |
| `src/Models/ContactMeta.php` | Flexible key-value metadata model |
| `src/Http/Controllers/ContactController.php` | RESTful endpoints (index, show, store, update, destroy) |
| `src/Services/ContactService.php` | Business logic for all contact operations |
| `database/migrations/*_create_contacts_table.php` | Schema definition |
| `resources/views/contacts/` | Blade UI templates |

---

## Database Schema

### `contacts` Table

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT | Primary key |
| `uid` | STRING | Unique identifier (ULID format) |
| `name` | STRING | Contact display name |
| `identifier` | STRING | Unique ref (email, phone, WhatsApp ID) |
| `status` | ENUM | `active` / `inactive` (default: `active`) |
| `created_by` | INT | User ID who created the record |
| `updated_by` | INT | User ID who last updated the record |
| `created_at` | TIMESTAMP | Creation timestamp |
| `updated_at` | TIMESTAMP | Last update timestamp |

### `contact_metas` Table

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT | Primary key |
| `ref_parent` | INT | Foreign key → `contacts.id` |
| `meta_key` | STRING | Metadata field name |
| `meta_value` | TEXT | Metadata field value |
| `status` | ENUM | `active` / `inactive` (default: `active`) |
| `created_by` | INT | User ID who created the record |
| `updated_by` | INT | User ID who last updated the record |
| `created_at` | TIMESTAMP | Creation timestamp |
| `updated_at` | TIMESTAMP | Last update timestamp |

---

## Model Relationships

```
Contact (1) ──── (N) ContactMeta
                      └─ ref_parent → contacts.id
```

```php
// In Contact model
public function metas() {
    return $this->hasMany(ContactMeta::class, 'ref_parent');
}

// In ContactMeta model
public function contact() {
    return $this->belongsTo(Contact::class, 'ref_parent');
}
```

---

## API Endpoints

```
GET    /contacts                           # List all contacts
GET    /contacts/{id}                      # Get a specific contact
POST   /contacts                           # Create new contact
PUT    /contacts/{id}                      # Update contact
DELETE /contacts/{id}                      # Delete contact

GET    /contacts/{id}/metas                # Get contact metadata
POST   /contacts/{id}/metas               # Add metadata entry
PUT    /contacts/{id}/metas/{metaId}       # Update metadata entry
DELETE /contacts/{id}/metas/{metaId}       # Delete metadata entry
```

---

## Events and Observability

| Event | When Triggered | Data Provided |
|-------|----------------|---------------|
| `ContactCreatedEvent` | New contact created | Contact instance, `created_by` user |
| `ContactUpdatedEvent` | Contact fields updated | Contact instance, changes, `updated_by` user |
| `ContactDeletedEvent` | Contact deleted | Contact instance, `deleted_by` user |

**Example listener:**
```php
class SendContactWelcomeEmail implements ShouldQueue {
    public function handle(ContactCreatedEvent $event) {
        Mail::send(new ContactWelcome($event->contact));
    }
}
```

---

## Authorization and Security

### Access Control
All endpoints require an authenticated user. Use Laravel authorization policies:

```php
// In ContactPolicy
public function view(User $user, Contact $contact) {
    return $user->can('view_contacts');
}
```

### Security Features
- CSRF protection on all state-changing operations
- SQL injection prevention via Eloquent ORM
- XSS protection in Blade templates
- Rate limiting on API endpoints
- Input validation on all create/update operations
- Full audit trail via `created_by` / `updated_by` fields

---

## Testing Strategy

### Running Tests
```bash
php artisan test tests/Feature/ContactFeatureTest.php
php artisan test tests/Unit/ContactModelTest.php
```

### Coverage Areas

| Test Type | What It Covers |
|-----------|----------------|
| **Unit** | Model validation, relationships, accessors/mutators, status transitions |
| **Feature** | CRUD flows, metadata workflows, unauthorized access rejection, event firing |
| **Integration** | Multi-table transactions, event listeners, API response formats, status codes |
| **Negative** | Invalid data, permission boundaries, concurrent update handling |

---

## Development Guidelines

### Adding New Metadata Fields
No migration needed — use the existing `contact_metas` table:

```php
$contact->metas()->create([
    'meta_key'   => 'department',
    'meta_value' => 'Sales',
    'created_by' => auth()->id()
]);
```

### Extending the Contact Model

```php
class Contact extends Model {
    // Custom scope
    public function scopeActive($query) {
        return $query->where('status', 'active');
    }

    // Custom accessor
    public function getFullNameAttribute() {
        return "{$this->name} ({$this->identifier})";
    }
}
```

### Creating Custom Observers

```php
class ContactObserver {
    public function creating(Contact $contact) {
        $contact->created_by = auth()->id();
    }

    public function updating(Contact $contact) {
        $contact->updated_by = auth()->id();
    }
}
```

---

## Logging

```php
Log::info('Contact created', [
    'contact_id'  => $contact->id,
    'contact_uid' => $contact->uid,
    'created_by'  => $contact->created_by,
    'identifier'  => $contact->identifier
]);

Log::error('Failed to create contact', [
    'error'   => $exception->getMessage(),
    'user_id' => auth()->id()
]);
```

---

## Performance Considerations

### Prevent N+1 Queries
```php
$contacts = Contact::with('metas')->get();
```

### Pagination
```php
$contacts = Contact::paginate(15);
```

### Recommended Indexes
Index the following columns for query performance: `uid`, `identifier`, `status`.

### Caching Strategy
- Cache contact lookups by UID
- Invalidate cache on contact update
- Consider Redis for high-traffic scenarios

---

## Future Enhancements
- Advanced search and filtering
- Bulk import / export operations
- Contact grouping and segmentation
- External CRM integrations
- GraphQL API endpoint support