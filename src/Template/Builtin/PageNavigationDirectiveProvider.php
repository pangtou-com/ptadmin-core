<?php

declare(strict_types=1);

namespace PTAdmin\Template\Builtin;

use PTAdmin\Template\Directive\DirectiveRegistration;
use PTAdmin\Template\Navigation\Breadcrumbs;
use PTAdmin\Template\Navigation\Pagination;

final class PageNavigationDirectiveProvider
{
    public function __construct(private readonly Breadcrumbs $breadcrumbs = new Breadcrumbs(), private readonly Pagination $pagination = new Pagination())
    {
    }

    public function register(DirectiveRegistration $registration): void
    {
        foreach (['crumb', 'crumb_data', 'pager', 'pager_data'] as $name) {
            $breadcrumb = str_starts_with($name, 'crumb');
            $handler = $breadcrumb ? $this->breadcrumbs : $this->pagination;
            $builder = $registration->define($name)->alias($name)->description($breadcrumb ? '通用面包屑。' : '通用分页。');
            if (str_ends_with($name, '_data')) {
                $builder->loop(static fn (array $arguments, array $runtime): array => $breadcrumb
                    ? $handler->data($arguments, $runtime['context']) : [$handler->data($arguments, $runtime['context'])]);
                $builder->parameter('empty', type: 'string', description: '没有结果时输出的普通文字。');
            } else {
                $builder->output([$handler, 'html'])->safeHtml();
            }
            $parameters = $breadcrumb ? [
                'items' => ['array', '面包屑项目列表，每项包含 title/url，可指定 is_current。'],
                'current' => ['boolean', '是否读取当前页面的 breadcrumbs 上下文，默认 true。'],
                'show_home' => ['boolean', '是否添加首页，显式 items 默认不添加。'],
                'home' => ['string', '首页文字，默认首页。'], 'home_url' => ['string', '首页地址，默认 /。'],
                'separator' => ['string', '项目之间的分隔文字，默认 /。'], 'class' => ['string', '面包屑 CSS 类名，默认 pt-crumb。'],
            ] : [
                'data' => ['array', '显式分页数据，包含 total/per_page/current_page，可指定 last_page。'],
                'page_id' => ['string', '指定列表分页名称。'], 'pagination_id' => ['string', 'page_id 的兼容名称。'], 'pager' => ['string', 'page_id 的兼容名称。'],
                'total' => ['integer', '记录总数。', 0], 'current_page' => ['integer', '当前页码。', 1], 'page' => ['integer', '当前页码。', 1],
                'per_page' => ['integer', '每页条数。', 1], 'limit' => ['integer', '每页条数。', 1], 'last_page' => ['integer', '末页页码。', 1],
                'window' => ['integer', '当前页两侧的页码数量，默认 2。', 0, 20],
                'layout' => ['string', 'default/simple/compact 或分页片段组合。'], 'theme' => ['string', '分页样式主题。'],
                'show_total' => ['boolean', '显示记录总数，默认 true。'], 'show_edges' => ['boolean', '显示首末页，默认 true。'],
                'show_single' => ['boolean', '只有一页时显示分页，默认 false。'], 'class' => ['string', '附加分页 CSS 类名。'],
            ];
            foreach ($parameters as $parameter => $spec) {
                $builder->parameter($parameter, type: $spec[0], description: $spec[1], min: $spec[2] ?? null, max: $spec[3] ?? null,
                    enum: $parameter === 'theme' ? ['default', 'minimal'] : null);
            }
            $builder->commit();
        }
    }
}
