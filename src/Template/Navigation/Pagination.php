<?php

declare(strict_types=1);

namespace PTAdmin\Template\Navigation;

use InvalidArgumentException;

/** 列表生产者登记分页结果，业务地址回调只在当前渲染内使用。 */
final class Pagination
{
    public function remember(array $result, array $context, string $id = 'default', ?callable $url = null): void
    {
        if (!isset($context['__ptadmin_render_state'])) {
            throw new InvalidArgumentException('登记分页结果必须处于模板渲染上下文中。');
        }
        $this->validate($result);
        $context['__ptadmin_render_state']['template.pagination'][$id] = [
            'data' => array_intersect_key($result, array_flip(['total', 'per_page', 'current_page', 'last_page'])), 'url' => $url,
        ];
        $context['__ptadmin_render_state']['template.last_pagination'] = $id;
    }

    public function data(array $parameters, array $context = []): array
    {
        $parameters = $this->aliases($parameters);
        $state = $context['__ptadmin_render_state'] ?? [];
        $id = $parameters['page_id'] ?? $state['template.last_pagination'] ?? 'default';
        $saved = $state['template.pagination'][$id] ?? null;
        $source = $parameters['data'] ?? $saved['data'] ?? $context['pagination'] ?? [];
        if (!is_array($source)) {
            throw new InvalidArgumentException('分页 data 必须是数组。');
        }
        if ($source === [] && !isset($parameters['total'])) {
            throw new InvalidArgumentException('分页指令缺少对应列表或分页数据：'.$id);
        }
        $values = [
            'total' => $parameters['total'] ?? $source['total'] ?? null,
            'per_page' => $parameters['per_page'] ?? $parameters['limit'] ?? $source['per_page'] ?? 15,
            'current_page' => $parameters['current_page'] ?? $parameters['page'] ?? $source['current_page'] ?? 1,
        ];
        $this->validate($values);
        $total = $values['total'];
        $limit = $values['per_page'];
        $page = $values['current_page'];
        $last = $parameters['last_page'] ?? (array_intersect_key($parameters, array_flip(['total', 'per_page', 'limit'])) === [] ? ($source['last_page'] ?? null) : null)
            ?? max(1, (int) ceil($total / $limit));
        $this->validate($values + ['last_page' => $last]);
        $url = $saved['url'] ?? fn (int $number): string => $this->pageUrl($number, $id, $context);
        $window = $parameters['window'] ?? 2;
        $pages = [];
        for ($number = max(1, $page - $window); $number <= min($last, $page + $window); $number++) {
            $pages[] = ['number' => $number, 'url' => NavigationUrl::safe($url($number)), 'current' => $number === $page];
        }
        return $values + ['last_page' => $last, 'page_id' => $id, 'pages' => $pages,
            'has_previous' => $page > 1, 'has_next' => $page < $last,
            'first_url' => NavigationUrl::safe($url(1)), 'last_url' => NavigationUrl::safe($url($last)),
            'previous_url' => $page > 1 ? NavigationUrl::safe($url(min($last, $page - 1))) : '',
            'next_url' => $page < $last ? NavigationUrl::safe($url($page + 1)) : ''];
    }

    public function html(array $parameters, array $context = []): string
    {
        $pager = $this->data($parameters, $context);
        if ($pager['last_page'] <= 1 && !($parameters['show_single'] ?? false)) {
            return '';
        }
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $link = static fn (string $url, string $label): string => $url === '' ? '' : '<a href="'.$escape($url).'">'.$escape($label).'</a>';
        $parts = ['total' => ($parameters['show_total'] ?? true) ? '<span>共 '.$pager['total'].' 条</span>' : '',
            'index' => ($parameters['show_edges'] ?? true) ? $link($pager['first_url'], '首页') : '',
            'pre' => $link($pager['previous_url'], '上一页'), 'pages' => '',
            'next' => $link($pager['next_url'], '下一页'), 'end' => ($parameters['show_edges'] ?? true) ? $link($pager['last_url'], '末页') : ''];
        foreach ($pager['pages'] as $page) {
            $parts['pages'] .= $page['current'] ? '<span aria-current="page">'.$page['number'].'</span>' : $link($page['url'], (string) $page['number']);
        }
        $layout = match ($parameters['layout'] ?? 'default') {
            'default' => 'total,index,pre,pages,next,end', 'simple' => 'pre,next', 'compact' => 'pre,pages,next',
            default => $parameters['layout'],
        };
        $html = '';
        foreach (explode(',', $layout) as $part) {
            if (!array_key_exists($part, $parts)) {
                throw new InvalidArgumentException('未知分页布局片段：'.$part);
            }
            $html .= $parts[$part];
        }
        return '<nav aria-label="分页" class="pt-pager pt-pager-'.$escape($parameters['theme'] ?? 'default').' '.$escape($parameters['class'] ?? '').'">'.$html.'</nav>';
    }

    private function validate(array $values): void
    {
        foreach (['total' => 0, 'per_page' => 1, 'current_page' => 1] + (isset($values['last_page']) ? ['last_page' => 1] : []) as $field => $minimum) {
            if (!isset($values[$field]) || !is_int($values[$field]) || $values[$field] < $minimum) {
                throw new InvalidArgumentException('分页 '.$field.' 必须是大于等于 '.$minimum.' 的整数。');
            }
        }
    }

    private function aliases(array $parameters): array
    {
        foreach (['pagination_id', 'pager'] as $alias) {
            if (isset($parameters[$alias])) {
                if (isset($parameters['page_id']) && $parameters['page_id'] !== $parameters[$alias]) {
                    throw new InvalidArgumentException('分页参数冲突：page_id / '.$alias);
                }
                $parameters['page_id'] = $parameters[$alias];
            }
        }
        return $parameters;
    }

    private function pageUrl(int $page, string $id, array $context): string
    {
        $query = $context['query'] ?? [];
        if ($id === 'default') {
            $query['page'] = $page;
        } else {
            $query['pages'][$id] = $page;
        }
        return ($context['current_path'] ?? '/').'?'.http_build_query($query);
    }
}
