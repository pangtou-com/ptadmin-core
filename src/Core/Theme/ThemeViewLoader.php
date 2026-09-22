<?php

declare(strict_types=1);

namespace PTAdmin\Core\Theme;

use Illuminate\View\FileViewFinder;

final class ThemeViewLoader
{
    public function __construct(private FileViewFinder $finder)
    {
    }

    public function load(?ThemeDescriptor $theme): void
    {
        $this->finder->setPaths($theme === null ? [] : [$theme->directory]);
        $this->finder->flush();
    }
}
