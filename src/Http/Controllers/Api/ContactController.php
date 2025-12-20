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
     *
     * @return JsonResponse
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
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $userId = auth()->id();
            
            Log::info('Creating contact', [
                'user_id' => $userId,
                'data' => $request->only(['name', 'identifier', 'messaging_profile_id'])
            ]);

            $contact = $this->contactService->createContact(
                $request->all(),
                $userId
            );

            return response()->json([
                'success' => true,
                'message' => 'Contact created successfully',
                'data' => [
                    'id' => $contact->id,
                    'uid' => $contact->uid,
                    'name' => $contact->name,
                    'identifier' => $contact->identifier,
                    'status' => $contact->status,
                    'created_at' => $contact->created_at?->toIso8601String(),
                    'updated_at' => $contact->updated_at?->toIso8601String(),
                ]
            ], 201);

        } catch (ValidationException $e) {
            Log::warning('Contact validation failed', [
                'user_id' => auth()->id(),
                'errors' => $e->errors()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Throwable $e) {
            Log::error('Failed to create contact', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Update a contact (name only)
     *
     * @param Request $request
     * @param string $uid
     * @return JsonResponse
     */
    public function update(Request $request, string $uid): JsonResponse
    {
        try {
            $userId = auth()->id();
            
            Log::info('Updating contact', [
                'user_id' => $userId,
                'contact_uid' => $uid,
                'data' => $request->only(['name'])
            ]);

            $contact = $this->contactService->updateContact(
                $uid,
                $request->all(),
                $userId
            );

            return response()->json([
                'success' => true,
                'message' => 'Contact updated successfully',
                'data' => [
                    'id' => $contact->id,
                    'uid' => $contact->uid,
                    'name' => $contact->name,
                    'identifier' => $contact->identifier,
                    'status' => $contact->status,
                    'created_at' => $contact->created_at?->toIso8601String(),
                    'updated_at' => $contact->updated_at?->toIso8601String(),
                ]
            ]);

        } catch (ValidationException $e) {
            Log::warning('Contact update validation failed', [
                'user_id' => auth()->id(),
                'contact_uid' => $uid,
                'errors' => $e->errors()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Throwable $e) {
            Log::error('Failed to update contact', [
                'user_id' => auth()->id(),
                'contact_uid' => $uid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'error' => app()->environment('local') ? $e->getMessage() : null
            ], 500);
        }
    }
}