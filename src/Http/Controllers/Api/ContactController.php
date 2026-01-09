<?php

namespace Iquesters\SmartMessenger\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Iquesters\SmartMessenger\Services\ContactService;

class ContactController extends Controller
{
    protected $contactService;

    /**
     * Constructor - Laravel auto-injects ContactService
     */
    public function __construct(ContactService $contactService)
    {
        $this->contactService = $contactService;
    }

    /**
     * Get all contacts linked to authenticated user's messaging profiles
     */
    public function index(): JsonResponse
    {
        try {
            $userId = auth()->id();

            Log::info('Fetching contacts', ['user_id' => $userId]);

            $contacts = $this->contactService->getUserContacts($userId);

            return response()->json([
                'success' => true,
                'data' => $contacts,
                'meta' => [
                    'total' => $contacts->count(),
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('Failed to fetch contacts', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load contacts',
                'error' => app()->environment('local') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Create a new contact
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $userId = auth()->id();

            $contact = $this->contactService->createContact(
                $request->all(),
                $userId
            );

            return response()->json([
                'success' => true,
                'message' => 'Contact created successfully',
                'data' => $contact,
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'errors' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update contact
     */
    public function update(Request $request, string $uid): JsonResponse
    {
        try {
            $contact = $this->contactService->updateContact(
                $uid,
                $request->all(),
                auth()->id()
            );

            return response()->json([
                'success' => true,
                'data' => $contact,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}