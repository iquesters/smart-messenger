<?php

namespace Iquesters\SmartMessenger\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\View\View;

class ContactPageController extends Controller
{
    /**
     * Display the contacts page
     */
    public function index(): View
    {
        return view('smartmessenger::contacts.index');
    }
}