<?php

declare(strict_types=1);

namespace PTAdmin\Admin\Http\Controllers;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use PTAdmin\Core\Http\Responses\AdminResponse;

final class NoticeTemplateController extends Controller
{
    public function __construct(private ConnectionInterface $database)
    {
    }

    public function options(): JsonResponse
    {
        $scenes = $this->database->table('notice_scenes')
            ->where('enabled', true)
            ->orderBy('group_code')
            ->orderBy('id')
            ->get()
            ->map(fn (object $scene): array => [
                'id' => (int) $scene->id,
                'source_type' => (string) $scene->source_type,
                'source_code' => (string) $scene->source_code,
                'group_code' => (string) $scene->group_code,
                'group_title' => (string) $scene->group_title,
                'code' => (string) $scene->code,
                'title' => (string) $scene->title,
                'description' => $scene->description !== null ? (string) $scene->description : null,
                'purpose' => (string) $scene->purpose,
                'parameters' => $this->jsonArray($scene->parameters),
                'channels' => $this->jsonArray($scene->channels),
                'supports' => $this->jsonArray($scene->supports),
                'record_in_app' => (bool) $scene->record_in_app,
                'enabled' => true,
            ])
            ->values()
            ->all();
        $channels = $this->database->table('notice_channels')
            ->where('enabled', true)
            ->orderBy('type')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $channel): array => [
                'id' => (int) $channel->id,
                'source_type' => (string) $channel->source_type,
                'source_code' => (string) $channel->source_code,
                'code' => (string) $channel->code,
                'type' => (string) $channel->type,
                'title' => (string) $channel->title,
                'editor' => (string) $channel->editor,
                'enabled' => true,
            ])
            ->values()
            ->all();

        return AdminResponse::success('', ['scenes' => $scenes, 'channels' => $channels]);
    }

    /** @return array<mixed> */
    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
