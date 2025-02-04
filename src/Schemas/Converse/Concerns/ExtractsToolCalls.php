<?php

namespace Prism\Bedrock\Schemas\Converse\Concerns;

use Prism\Prism\ValueObjects\ToolCall;

trait ExtractsToolCalls
{
    /**
     * @param  array<string, mixed>  $data
     * @return ToolCall[]
     */
    protected function extractToolCalls(array $data): array
    {
        $toolCalls = array_map(function ($content) {

            if (! $use = data_get($content, 'toolUse')) {
                return;
            }

            return new ToolCall(
                id: data_get($use, 'toolUseId'),
                name: data_get($use, 'name'),
                arguments: data_get($use, 'input')
            );

        }, data_get($data, 'output.message.content', []));

        return array_values(array_filter($toolCalls));
    }
}
