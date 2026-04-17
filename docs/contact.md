# Contact Module Documentation

## Table of Contents
- [Overview](#overview)
- [For Users](#for-users)
- [For Developers](#for-developers)

## Overview

The `contact` module is part of the `iquesters/smart-messenger` package. It provides a comprehensive contact management system for storing, organizing, and managing contacts with flexible metadata storage and support for multi-channel integration.

**Key Features:**
- Contact creation and management
- Flexible metadata storage for custom fields
- Contact status tracking and lifecycle management
- Comprehensive audit trail with creator/updater tracking
- Role-based access control and authorization
- Full integration with the messaging platform

---

## For Users

### What is the Contact Module?

The Contact Module enables you to manage all your business contacts in a centralized system. You can store various types of contacts (customers, leads, team members) and associate custom information with each contact.

### Core Features

#### 1. **Contact Management**
- Create and organize contacts with essential information (name, identifier like phone/email)
- Track contact status (active, inactive, archived)
- Search and filter contacts easily
- Attach custom metadata to each contact for personalized information

#### 2. **Contact Information**
Each contact stores:
- **Name** - Display name of the contact
- **Identifier** - Unique reference (phone number, email, WhatsApp ID, etc.)
- **Status** - Current state (Active/Inactive)
- **Custom Metadata** - Add unlimited custom fields like department, tags, preferences, etc.

#### 3. **Audit Trail**
- See who created or last updated each contact
- Track creation and modification timestamps
- Maintain accountability for all contact changes

### How to Use

#### Creating a Contact
1. Navigate to Contacts section
2. Click "New Contact"
3. Enter contact name and identifier (phone/email)
4. Set status to Active
5. Add any custom metadata fields
6. Save

#### Managing Contact Metadata
- Store custom information like: service preferences, purchase history, communication tags, special notes
- Add multiple metadata entries per contact
- Update or remove metadata as needed
- Use metadata for filtering and segmentation

#### Viewing Contacts
- View all contacts in the contacts list
- Click on a contact to view detailed information including all associated metadata
- Filter by status or search by name/identifier

---

## For Developers

### Architecture Overview

The Contact Module follows Laravel conventions and best practices:
- **Model-based architecture** using Eloquent ORM
- **RESTful API endpoints** for CRUD operations
- **Service layer** for business logic
- **Event-driven architecture** for hooks and observability
- **Comprehensive logging** for debugging and monitoring

### Folder Structure

```
smart-messenger/
│
├── 📁 src/
│   ├── Models/
│   │   ├── Contact.php                 # Main contact model with relationships
│   │   ├── ContactMeta.php             # Metadata model for flexible attributes
│   │   └── (Contact & ContactMeta handle all ORM logic)
│   │
│   ├── Http/
│   │   └── Controllers/
│   │       └── ContactController.php   # REST endpoints for contact operations
│   │
│   ├── Services/
│   │   └── ContactService.php          # Business logic and operations
│   │
│   ├── Events/
│   │   ├── ContactCreatedEvent.php     # Fired when contact is created
│   │   ├── ContactUpdatedEvent.php     # Fired when contact is updated
│   │   └── ContactDeletedEvent.php     # Fired when contact is deleted
│   │
│   ├── Listeners/
│   │   └── ContactEventListeners.php   # Handle contact events
│   │
│   └── Jobs/
│       └── ContactJobs/                # Async jobs for contact operations
│
├── 📁 database/
│   ├── migrations/
│   │   ├── 2024_12_11_000003_create_contacts_table.php       # Main contacts table
│   │   └── 2024_12_11_000004_create_contact_metas_table.php  # Metadata table
│   │
│   └── seeders/
│       └── SmartMessengerSeeder.php    # Test data seeding
│
├── 📁 resources/
│   └── views/
│       └── contacts/
│           ├── index.blade.php         # Contact list view
│           ├── show.blade.php          # Individual contact detail view
│           └── form.blade.php          # Create/edit contact form
│
└── 📁 tests/
    ├── Feature/
    │   └── ContactFeatureTest.php      # Integration tests
    │
    └── Unit/
        └── ContactModelTest.php        # Unit tests for model logic
```

### Database Schema

#### `contacts` Table
Core contact information storage:

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT | Primary key |
| `uid` | STRING | Unique identifier (ULID format) |
| `name` | STRING | Contact display name |
| `identifier` | STRING | Unique identifier (email, phone, WhatsApp ID) |
| `status` | ENUM | Status: active/inactive (default: active) |
| `created_by` | INT | User ID who created record |
| `updated_by` | INT | User ID who last updated record |
| `created_at` | TIMESTAMP | Record creation timestamp |
| `updated_at` | TIMESTAMP | Record update timestamp |

#### `contact_metas` Table
Flexible key-value metadata storage:

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT | Primary key |
| `ref_parent` | INT | Foreign key to `contacts.id` |
| `meta_key` | STRING | Metadata field name |
| `meta_value` | TEXT | Metadata field value |
| `status` | ENUM | Status: active/inactive (default: active) |
| `created_by` | INT | User ID who created record |
| `updated_by` | INT | User ID who last updated record |
| `created_at` | TIMESTAMP | Record creation timestamp |
| `updated_at` | TIMESTAMP | Record update timestamp |

### Model Relationships

```
Contact (1) ---- (N) ContactMeta
  ↓
  └─ ref_parent links each meta entry to parent contact
```

**Eloquent Relationships:**
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

### Key Files and Responsibilities

| File | Purpose |
|------|---------|
| `src/Models/Contact.php` | Eloquent model with relationships and accessors/mutators |
| `src/Models/ContactMeta.php` | Eloquent model for flexible metadata storage |
| `src/Http/Controllers/ContactController.php` | RESTful endpoints (index, show, store, update, destroy) |
| `src/Services/ContactService.php` | Business logic for contact operations |
| `database/migrations/*_create_contacts_table.php` | Table creation and schema |
| `resources/views/contacts/` | Blade templates for UI rendering |

### API Endpoints

```
GET    /contacts              # List all contacts
GET    /contacts/{id}         # Get specific contact
POST   /contacts              # Create new contact
PUT    /contacts/{id}         # Update contact
DELETE /contacts/{id}         # Delete contact

GET    /contacts/{id}/metas   # Get contact metadata
POST   /contacts/{id}/metas   # Add metadata
PUT    /contacts/{id}/metas/{metaId}   # Update metadata
DELETE /contacts/{id}/metas/{metaId}   # Delete metadata
```

### Events and Observability

Events emitted throughout the contact lifecycle:

| Event | When Triggered | Data Provided |
|-------|---|---|
| `ContactCreatedEvent` | New contact created | Contact instance, created_by user |
| `ContactUpdatedEvent` | Contact fields updated | Contact instance, changes, updated_by user |
| `ContactDeletedEvent` | Contact deleted | Contact instance, deleted_by user |

**Usage Example:**
```php
// Listen for events in your listeners
class SendContactWelcomeEmail implements ShouldQueue {
    public function handle(ContactCreatedEvent $event) {
        Mail::send(new ContactWelcome($event->contact));
    }
}
```

### Authorization and Security

#### Access Control
- All endpoints require authenticated user
- Contact operations restricted to authorized roles
- Use Laravel's authorization policies:
  ```php
  // In ContactPolicy
  public function view(User $user, Contact $contact) {
      return $user->can('view_contacts');
  }
  ```

#### Security Features
- CSRF protection on all state-changing operations
- SQL injection prevention via Eloquent ORM
- XSS protection in Blade templates
- Rate limiting on API endpoints
- Input validation on all create/update operations

#### Audit Trail
- All contact changes logged with user attribution
- `created_by` and `updated_by` fields track user actions
- Timestamps maintain chronological record
- Enables compliance and debugging

### Testing Strategy

#### Unit Tests
- Contact model instantiation and validation
- Relationship loading and eager loading
- Accessor/mutator functionality
- Status lifecycle transitions

#### Feature Tests
- Complete CRUD operation flows
- Metadata add/edit/delete workflows
- Unauthorized access rejection
- Event firing verification

#### Integration Tests
- Multi-table transactions
- Event listener execution
- API response formats
- Status code validation

#### Negative Tests
- Unauthorized access attempts
- Invalid data validation
- Permission boundary testing
- Concurrent update handling

**Running Tests:**
```bash
php artisan test tests/Feature/ContactFeatureTest.php
php artisan test tests/Unit/ContactModelTest.php
```

### Development Guidelines

#### Adding New Metadata Fields
1. No migration needed - use existing `contact_metas` table
2. Simply add entries via API:
   ```php
   $contact->metas()->create([
       'meta_key' => 'department',
       'meta_value' => 'Sales',
       'created_by' => auth()->id()
   ]);
   ```

#### Extending Contact Model
```php
class Contact extends Model {
    // Add custom scopes
    public function scopeActive($query) {
        return $query->where('status', 'active');
    }
    
    // Add custom accessors
    public function getFullNameAttribute() {
        return "{$this->name} ({$this->identifier})";
    }
}
```

#### Creating Custom Observers
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

### Logging

Rich logging is added throughout for debugging:

```php
Log::info('Contact created', [
    'contact_id' => $contact->id,
    'contact_uid' => $contact->uid,
    'created_by' => $contact->created_by,
    'identifier' => $contact->identifier
]);

Log::error('Failed to create contact', [
    'error' => $exception->getMessage(),
    'user_id' => auth()->id()
]);
```

### Performance Considerations

#### Database Optimization
- Use eager loading to prevent N+1 queries:
  ```php
  $contacts = Contact::with('metas')->get();
  ```
- Index frequently queried columns: `uid`, `identifier`, `status`
- Paginate large result sets:
  ```php
  $contacts = Contact::paginate(15);
  ```

#### Caching Strategy
- Cache contact lookups by UID
- Invalidate cache on contact update
- Consider Redis for high-traffic scenarios

### Future Enhancements
- Advanced search and filtering
- Bulk operations (import/export)
- Contact grouping and segmentation
- Integration with external CRM systems
- GraphQL API endpoint support