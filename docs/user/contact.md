# Contact Module — User Guide

## Table of Contents
- #overview
- what-is-the-contact-module
- core-features
- #how-to-use
- #folder-structure

---

## Overview

The Contact Module enables you to manage all your business contacts in a centralized system within the Smart Messenger application. You can store various types of contacts (customers, leads, team members) and associate custom information with each contact.

---

## What is the Contact Module?

The Contact Module allows you to:
- Store and organize all your business contacts in one place
- Track contact status and history
- Add unlimited custom information to each contact
- Search and filter contacts easily

---

## Core Features

### 1. Contact Management
- Create and organize contacts with essential information (name, identifier like phone/email)
- Track contact status (active, inactive, archived)
- Search and filter contacts easily
- Attach custom metadata to each contact for personalized information

### 2. Contact Information
Each contact stores:
- **Name** — Display name of the contact
- **Identifier** — Unique reference (phone number, email, WhatsApp ID, etc.)
- **Status** — Current state (Active/Inactive)
- **Custom Metadata** — Add unlimited custom fields like department, tags, preferences, etc.

### 3. Audit Trail
- See who created or last updated each contact
- Track creation and modification timestamps
- Maintain accountability for all contact changes

---

## How to Use

### Creating a Contact
1. Navigate to Contacts section
2. Click "New Contact"
3. Enter contact name and identifier (phone/email)
4. Set status to Active
5. Add any custom metadata fields
6. Save

### Managing Contact Metadata
- Store custom information like: service preferences, purchase history, communication tags, special notes
- Add multiple metadata entries per contact
- Update or remove metadata as needed
- Use metadata for filtering and segmentation

### Viewing Contacts
- View all contacts in the contacts list
- Click on a contact to view detailed information including all associated metadata
- Filter by status or search by name/identifier

---

## Folder Structure

```
smart-messenger/
├── docs/
│   ├── user/
│   │   └── contact.md
│   └── developer/
│       └── contact.md
├── resources/
│   └── views/
│       └── contacts/
│           ├── index.blade.php

```