<?php

namespace Iquesters\SmartMessenger\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Iquesters\SmartMessenger\Models\FaqItem;
use Iquesters\Integration\Models\Integration;

class FaqController extends Controller
{
    public function index(Request $request)
    {
        $accessibleIds = $this->getAccessibleIntegrationIds();

        $query = FaqItem::with('integration')
            ->whereIn('integration_id', $accessibleIds)
            ->orderBy('sort_order');

        if ($request->filled('integration_id')) {
            if (! in_array((int) $request->integration_id, $accessibleIds, true)) {
                abort(403);
            }
            $query->where('integration_id', $request->integration_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('question', 'like', "%{$search}%")
                  ->orWhere('answer', 'like', "%{$search}%");
            });
        }

        $faqs = $query->paginate(25)->withQueryString();
        $integrations = $this->getAccessibleIntegrations();

        return view('smartmessenger::faq.index', compact('faqs', 'integrations'));
    }

    public function create()
    {
        $integrations = $this->getAccessibleIntegrations();
        return view('smartmessenger::faq.form', compact('integrations'));
    }

    public function store(Request $request)
    {
        $accessibleIds = $this->getAccessibleIntegrationIds();

        $validated = $request->validate([
            'integration_id' => 'required|integer',
            'question'       => 'required|string',
            'answer'         => 'required|string',
            'status'         => 'in:active,inactive',
            'sort_order'     => 'integer|min:0',
        ]);

        if (! in_array((int) $validated['integration_id'], $accessibleIds, true)) {
            abort(403);
        }

        FaqItem::create($validated);

        return redirect()->route('faq.index')->with('success', 'FAQ item created successfully.');
    }

    public function edit(FaqItem $faq)
    {
        $this->authorizeFaqAccess($faq);

        $integrations = $this->getAccessibleIntegrations();
        return view('smartmessenger::faq.form', compact('faq', 'integrations'));
    }

    public function update(Request $request, FaqItem $faq)
    {
        $this->authorizeFaqAccess($faq);

        $accessibleIds = $this->getAccessibleIntegrationIds();

        $validated = $request->validate([
            'integration_id' => 'required|integer',
            'question'       => 'required|string',
            'answer'         => 'required|string',
            'status'         => 'in:active,inactive',
            'sort_order'     => 'integer|min:0',
        ]);

        if (! in_array((int) $validated['integration_id'], $accessibleIds, true)) {
            abort(403);
        }

        $faq->update($validated);

        return redirect()->route('faq.index')->with('success', 'FAQ item updated successfully.');
    }

    public function destroy(FaqItem $faq)
    {
        $this->authorizeFaqAccess($faq);

        $faq->delete();
        return redirect()->route('faq.index')->with('success', 'FAQ item deleted.');
    }

    private function authorizeFaqAccess(FaqItem $faq): void
    {
        $accessibleIds = $this->getAccessibleIntegrationIds();

        if (! in_array((int) $faq->integration_id, $accessibleIds, true)) {
            abort(403);
        }
    }

    private function getAccessibleIntegrationIds(): array
    {
        return $this->getAccessibleIntegrations()->pluck('id')->toArray();
    }

    private function getAccessibleIntegrations()
    {
        $user = Auth::user();
        $organisationIds = collect();

        if ($user && method_exists($user, 'organisations')) {
            $organisationIds = $user->organisations()->pluck('organisations.id');
        }

        if ($organisationIds->isEmpty() || ! method_exists(Integration::class, 'organisations')) {
            return collect();
        }

        return Integration::query()
            ->whereHas('organisations', function ($q) use ($organisationIds) {
                $q->whereIn('organisations.id', $organisationIds);
            })
            ->orderBy('name')
            ->get();
    }
}
