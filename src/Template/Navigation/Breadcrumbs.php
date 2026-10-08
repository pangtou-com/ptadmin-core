<?php

declare(strict_types=1);

namespace PTAdmin\Template\Navigation;

use InvalidArgumentException;

final class Breadcrumbs
{
    public function data(array $parameters, array $context = []): array
    {
        $explicit = array_key_exists('items', $parameters);
        $items = $explicit ? $parameters['items'] : (($parameters['current'] ?? true) ? ($context['breadcrumbs'] ?? []) : []);
        if (!is_array($items) || !array_is_list($items)) {
            throw new InvalidArgumentException('面包屑 items 必须是列表。');
        }
        if ($parameters['show_home'] ?? !$explicit) {
            array_unshift($items, ['title' => $parameters['home'] ?? '首页', 'url' => $parameters['home_url'] ?? '/']);
        }
        foreach ($items as $index => &$item) {
            if (!is_array($item) || !is_string($item['title'] ?? $item['name'] ?? null) || !is_string($item['url'] ?? '')) {
                throw new InvalidArgumentException('面包屑第 '.($index + 1).' 项必须包含文字 title 和地址 url。');
            }
            if (isset($item['is_current']) && !is_bool($item['is_current'])) {
                throw new InvalidArgumentException('面包屑 is_current 必须为布尔值。');
            }
            $item['title'] = $item['title'] ?? $item['name'];
            $item['url'] = NavigationUrl::safe($item['url'] ?? '');
            $item['is_current'] ??= $index === count($items) - 1;
        }
        unset($item);
        return $items;
    }

    public function html(array $parameters, array $context = []): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $links = [];
        foreach ($this->data($parameters, $context) as $item) {
            $title = $escape($item['title']);
            if ($item['is_current']) {
                $links[] = '<span aria-current="page">'.$title.'</span>';
            } elseif ($item['url'] === '') {
                $links[] = '<span>'.$title.'</span>';
            } else {
                $links[] = '<a href="'.$escape($item['url']).'">'.$title.'</a>';
            }
        }
        return '<nav aria-label="面包屑" class="'.$escape($parameters['class'] ?? 'pt-crumb').'">'
            .implode('<span aria-hidden="true">'.$escape($parameters['separator'] ?? '/').'</span>', $links).'</nav>';
    }
}
