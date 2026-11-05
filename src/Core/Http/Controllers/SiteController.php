<?php

declare(strict_types=1);

namespace PTAdmin\Core\Http\Controllers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;

final class SiteController extends Controller
{
    public function __construct(private Application $app)
    {
    }

    public function index(): View
    {
        return $this->app->make('view')->first(['index']);
    }
}
