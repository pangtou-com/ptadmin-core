<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PTAdmin\Core\Config\Models\ConfigDefinition;
use PTAdmin\Core\Config\Models\ConfigValue;
use PTAdmin\Schema\Definition\Schema;

return new class() extends Migration {
    public function up(): void
    {
        $this->renameField('site_name', 'title');
    }

    public function down(): void
    {
        $this->renameField('title', 'site_name');
    }

    private function renameField(string $from, string $to): void
    {
        DB::transaction(static function () use ($from, $to): void {
            $owner = ['owner_type' => 'core', 'owner_code' => 'ptadmin', 'config_key' => 'site'];
            $definition = ConfigDefinition::query()->where($owner)->first();
            if (!$definition) {
                return;
            }

            $schema = (array) $definition->schema_json;
            $fields = (array) ($schema['fields'] ?? []);
            $names = array_column($fields, null, 'name');
            $defaultTitle = $names[$from]['default'] ?? '';
            if (isset($names[$from])) {
                $renamedFields = [];
                foreach ($fields as $field) {
                    if ($field['name'] === $from) {
                        if (isset($names[$to])) {
                            continue;
                        }
                        $field['name'] = $to;
                    }
                    $renamedFields[] = $field;
                }
                $schema['fields'] = $renamedFields;
                $definition->schema_json = $schema;
                $definition->checksum = (new Schema($schema))->checksum();
                $definition->save();
            }

            $values = ConfigValue::query()->where($owner)->get();
            if ($values->isEmpty() && isset($names[$from]) && $defaultTitle !== '') {
                $value = new ConfigValue();
                $value->fill($owner + ['scope_type' => 'global', 'scope_id' => '', 'revision' => 0]);
                $values->push($value);
            }
            foreach ($values as $value) {
                $stored = (array) $value->value_json;
                $renamed = $stored;
                if (!array_key_exists($to, $renamed)) {
                    if (array_key_exists($from, $renamed)) {
                        $renamed[$to] = $renamed[$from];
                    } elseif ($defaultTitle !== '') {
                        $renamed[$to] = $defaultTitle;
                    }
                }
                unset($renamed[$from]);
                if ($renamed !== $stored || !$value->exists) {
                    $value->value_json = $renamed;
                    $value->checksum = hash('sha256', json_encode($renamed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                    $value->revision = (int) $value->revision + 1;
                    $value->save();
                }
                Cache::forget('ptadmin:config:value:core:ptadmin:site:'.$value->scope_type.':'.$value->scope_id);
            }
            Cache::forget('ptadmin:config:definition:core:ptadmin:site');
        });
    }
};
