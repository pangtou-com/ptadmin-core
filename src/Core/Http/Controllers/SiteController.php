<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

final class SiteController extends Controller
{
    public function index(): View
    {
        return view('index');
    }
}
