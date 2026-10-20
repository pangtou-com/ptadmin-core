<?php

declare(strict_types=1);

namespace PTAdmin\Captcha;

use InvalidArgumentException;

final class ChallengeType
{
    public const IMAGE_TEXT = 'image_text';
    public const IMAGE_SELECT = 'image_select';
    public const SLIDER = 'slider';
    public const ROTATE = 'rotate';
    public const WIDGET_TOKEN = 'widget_token';
    public const RISK_TOKEN = 'risk_token';

    /** @return array<int, string> */
    public static function all(): array
    {
        return [
            self::IMAGE_TEXT,
            self::IMAGE_SELECT,
            self::SLIDER,
            self::ROTATE,
            self::WIDGET_TOKEN,
            self::RISK_TOKEN,
        ];
    }

    public static function assert(string $type): string
    {
        if (!in_array($type, self::all(), true)) {
            throw new InvalidArgumentException('Unsupported captcha challenge type.');
        }

        return $type;
    }
}
