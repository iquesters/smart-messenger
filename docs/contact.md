# Contact Module Documentation

## For Users
The Contact module allows you to manage your contacts within the Smart Messenger application.

### What you can do:
- Add new contacts
- View and manage existing contacts
- Track contact status (active/inactive)
- Store additional information about contacts (meta data)

---

## For Developers

### Overview
The `contact` module is part of the `iquesters/smart-messenger` package.
It provides a contact management system for storing and managing contacts and their associated meta data.

### Folder Structure
```
smart-messenger/
├── docs/
│   └── contact.md
├── src/
│   ├── Config/
│   ├── Console/
│   ├── Constants/
│   ├── Events/
│   ├── Http/
│   ├── Jobs/
│   ├── Listeners/
│   ├── Models/
│   ├── Services/
│   ├── Support/
│   └── SmartMessengerServiceProvider.php
├── resources/
│   └── views/
│       ├── channels/
│       │   ├── form.blade.php
│       │   └── index.blade.php
│       ├── contacts/
│       │   └── index.blade.php
│       └── messages/
│           ├── index.blade.php
│           └── partials/
├── database/
│   └── migrations/
│       └── *_create_contacts_table.php
└── tests/
```

### Purpose
- Contact creation and management
- Contact meta data storage
- Contact status tracking

### Database Schema

#### contacts table
- `id` — primary key
- `uid` — unique identifier (ulid format)
- `name` — name of the contact
- `identifier` — unique identifier (email/phone etc.)
- `status` — current status (default: active)
- `created_by` / `updated_by` — audit user tracking
- `created_at` / `updated_at` — audit timestamps

#### contact_metas table
- `id` — primary key
- `ref_parent` — foreign key referencing `contacts.id`
- `meta_key` — name of the meta field
- `meta_value` — value of the meta field
- `status` — current status (default: active)
- `created_by` / `updated_by` — audit user tracking
- `created_at` / `updated_at` — audit timestamps

### Key Files
- `database/migrations/*_create_contacts_table.php`

### Authorization and Security
- All endpoints require an authenticated user
- Contact operations are restricted to authorized roles only
- All actions are logged for audit and observability purposes

### Audit and Observability
Events captured:
- Contact create / update / delete
- Unauthorized access attempts

### Test Strategy
- Unit tests for contact creation and validation
- Integration tests for contact CRUD operations
- Negative tests for unauthorized access attempts
- Tests for meta data handling