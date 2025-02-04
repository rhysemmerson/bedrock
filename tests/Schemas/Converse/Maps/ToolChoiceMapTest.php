<?php

declare(strict_types=1);

namespace Tests\Schemas\Converse\Maps;

use Prism\Bedrock\Schemas\Converse\Maps\ToolChoiceMap;
use Prism\Prism\Enums\ToolChoice;

it('maps a specific tool correctly', function (): void {
    expect(ToolChoiceMap::map('search'))
        ->toBe([
            'tool' => [
                'name' => 'search',
            ],
        ]);
});

it('maps any tool correctly', function (): void {
    expect(ToolChoiceMap::map(ToolChoice::Any))
        ->toBe([
            'tool' => [
                'any' => [],
            ],
        ]);
});

it('maps auto tool correctly', function (): void {
    expect(ToolChoiceMap::map(ToolChoice::Auto))
        ->toBe([
            'tool' => [
                'auto' => [],
            ],
        ]);
});
