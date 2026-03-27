# Contact Module Documentation

## Overview

The `contact` module is part of the `iquesters/smart-messenger` package.
It provides a contact management system for storing and managing contacts
and their associated meta data.

## Purpose

- contact creation and management
- contact meta data storage
- contact status tracking

## Database Schema

### contacts table

Stores the core contact information:

- `id` — primary key
- `uid` — unique identifier (ulid format)
- `name` — name of the contact
- `identifier` — unique identifier for the contact (email/phone etc.)
- `status` — current status (default: active)
- `created_by` — ID of the user who created the record
- `updated_by` — ID of the user who last updated the record
- `created_at` / `updated_at` — audit timestamps

### contact_metas table

Stores additional meta data for each contact:

- `id` — primary key
- `ref_parent` — foreign key referencing `contacts.id`
- `meta_key` — name of the meta field
- `meta_value` — value of the meta field
- `status` — current status (default: active)
- `created_by` — ID of the user who created the record
- `updated_by` — ID of the user who last updated the record
- `created_at` / `updated_at` — audit timestamps

## Key Files

- `database/migrations/*_create_contacts_table.php`

## Authorization and Security

- All endpoints require an authenticated user
- Contact operations are restricted to authorized roles only
- All actions are logged for audit and observability purposes

## Audit and Observability

Events captured:

- contact create/update/delete
- unauthorized access attempts

## Test Strategy

- Unit tests for contact creation and validation
- Integration tests for contact CRUD operations
- Negative tests for unauthorized access attempts
- Tests for meta data handling