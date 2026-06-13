<?php

namespace Iquesters\SmartMessenger\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Iquesters\SmartMessenger\Models\FaqItem;
use Iquesters\Integration\Models\Integration;

class FaqController extends Controller
{
    public function index(Request $request)
    {
        $query = FaqItem::with('integration')->orderBy('sort_order');

        if ($request->filled('integration_id')) {
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
        $integrations = Integration::orderBy('name')->get();

        return view('smartmessenger::faq.index', compact('faqs', 'integrations'));
    }

    public function create()
    {
        $integrations = Integration::orderBy('name')->get();
        return view('smartmessenger::faq.form', compact('integrations'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'integration_id' => 'required|integer',
            'question'       => 'required|string',
            'answer'         => 'required|string',
            'status'         => 'in:active,inactive',
            'sort_order'     => 'integer|min:0',
        ]);

        FaqItem::create($validated);

        return redirect()->route('faq.index')->with('success', 'FAQ item created successfully.');
    }

    public function edit(FaqItem $faq)
    {
        $integrations = Integration::orderBy('name')->get();
        return view('smartmessenger::faq.form', compact('faq', 'integrations'));
    }

    public function update(Request $request, FaqItem $faq)
    {
        $validated = $request->validate([
            'integration_id' => 'required|integer',
            'question'       => 'required|string',
            'answer'         => 'required|string',
            'status'         => 'in:active,inactive',
            'sort_order'     => 'integer|min:0',
        ]);

        $faq->update($validated);

        return redirect()->route('faq.index')->with('success', 'FAQ item updated successfully.');
    }

    public function destroy(FaqItem $faq)
    {
        $faq->delete();
        return redirect()->route('faq.index')->with('success', 'FAQ item deleted.');
    }
}