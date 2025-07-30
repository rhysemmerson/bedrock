<?php

declare(strict_types=1);

namespace Tests\Schemas\Converse;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Bedrock\Enums\BedrockSchema;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Tests\Fixtures\FixtureResponse;

it('streams output', function (): void {
    FixtureResponse::fakeStreamResponses('converse-stream', 'converse/stream-basic-text');

    $response = Prism::text()
        ->using('bedrock', 'us.amazon.nova-micro-v1:0')
        ->withProviderOptions(['apiSchema' => BedrockSchema::Converse])
        ->withPrompt('Who are you?')
        ->asStream();

    $text = '';
    $chunks = [];

    foreach ($response as $chunk) {
        $chunks[] = $chunk;
        $text .= $chunk->text;
    }

    expect($chunks)->not->toBeEmpty();
    expect($text)->not->toBeEmpty();

    $finalChunk = end($chunks);

    expect($finalChunk->finishReason)->toBe(FinishReason::Stop);

    // Verify the HTTP request
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'converse-stream'));

    expect($text)
        ->toBe('I am an AI assistant called Claude. I was created by Anthropic to be helpful, '.
            'harmless, and honest. I don\'t have a physical body or avatar - I\'m a language '.
            'model trained to engage in conversation and help with tasks. How can I assist you today?');
});

it('can return usage with a basic stream', function (): void {
    FixtureResponse::fakeStreamResponses('converse-stream', 'converse/stream-basic-text-with-cache-usage');

    // Read a large system prompt from cache
    // Write a conversation to cache

    $response = Prism::text()
        ->using('bedrock', 'us.amazon.nova-micro-v1:0')
        ->withSystemPrompt(
            (new SystemMessage(
                collect(range(1, 1000))
                    ->map(fn ($i): string|false => \NumberFormatter::create('en', \NumberFormatter::SPELLOUT)->format($i))
                    ->implode(' ')
            ))
                ->withProviderOptions(['cacheType' => 'default'])
        )
        ->withMessages([
            new UserMessage('Who are you?'),
            (new AssistantMessage('Hi I\'m Nova'))
                ->withProviderOptions(['cacheType' => 'default']),
            new UserMessage('Nice to meet you Nova'),
        ])
        ->asStream();

    $text = '';
    $chunks = [];

    foreach ($response as $chunk) {
        $chunks[] = $chunk;
        $text .= $chunk->text;
    }

    expect((array) end($chunks)->usage)->toBe([
        'promptTokens' => 67,
        'completionTokens' => 48,
        'cacheWriteInputTokens' => 131,
        'cacheReadInputTokens' => 4230,
        'thoughtTokens' => null,
    ]);

    expect($text)->not()->toBeEmpty();
    expect($chunks)->not()->toBeEmpty();

    // Verify the HTTP request
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'converse-stream'));
});

describe('tools', function (): void {
    it('can handle tool calls', function (): void {
        FixtureResponse::fakeStreamResponses('converse-stream', 'converse/stream-handle-tool-cals');

        $tools = [
            Tool::as('weather')
                ->for('useful when you need to search for current weather conditions')
                ->withStringParameter('city', 'The city that you want the weather for')
                ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
            Tool::as('search')
                ->for('useful for searching curret events or data')
                ->withStringParameter('query', 'The detailed search query')
                ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
        ];

        $response = Prism::text()
            ->using('bedrock', 'us.amazon.nova-micro-v1:0')
            ->withProviderOptions(['apiSchema' => BedrockSchema::Converse])
            ->withPrompt('What is the weather like in Detroit today?')
            ->withMaxSteps(2)
            ->withTools($tools)
            ->asStream();

        $toolCalls = [];
        $toolResults = [];

        $text = '';

        foreach ($response as $chunk) {
            $text .= $chunk->text;
            $toolCalls = [...$toolCalls, ...$chunk->toolCalls];
            $toolResults = [...$toolResults, ...$chunk->toolResults];
        }

        expect($text)->not()->toBeEmpty();
        expect($toolCalls)->toHaveLength(1);
        expect($toolResults)->toHaveLength(1);

        [$toolCall] = $toolCalls;
        [$toolResult] = $toolResults;

        expect($toolCall)
            ->toBeInstanceOf(ToolCall::class)
            ->toHaveProperties([
                'id' => 'tooluse_XXY4prZmT6K90Vao7_3Wsg',
                'name' => 'weather',
                'resultId' => null,
                'reasoningId' => null,
                'reasoningSummary' => null,
            ])
            ->and($toolCall->arguments())
            ->toBe([
                'city' => 'Detroit',
            ]);

        expect($toolResult)
            ->toBeInstanceOf(ToolResult::class)
            ->toHaveProperties([
                'toolCallId' => 'tooluse_XXY4prZmT6K90Vao7_3Wsg',
                'toolName' => 'weather',
                'result' => 'The weather will be 75° and sunny',
                'toolCallResultId' => null,
            ]);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'converse-stream')
            && str_contains($request->body(), '{"text":"The weather will be 75\u00b0 and sunny"}'));
    });

    it('can call multiple tools', function (): void {
        FixtureResponse::fakeStreamResponses('converse-stream', 'converse/stream-handle-multiple-tool-cals');

        $tools = [
            Tool::as('weather')
                ->for('useful when you need to search for current weather conditions')
                ->withStringParameter('city', 'The city that you want the weather for')
                ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
            Tool::as('search')
                ->for('useful for searching curret events or data')
                ->withStringParameter('query', 'The detailed search query')
                ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
        ];

        $response = Prism::text()
            ->using('bedrock', 'us.amazon.nova-micro-v1:0')
            ->withProviderOptions(['apiSchema' => BedrockSchema::Converse])
            ->withPrompt('Where is the tigers game tonight and what will the weather be like?')
            ->withMaxSteps(3)
            ->withTools($tools)
            ->asStream();

        $toolCalls = [];
        $toolResults = [];

        foreach ($response as $chunk) {
            $toolCalls = [...$toolCalls, ...$chunk->toolCalls];
            $toolResults = [...$toolResults, ...$chunk->toolResults];
        }

        expect($toolCalls)->toHaveLength(2);
        expect($toolResults)->toHaveLength(2);

        [$toolCall1, $toolCall2] = $toolCalls;
        [$toolResult1, $toolResult2] = $toolResults;

        expect($toolCall1)
            ->toBeInstanceOf(ToolCall::class)
            ->toHaveProperties([
                'name' => 'search',
            ])
            ->and($toolCall1->arguments())
            ->toBe([
                'query' => 'Tigers game tonight schedule',
            ]);
        expect($toolCall2)
            ->toBeInstanceOf(ToolCall::class)
            ->toHaveProperties([
                'name' => 'weather',
            ])
            ->and($toolCall2->arguments())
            ->toBe([
                'city' => 'Detroit',
            ]);

        expect($toolResult1)
            ->toBeInstanceOf(ToolResult::class)
            ->toHaveProperties([
                'toolName' => 'search',
                'result' => 'The tigers game is at 3pm in detroit',
            ]);
        expect($toolResult2)
            ->toBeInstanceOf(ToolResult::class)
            ->toHaveProperties([
                'toolName' => 'weather',
                'result' => 'The weather will be 75° and sunny',
            ]);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'converse-stream')
            && str_contains($request->body(), '{"text":"The weather will be 75\u00b0 and sunny"}'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'converse-stream')
            && str_contains($request->body(), '{"text":"The tigers game is at 3pm in detroit"}'));
    });

    it('can call multiple tools per step', function (): void {
        FixtureResponse::fakeStreamResponses('converse-stream', 'converse/stream-handle-multiple-tool-calls-per-step');

        $tools = [
            Tool::as('weather')
                ->for('useful when you need to search for current weather conditions')
                ->withStringParameter('city', 'The city that you want the weather for')
                ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
            Tool::as('search')
                ->for('useful for searching current events or data')
                ->withStringParameter('query', 'The detailed search query')
                ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
        ];

        $response = Prism::text()
            ->using('bedrock', 'us.amazon.nova-micro-v1:0')
            ->withProviderOptions(['apiSchema' => BedrockSchema::Converse])
            ->withPrompt('Tell me the weather in Detroit and also when the Tigers game is tonight. Please answer both at once.')
            ->withMaxSteps(2)
            ->withTools($tools)
            ->asStream();

        $toolCalls = [];
        $toolResults = [];

        while ($chunk = $response->current()) {
            $toolCalls = [...$toolCalls, ...$chunk->toolCalls];
            $toolResults = [...$toolResults, ...$chunk->toolResults];

            $response->next();

            if (count($toolCalls) === 2) {
                break;
            }
        }

        expect($toolCalls)->toHaveLength(2);
        expect($toolResults)->toHaveLength(0);

        Http::assertSentCount(1);

        while ($chunk = $response->current()) {
            $toolCalls = [...$toolCalls, ...$chunk->toolCalls];
            $toolResults = [...$toolResults, ...$chunk->toolResults];
            $response->next();
        }

        expect($toolCalls)->toHaveLength(2);
        expect($toolResults)->toHaveLength(2);

        Http::assertSentCount(2);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'converse-stream')
            && str_contains($request->body(), '{"text":"The weather will be 75\u00b0 and sunny"}'));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'converse-stream')
            && str_contains($request->body(), '{"text":"The tigers game is at 3pm in detroit"}'));
    });
});

it('can handle stream exceptions', function (): void {
    FixtureResponse::fakeStreamResponses('converse-stream', 'converse/stream-handle-exceptions');

    $tools = [
        Tool::as('determine-weather')
            ->for('Returns a list of weather conditions')
            ->withStringParameter('city', 'The city that you want the weather for')
            ->using(fn (...$args): string => 'The weather will be 75° and sunny'),
    ];

    $response = Prism::text()
        ->using('bedrock', 'us.amazon.nova-micro-v1:0')
        ->withProviderOptions(['apiSchema' => BedrockSchema::Converse])
        ->withPrompt('What is the weather like in Detroit today?')
        ->withMaxSteps(3)
        ->withTools($tools)
        ->asStream();

    iterator_to_array($response, false);
})->throws(PrismException::class, 'Bedrock Converse Stream Error (modelStreamErrorException): {"message":'.
                                    '"Model produced invalid sequence as part of ToolUse. Please refer '.
                                    'to the model tool use troubleshooting guide."}');
