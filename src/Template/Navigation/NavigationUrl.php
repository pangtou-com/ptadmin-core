<?php

declare(strict_types=1);

namespace PTAdmin\Template\Navigation;

final class NavigationUrl
{
    public static function safe(string $url): string
    {
        return preg_match('#^(?:https?://|/(?!/))#i', $url) && !preg_match('/[\x00-\x20"\']/', $url) ? $url : '';
    }
}
