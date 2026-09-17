<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Schema;

/**
 * Admin 代码资源定义。物理表由领域迁移维护，Schema 只负责字段、视图能力和 Runtime 协议。
 */
final class AdminSchemaDefinitions
{
    /** @return array<string, array{table:string,schema:array<string,mixed>}> */
    public function all(): array
    {
        return [
            'admin.management_users' => [
                'table' => 'admins',
                'schema' => [
                    'name' => 'management_users',
                    'title' => '管理员',
                    'description' => '管理后台登录账号、角色和直接授权。',
                    'features' => ['layout' => 'single'],
                    'capabilities' => ['allow_recycle' => true],
                    'views' => ['forms' => ['default' => [
                        'span' => 12,
                        'gap' => 18,
                        'props' => ['labelPosition' => 'top'],
                        'children' => [
                            ['type' => 'section', 'title' => '账号信息', 'span' => 24, 'children' => [
                                ['type' => 'field', 'field' => 'username'],
                                ['type' => 'field', 'field' => 'nickname'],
                                ['type' => 'field', 'field' => 'email'],
                                ['type' => 'field', 'field' => 'mobile'],
                                ['type' => 'field', 'field' => 'avatar'],
                                ['type' => 'field', 'field' => 'status'],
                                ['type' => 'field', 'field' => 'password', 'component' => 'password'],
                            ]],
                            ['type' => 'section', 'title' => '角色与组织', 'span' => 24, 'children' => [
                                ['type' => 'field', 'field' => 'role_ids'],
                                ['type' => 'field', 'field' => 'organization_relations', 'component' => 'organization-relation-editor', 'span' => 24],
                            ]],
                            ['type' => 'section', 'title' => '直接授权', 'span' => 24, 'children' => [
                                ['type' => 'field', 'field' => 'permissions', 'component' => 'permission-grant-editor', 'span' => 24],
                            ]],
                        ],
                    ]]],
                    'fields' => [
                        $this->string('username', '用户名', true, true, true, true, ['trim' => true], [['type' => 'pattern', 'value' => '^[A-Za-z0-9_.-]{3,100}$', 'message' => '用户名只能包含字母、数字、点、下划线和短横线，长度为 3 到 100 个字符']]),
                        $this->string('nickname', '显示名称', true, true, true, true, [], [['type' => 'max_length', 'value' => 100, 'message' => '显示名称不能超过 100 个字符']]),
                        $this->string('email', '电子邮箱', false, true, true, true, ['empty_as_null' => true], [['type' => 'format', 'value' => 'email', 'message' => '电子邮箱格式不正确'], ['type' => 'max_length', 'value' => 255, 'message' => '电子邮箱不能超过 255 个字符']]),
                        $this->string('mobile', '手机号码', false, true, true, false, ['empty_as_null' => true], [['type' => 'max_length', 'value' => 30, 'message' => '手机号码不能超过 30 个字符']]),
                        $this->string('avatar', '头像地址', false, true, false, false, ['empty_as_null' => true], [['type' => 'max_length', 'value' => 255, 'message' => '头像地址不能超过 255 个字符']]),
                        $this->boolean('status', '账号状态', true),
                        ['name' => 'is_founder', 'type' => 'boolean', 'label' => '创始人', 'persisted' => true, 'readonly' => true, 'formable' => false, 'listable' => true, 'searchable' => true, 'sortable' => true],
                        ['name' => 'login_at', 'type' => 'datetime', 'label' => '最近登录时间', 'persisted' => true, 'readonly' => true, 'formable' => false, 'listable' => true, 'sortable' => true],
                        ['name' => 'login_ip', 'type' => 'string', 'label' => '最近登录 IP', 'persisted' => true, 'readonly' => true, 'formable' => false, 'listable' => true],
                        ['name' => 'password', 'type' => 'string', 'label' => '密码', 'persisted' => false, 'required' => true, 'formable' => true, 'listable' => false, 'extensions' => ['secret' => ['readable' => false]], 'validation' => [['type' => 'complexity', 'params' => ['min_length' => 6, 'max_length' => 4096], 'message' => '密码长度必须为 6 到 4096 个字符']]],
                        ['name' => 'role_ids', 'type' => 'integer', 'label' => '角色', 'persisted' => false, 'multiple' => true, 'formable' => true, 'listable' => false, 'reference' => ['resource' => 'admin.management_roles', 'value_field' => 'id', 'label_field' => 'name']],
                        ['name' => 'organization_relations', 'type' => 'json', 'label' => '组织归属', 'persisted' => false, 'formable' => true, 'listable' => false, 'extensions' => ['component' => 'organization-relation-editor']],
                        ['name' => 'permissions', 'type' => 'json', 'label' => '直接授权', 'persisted' => false, 'formable' => true, 'listable' => false, 'extensions' => ['component' => 'permission-grant-editor']],
                    ],
                    'actions' => [
                        'sync_roles' => ['label' => '设置角色'],
                        'sync_organization_relations' => ['label' => '设置组织归属'],
                        'sync_permissions' => ['label' => '设置直接授权'],
                        'reset_password' => ['label' => '重置密码'],
                        'revoke_sessions' => ['label' => '撤销会话'],
                    ],
                ],
            ],
            'admin.management_roles' => [
                'table' => 'admin_roles',
                'schema' => [
                    'name' => 'management_roles',
                    'title' => '角色权限',
                    'description' => '管理角色及其资源动作授权。',
                    'features' => ['layout' => 'single'],
                    'capabilities' => ['allow_recycle' => true],
                    'views' => ['forms' => ['default' => [
                        'span' => 12,
                        'gap' => 18,
                        'props' => ['labelPosition' => 'top'],
                        'children' => [
                            ['type' => 'section', 'title' => '角色信息', 'span' => 24, 'children' => [
                                ['type' => 'field', 'field' => 'code'],
                                ['type' => 'field', 'field' => 'name'],
                                ['type' => 'field', 'field' => 'description', 'span' => 24],
                                ['type' => 'field', 'field' => 'tenant_id'],
                                ['type' => 'field', 'field' => 'status'],
                                ['type' => 'field', 'field' => 'scope_mode'],
                                ['type' => 'field', 'field' => 'scope_value_json', 'component' => 'authorization-scope-value-editor'],
                                ['type' => 'field', 'field' => 'sort'],
                            ]],
                            ['type' => 'section', 'title' => '资源授权', 'span' => 24, 'children' => [
                                ['type' => 'field', 'field' => 'permissions', 'component' => 'permission-grant-editor', 'span' => 24],
                            ]],
                        ],
                    ]]],
                    'fields' => [
                        $this->string('code', '角色编码', true, true, true, true, ['trim' => true, 'case' => 'lower'], [['type' => 'format', 'value' => 'identifier', 'message' => '角色编码必须是小写标识符'], ['type' => 'max_length', 'value' => 100, 'message' => '角色编码不能超过 100 个字符']]),
                        $this->string('name', '角色名称', true, true, true, true, [], [['type' => 'max_length', 'value' => 100, 'message' => '角色名称不能超过 100 个字符']]),
                        ['name' => 'description', 'type' => 'text', 'label' => '角色说明', 'persisted' => true, 'formable' => true, 'listable' => false],
                        ['name' => 'tenant_id', 'type' => 'integer', 'label' => '所属租户', 'persisted' => true, 'formable' => true, 'listable' => true, 'searchable' => true, 'reference' => ['resource' => 'admin.management_tenants', 'value_field' => 'id', 'label_field' => 'name']],
                        $this->select('scope_mode', '默认数据范围', 'all', [['value' => 'all', 'label' => '全部数据'], ['value' => 'self', 'label' => '仅本人'], ['value' => 'tenant', 'label' => '指定租户'], ['value' => 'organization', 'label' => '指定组织'], ['value' => 'department', 'label' => '指定部门'], ['value' => 'assigned', 'label' => '已分配组织范围']]),
                        ['name' => 'scope_value_json', 'type' => 'json', 'label' => '默认范围参数', 'persisted' => true, 'formable' => true, 'listable' => false, 'extensions' => ['component' => 'authorization-scope-value-editor']],
                        $this->boolean('status', '角色状态', true),
                        ['name' => 'is_system', 'type' => 'boolean', 'label' => '系统角色', 'persisted' => true, 'readonly' => true, 'formable' => false, 'listable' => true, 'searchable' => true, 'sortable' => true],
                        ['name' => 'sort', 'type' => 'integer', 'label' => '排序', 'persisted' => true, 'default' => 0, 'formable' => true, 'listable' => true, 'sortable' => true],
                        ['name' => 'permissions', 'type' => 'json', 'label' => '资源授权', 'persisted' => false, 'formable' => true, 'listable' => false, 'extensions' => ['component' => 'permission-grant-editor']],
                    ],
                    'actions' => ['sync_permissions' => ['label' => '设置权限']],
                ],
            ],
            'admin.management_menus' => [
                'table' => 'admin_resources',
                'schema' => [
                    'name' => 'management_menus',
                    'title' => '菜单与权限资源',
                    'description' => '管理菜单节点和服务端权限资源。',
                    'features' => ['layout' => 'grouped'],
                    'capabilities' => ['allow_recycle' => true],
                    'fields' => [
                        $this->string('resource_key', '资源编码', true, true, true, true, ['trim' => true, 'case' => 'lower'], [['type' => 'pattern', 'value' => '^[a-z][a-z0-9._-]{1,190}$', 'message' => '资源编码格式不正确']]),
                        $this->string('title', '资源名称', true, true, true, true, [], [['type' => 'max_length', 'value' => 150, 'message' => '资源名称不能超过 150 个字符']]),
                        $this->select('type', '资源类型', 'page', [['value' => 'page', 'label' => '页面'], ['value' => 'system', 'label' => '系统权限'], ['value' => 'action', 'label' => '操作权限']]),
                        $this->string('parent_key', '父资源编码', false, true, true, false, ['trim' => true], [['type' => 'max_length', 'value' => 191, 'message' => '父资源编码不能超过 191 个字符']]),
                        $this->string('path', '前端相对路径', false, true, false, false, ['trim' => true], [['type' => 'max_length', 'value' => 255, 'message' => '前端路径不能超过 255 个字符']]),
                        ['name' => 'meta_json', 'type' => 'json', 'label' => '菜单元数据', 'persisted' => true, 'formable' => true, 'listable' => false],
                        $this->boolean('is_menu', '显示为菜单', false),
                        $this->boolean('status', '资源状态', true),
                        ['name' => 'sort', 'type' => 'integer', 'label' => '排序', 'persisted' => true, 'default' => 0, 'formable' => true, 'listable' => true, 'sortable' => true],
                    ],
                ],
            ],
            'admin.login_logs' => [
                'table' => 'admin_login_logs',
                'schema' => [
                    'name' => 'login_logs',
                    'title' => '登录日志',
                    'description' => '只读展示后台登录成功与失败记录。',
                    'features' => ['layout' => 'single', 'read_only' => true],
                    'actions' => ['create' => ['enabled' => false], 'update' => ['enabled' => false], 'delete' => ['enabled' => false]],
                    'fields' => [
                        ['name' => 'admin_id', 'type' => 'integer', 'label' => '管理员 ID', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true, 'sortable' => true],
                        ['name' => 'username', 'type' => 'string', 'label' => '登录用户名', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true, 'sortable' => true],
                        ['name' => 'successful', 'type' => 'boolean', 'label' => '登录成功', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true, 'sortable' => true],
                        ['name' => 'failure_reason', 'type' => 'string', 'label' => '失败原因', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
                        ['name' => 'ip_address', 'type' => 'string', 'label' => 'IP 地址', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
                        ['name' => 'user_agent', 'type' => 'text', 'label' => 'User-Agent', 'persisted' => true, 'readonly' => true, 'listable' => false],
                        $this->readOnlyDateTime('created_at', '登录时间'),
                    ],
                ],
            ],
            'admin.operation_records' => [
                'table' => 'admin_operation_records',
                'schema' => [
                    'name' => 'operation_records',
                    'title' => '操作记录',
                    'description' => '只读展示后台写请求的资源、动作和结果。',
                    'features' => ['layout' => 'single', 'read_only' => true],
                    'actions' => ['create' => ['enabled' => false], 'update' => ['enabled' => false], 'delete' => ['enabled' => false]],
                    'fields' => [
                        ['name' => 'admin_id', 'type' => 'integer', 'label' => '管理员 ID', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true, 'sortable' => true],
                        ['name' => 'request_id', 'type' => 'string', 'label' => '请求 ID', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
                        ['name' => 'resource_key', 'type' => 'string', 'label' => '资源编码', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
                        ['name' => 'action', 'type' => 'string', 'label' => '动作', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
                        ['name' => 'method', 'type' => 'string', 'label' => '请求方法', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
                        ['name' => 'path', 'type' => 'string', 'label' => '请求路径', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
                        ['name' => 'status_code', 'type' => 'integer', 'label' => '响应状态码', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true, 'sortable' => true],
                        ['name' => 'successful', 'type' => 'boolean', 'label' => '操作成功', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true, 'sortable' => true],
                        ['name' => 'ip_address', 'type' => 'string', 'label' => 'IP 地址', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
                        $this->readOnlyDateTime('created_at', '操作时间'),
                    ],
                ],
            ],
            'admin.management_tenants' => $this->tenantDefinition(),
            'admin.management_organizations' => $this->organizationDefinition(),
            'admin.management_departments' => $this->departmentDefinition(),
            'admin.dashboard_widgets' => $this->dashboardWidgetDefinition(),
            'admin.notification_scenes' => $this->notificationSceneDefinition(),
            'admin.notification_templates' => $this->notificationTemplateDefinition(),
            'admin.notification_messages' => $this->notificationMessageDefinition(),
            'admin.notification_receipts' => $this->notificationReceiptDefinition(),
            'admin.notification_deliveries' => $this->notificationDeliveryDefinition(),
        ];
    }

    /** @return array{table:string,schema:array<string,mixed>} */
    private function tenantDefinition(): array
    {
        return [
            'table' => 'admin_tenants',
            'schema' => [
                'name' => 'management_tenants',
                'title' => '租户',
                'description' => '管理后台租户及其全局配置。',
                'features' => ['layout' => 'single'],
                'capabilities' => ['allow_recycle' => true],
                'fields' => [
                    $this->string('code', '租户编码', true, true, true, true, ['trim' => true, 'case' => 'lower'], [['type' => 'format', 'value' => 'identifier', 'message' => '租户编码必须是小写标识符']]),
                    $this->string('name', '租户名称', true, true, true, true),
                    $this->boolean('status', '租户状态', true),
                    ['name' => 'settings_json', 'type' => 'json', 'label' => '租户配置', 'persisted' => true, 'formable' => true, 'listable' => false],
                ],
            ],
        ];
    }

    /** @return array{table:string,schema:array<string,mixed>} */
    private function organizationDefinition(): array
    {
        return [
            'table' => 'admin_organizations',
            'schema' => [
                'name' => 'management_organizations',
                'title' => '组织',
                'description' => '管理租户下的组织树和组织归属。',
                'features' => ['layout' => 'grouped'],
                'tree' => ['enabled' => true, 'parent_field' => 'parent_id', 'order_field' => 'sort'],
                'capabilities' => ['allow_recycle' => true],
                'fields' => [
                    ['name' => 'tenant_id', 'type' => 'integer', 'label' => '租户', 'persisted' => true, 'formable' => true, 'listable' => true, 'searchable' => true, 'reference' => ['resource' => 'admin.management_tenants', 'value_field' => 'id', 'label_field' => 'name']],
                    ['name' => 'parent_id', 'type' => 'integer', 'label' => '父组织', 'persisted' => true, 'default' => 0, 'formable' => true, 'listable' => false, 'reference' => ['resource' => 'admin.management_organizations', 'value_field' => 'id', 'label_field' => 'name', 'parent_field' => 'parent_id']],
                    $this->string('code', '组织编码', true, true, true, true, ['trim' => true, 'case' => 'lower'], [['type' => 'format', 'value' => 'identifier', 'message' => '组织编码必须是小写标识符']]),
                    $this->string('name', '组织名称', true, true, true, true),
                    $this->boolean('status', '组织状态', true),
                    ['name' => 'sort', 'type' => 'integer', 'label' => '排序', 'persisted' => true, 'default' => 0, 'formable' => true, 'listable' => true, 'sortable' => true],
                    ['name' => 'meta_json', 'type' => 'json', 'label' => '扩展信息', 'persisted' => true, 'formable' => true, 'listable' => false],
                ],
            ],
        ];
    }

    /** @return array{table:string,schema:array<string,mixed>} */
    private function departmentDefinition(): array
    {
        return [
            'table' => 'admin_departments',
            'schema' => [
                'name' => 'management_departments',
                'title' => '部门',
                'description' => '管理组织下的部门树和管理员归属范围。',
                'features' => ['layout' => 'grouped'],
                'tree' => ['enabled' => true, 'parent_field' => 'parent_id', 'order_field' => 'sort'],
                'capabilities' => ['allow_recycle' => true],
                'fields' => [
                    ['name' => 'tenant_id', 'type' => 'integer', 'label' => '租户', 'persisted' => true, 'formable' => true, 'listable' => true, 'searchable' => true, 'reference' => ['resource' => 'admin.management_tenants', 'value_field' => 'id', 'label_field' => 'name']],
                    ['name' => 'organization_id', 'type' => 'integer', 'label' => '组织', 'persisted' => true, 'required' => true, 'formable' => true, 'listable' => true, 'searchable' => true, 'reference' => ['resource' => 'admin.management_organizations', 'value_field' => 'id', 'label_field' => 'name']],
                    ['name' => 'parent_id', 'type' => 'integer', 'label' => '父部门', 'persisted' => true, 'default' => 0, 'formable' => true, 'listable' => false, 'reference' => ['resource' => 'admin.management_departments', 'value_field' => 'id', 'label_field' => 'name', 'parent_field' => 'parent_id']],
                    $this->string('code', '部门编码', true, true, true, true, ['trim' => true, 'case' => 'lower'], [['type' => 'format', 'value' => 'identifier', 'message' => '部门编码必须是小写标识符']]),
                    $this->string('name', '部门名称', true, true, true, true),
                    $this->boolean('status', '部门状态', true),
                    ['name' => 'sort', 'type' => 'integer', 'label' => '排序', 'persisted' => true, 'default' => 0, 'formable' => true, 'listable' => true, 'sortable' => true],
                    ['name' => 'meta_json', 'type' => 'json', 'label' => '扩展信息', 'persisted' => true, 'formable' => true, 'listable' => false],
                ],
            ],
        ];
    }

    /** @return array{table:string,schema:array<string,mixed>} */
    private function dashboardWidgetDefinition(): array
    {
        return [
            'table' => 'admin_dashboard_widgets',
            'schema' => [
                'name' => 'dashboard_widgets',
                'title' => '仪表盘组件配置',
                'description' => '管理管理员或角色的仪表盘组件布局。',
                'features' => ['layout' => 'single'],
                'fields' => [
                    $this->select('subject_type', '主体类型', 'admin', [['value' => 'admin', 'label' => '管理员'], ['value' => 'role', 'label' => '角色']]),
                    ['name' => 'subject_id', 'type' => 'integer', 'label' => '主体 ID', 'persisted' => true, 'required' => true, 'formable' => true, 'listable' => true, 'searchable' => true],
                    ['name' => 'tenant_id', 'type' => 'integer', 'label' => '租户 ID', 'persisted' => true, 'formable' => true, 'listable' => true, 'searchable' => true],
                    $this->string('widget_code', '组件编码', true, true, true, true, ['trim' => true]),
                    $this->boolean('enabled', '是否启用', true),
                    ['name' => 'sort', 'type' => 'integer', 'label' => '排序', 'persisted' => true, 'default' => 0, 'formable' => true, 'listable' => true, 'sortable' => true],
                    ['name' => 'layout_json', 'type' => 'json', 'label' => '布局参数', 'persisted' => true, 'formable' => true, 'listable' => false],
                    ['name' => 'config_json', 'type' => 'json', 'label' => '组件配置', 'persisted' => true, 'formable' => true, 'listable' => false],
                ],
            ],
        ];
    }

    /** @return array{table:string,schema:array<string,mixed>} */
    private function notificationSceneDefinition(): array
    {
        return [
            'table' => 'notification_scenes',
            'schema' => [
                'name' => 'notification_scenes',
                'title' => '通知场景',
                'description' => '管理系统和插件通知场景定义。',
                'features' => ['layout' => 'single'],
                'fields' => [
                    $this->select('source_type', '来源类型', 'system', [['value' => 'system', 'label' => '系统'], ['value' => 'addon', 'label' => '插件']]),
                    $this->string('source_code', '来源编码', true, true, true, true, ['trim' => true]),
                    $this->string('group_code', '分组编码', true, true, true, true, ['trim' => true]),
                    $this->string('group_title', '分组名称', true, true, false, false),
                    $this->string('code', '场景编码', true, true, true, true, ['trim' => true, 'case' => 'lower']),
                    $this->string('title', '场景名称', true, true, true, true),
                    ['name' => 'description', 'type' => 'text', 'label' => '场景说明', 'persisted' => true, 'formable' => true, 'listable' => false],
                    $this->select('purpose', '用途类型', 'transactional', [['value' => 'transactional', 'label' => '事务通知'], ['value' => 'verification', 'label' => '验证通知'], ['value' => 'marketing', 'label' => '营销通知'], ['value' => 'security', 'label' => '安全通知']]),
                    ['name' => 'variables', 'type' => 'json', 'label' => '变量定义', 'persisted' => true, 'formable' => true, 'listable' => false],
                    ['name' => 'default_channels', 'type' => 'json', 'label' => '默认渠道', 'persisted' => true, 'formable' => true, 'listable' => false],
                    $this->boolean('enabled', '是否启用', true),
                ],
            ],
        ];
    }

    /** @return array{table:string,schema:array<string,mixed>} */
    private function notificationTemplateDefinition(): array
    {
        return [
            'table' => 'notification_templates',
            'schema' => [
                'name' => 'notification_templates',
                'title' => '通知模板',
                'description' => '管理通知场景的渠道模板和语言版本。',
                'features' => ['layout' => 'single'],
                'fields' => [
                    ['name' => 'scene_id', 'type' => 'integer', 'label' => '通知场景', 'persisted' => true, 'required' => true, 'formable' => true, 'listable' => true, 'searchable' => true, 'reference' => ['resource' => 'admin.notification_scenes', 'value_field' => 'id', 'label_field' => 'title']],
                    $this->string('channel', '渠道', true, true, true, true, ['trim' => true]),
                    $this->string('locale', '语言区域', true, true, true, true, ['trim' => true]),
                    $this->string('mode', '内容模式', true, true, true, true, ['trim' => true]),
                    ['name' => 'config', 'type' => 'json', 'label' => '模板配置', 'persisted' => true, 'formable' => true, 'listable' => false],
                    $this->boolean('customized', '是否自定义', false),
                    $this->boolean('enabled', '是否启用', true),
                ],
            ],
        ];
    }

    /** @return array{table:string,schema:array<string,mixed>} */
    private function notificationMessageDefinition(): array
    {
        return ['table' => 'notification_messages', 'schema' => $this->readOnlySchema('notification_messages', '通知消息', '查询系统产生的通知消息。', [
            ['name' => 'audience_type', 'type' => 'string', 'label' => '接收对象类型', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'source_type', 'type' => 'string', 'label' => '来源类型', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'source_code', 'type' => 'string', 'label' => '来源编码', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'category', 'type' => 'string', 'label' => '消息分类', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'level', 'type' => 'string', 'label' => '消息等级', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'title', 'type' => 'string', 'label' => '标题', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'content', 'type' => 'text', 'label' => '内容', 'persisted' => true, 'readonly' => true, 'listable' => false],
            ['name' => 'action_type', 'type' => 'string', 'label' => '点击动作类型', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'action_url', 'type' => 'string', 'label' => '点击动作地址', 'persisted' => true, 'readonly' => true, 'listable' => false],
            ['name' => 'biz_type', 'type' => 'string', 'label' => '业务类型', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'biz_id', 'type' => 'string', 'label' => '业务标识', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'biz_key', 'type' => 'string', 'label' => '业务幂等键', 'persisted' => true, 'readonly' => true, 'listable' => false, 'searchable' => true],
            ['name' => 'data', 'type' => 'json', 'label' => '扩展数据', 'persisted' => true, 'readonly' => true, 'listable' => false],
            ['name' => 'created_by', 'type' => 'integer', 'label' => '创建主体 ID', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'expires_at', 'type' => 'datetime', 'label' => '过期时间', 'persisted' => true, 'readonly' => true, 'listable' => true, 'sortable' => true],
            $this->readOnlyDateTime('created_at', '创建时间'),
        ])];
    }

    /** @return array{table:string,schema:array<string,mixed>} */
    private function notificationReceiptDefinition(): array
    {
        return ['table' => 'notification_receipts', 'schema' => $this->readOnlySchema('notification_receipts', '通知回执', '查询管理员收到的通知状态。', [
            ['name' => 'notification_id', 'type' => 'integer', 'label' => '通知 ID', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'receiver_type', 'type' => 'string', 'label' => '接收对象类型', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'receiver_id', 'type' => 'integer', 'label' => '接收对象 ID', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'read_at', 'type' => 'datetime', 'label' => '读取时间', 'persisted' => true, 'readonly' => true, 'listable' => true, 'sortable' => true],
            ['name' => 'archived_at', 'type' => 'datetime', 'label' => '归档时间', 'persisted' => true, 'readonly' => true, 'listable' => true, 'sortable' => true],
            $this->readOnlyDateTime('created_at', '接收时间'),
        ])];
    }

    /** @return array{table:string,schema:array<string,mixed>} */
    private function notificationDeliveryDefinition(): array
    {
        return ['table' => 'notification_deliveries', 'schema' => $this->readOnlySchema('notification_deliveries', '通知投递记录', '查询通知渠道的外部投递结果。', [
            ['name' => 'notification_id', 'type' => 'integer', 'label' => '通知 ID', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'receipt_id', 'type' => 'integer', 'label' => '通知回执 ID', 'persisted' => true, 'readonly' => true, 'listable' => false, 'searchable' => true],
            ['name' => 'receiver_type', 'type' => 'string', 'label' => '接收对象类型', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'receiver_id', 'type' => 'integer', 'label' => '接收对象 ID', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'channel', 'type' => 'string', 'label' => '渠道', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'provider', 'type' => 'string', 'label' => '提供者', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'addon_code', 'type' => 'string', 'label' => '插件编码', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'provider_group', 'type' => 'string', 'label' => '提供者分组', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'instance_code', 'type' => 'string', 'label' => '实例编码', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'route_revision', 'type' => 'integer', 'label' => '路由修订号', 'persisted' => true, 'readonly' => true, 'listable' => true, 'sortable' => true],
            ['name' => 'strategy', 'type' => 'string', 'label' => '选择策略', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'message_id', 'type' => 'string', 'label' => '外部消息标识', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'batch_id', 'type' => 'string', 'label' => '外部批次标识', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'status', 'type' => 'string', 'label' => '投递状态', 'persisted' => true, 'readonly' => true, 'listable' => true, 'searchable' => true],
            ['name' => 'accepted_at', 'type' => 'datetime', 'label' => '受理时间', 'persisted' => true, 'readonly' => true, 'listable' => true, 'sortable' => true],
            ['name' => 'delivered_at', 'type' => 'datetime', 'label' => '送达时间', 'persisted' => true, 'readonly' => true, 'listable' => true, 'sortable' => true],
            ['name' => 'error_message', 'type' => 'text', 'label' => '失败原因', 'persisted' => true, 'readonly' => true, 'listable' => false],
            ['name' => 'meta', 'type' => 'json', 'label' => '投递上下文', 'persisted' => true, 'readonly' => true, 'listable' => false],
            ['name' => 'raw', 'type' => 'json', 'label' => '外部原始响应', 'persisted' => true, 'readonly' => true, 'listable' => false],
            $this->readOnlyDateTime('created_at', '创建时间'),
        ])];
    }

    /** @param list<array<string,mixed>> $fields @return array<string,mixed> */
    private function readOnlySchema(string $name, string $title, string $description, array $fields): array
    {
        return [
            'name' => $name,
            'title' => $title,
            'description' => $description,
            'features' => ['layout' => 'single', 'read_only' => true],
            'actions' => ['create' => ['enabled' => false], 'update' => ['enabled' => false], 'delete' => ['enabled' => false]],
            'fields' => $fields,
        ];
    }

    /** @param list<array<string,mixed>> $validation @return array<string,mixed> */
    private function string(string $name, string $label, bool $required, bool $listable, bool $searchable, bool $sortable, array $normalize = [], array $validation = []): array
    {
        return ['name' => $name, 'type' => 'string', 'label' => $label, 'persisted' => true, 'required' => $required, 'formable' => true, 'listable' => $listable, 'searchable' => $searchable, 'sortable' => $sortable, 'normalize' => $normalize, 'validation' => $validation];
    }

    /** @return array<string,mixed> */
    private function boolean(string $name, string $label, bool $default): array
    {
        return ['name' => $name, 'type' => 'boolean', 'label' => $label, 'persisted' => true, 'default' => $default, 'formable' => true, 'listable' => true, 'searchable' => true, 'sortable' => true];
    }

    /** @return array<string,mixed> */
    private function readOnlyDateTime(string $name, string $label): array
    {
        return ['name' => $name, 'type' => 'datetime', 'label' => $label, 'persisted' => true, 'readonly' => true, 'formable' => false, 'listable' => true, 'sortable' => true];
    }

    /** @param list<array{value:string,label:string}> $items @return array<string,mixed> */
    private function select(string $name, string $label, string $default, array $items): array
    {
        return ['name' => $name, 'type' => 'string', 'label' => $label, 'persisted' => true, 'default' => $default, 'formable' => true, 'listable' => true, 'searchable' => true, 'sortable' => true, 'options' => ['mode' => 'static', 'value_policy' => 'closed', 'items' => $items]];
    }
}
