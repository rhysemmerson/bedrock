<?php

declare(strict_types=1);

namespace Prism\Bedrock\Schemas\Converse\Maps;

use InvalidArgumentException;
use Prism\Prism\Enums\ToolChoice;

class ToolChoiceMap
{
    /**
     * @return array<string, mixed>|string|null
     */
    public static function map(string|ToolChoice|null $toolChoice): string|array|null
    {
        if (is_null($toolChoice)) {
            return null;
        }

        if (is_string($toolChoice)) {
            return [
                'tool' => [
                    'name' => $toolChoice,
                ],
            ];
        }

        if (! in_array($toolChoice, [ToolChoice::Auto, ToolChoice::Any])) {
            throw new InvalidArgumentException('Invalid tool choice');
        }

        return [
            'tool' => [
                ($toolChoice === ToolChoice::Auto ? 'auto' : 'any') => [],
            ],
        ];
    }
}
