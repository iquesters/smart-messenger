<?php

namespace Iquesters\SmartMessenger\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Routing\Controller;

class TestChatbotController extends Controller
{
    public function handle(Request $request)
    {
        set_time_limit(0);
        // Simulate long processing (40 seconds)
        sleep(100);

        return response()->json([
            'session_id' => (string) Str::uuid(),
            'directives' => [],
            'messages' => [
                [
                    'id' => 'msg-001',
                    'type' => 'text',
                    'content' => [
                        'text' => 'Here are some options.'
                    ]
                ],
                [
                    'id' => 'msg-002',
                    'type' => 'product',
                    'content' => [
                        'title' => 'Rosewood Guitar Plectrum 3mm – Natural Wooden Pick',
                        'sku' => 'SKU123',
                        'product_id' => 123,
                        'price' => 100,
                        'currency' => 'INR',
                        'link' => 'https://gigigadgets.com/product/rosewood-guitar-plectrum-3mm-natural-wooden-pick/',
                        'image_url' => 'https://gigigadgets.com/wp-content/uploads/2026/01/cropped-PU-1289.jpg',
                        'caption' => null
                    ]
                ],
                [
                    'id' => 'msg-003',
                    'type' => 'text',
                    'reply_to' => 'msg-002',
                    'content' => [
                        'text' => 'Here’s more info about that product.'
                    ]
                ],
                [
                    'id' => 'msg-004',
                    'type' => 'product',
                    'content' => [
                        'title' => 'MG Series Potentiometer Knob Golden-Black Metal Crown',
                        'sku' => 'SKU1234',
                        'product_id' => 124,
                        'price' => 10,
                        'currency' => 'INR',
                        'link' => 'https://gigigadgets.com/product/mg-series-potentiometer-knob-golden-black-metal-crown/',
                        'image_url' => 'https://gigigadgets.com/wp-content/uploads/2025/12/Golden-Black-Knob.jpg',
                        'caption' => null
                    ]
                ],
            ]
        ]);
    }
}