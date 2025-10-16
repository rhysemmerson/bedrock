<?php

declare(strict_types=1);

namespace Tests\Schemas\Converse;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use NumberFormatter;
use Prism\Bedrock\Enums\BedrockSchema;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\StreamEventType;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Prism;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ThinkingCompleteEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\Streaming\Events\ThinkingStartEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Tests\Fixtures\FixtureResponse;

it('streams output', function (): void {
    FixtureResponse::fakeStreamResponses('converse-stream', 'converse/stream-basic-text');

    $response = Prism::text()
        ->using('bedrock', 'us.amazon.nova-micro-v1:0')
        ->withProviderOptions(['apiSchema' => BedrockSchema::Converse])
        ->withPrompt('Who are you?')
        ->asStream();

    $text = '';
    $events = [];

    foreach ($response as $event) {
        $events[] = $event;
        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect($events)->not->toBeEmpty();
    expect($text)->not->toBeEmpty();

    $finalEvent = end($events);

    expect($finalEvent->finishReason)->toBe(FinishReason::Stop);

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
                    ->map(fn ($i): string|false => NumberFormatter::create('en', NumberFormatter::SPELLOUT)->format($i))
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
    $event = [];

    foreach ($response as $chunk) {
        $event[] = $chunk;
        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect((array) end($event)->usage)->toBe([
        'promptTokens' => 67,
        'completionTokens' => 48,
        'cacheWriteInputTokens' => 131,
        'cacheReadInputTokens' => 4230,
        'thoughtTokens' => null,
    ]);

    // Verify the HTTP request
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'converse-stream'));
});

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

    $toolCallFound = false;
    $toolResults = [];
    $events = [];
    $text = '';

    foreach ($response as $event) {
        $events[] = $event;

        if ($event instanceof ToolCallEvent) {
            $toolCallFound = true;
            expect($event->toolCall->name)->not->toBeEmpty();
            expect($event->toolCall->arguments())->toBeArray();
        }

        if ($event instanceof ToolResultEvent) {
            $toolResults[] = $event;
        }

        if ($event instanceof TextDeltaEvent) {
            $text .= $event->delta;
        }
    }

    expect($events)->not->toBeEmpty();
    expect($toolCallFound)->toBeTrue('Expected to find at least one tool call in the stream');

    $lastEvent = end($events);
    expect($lastEvent)->toBeInstanceOf(StreamEndEvent::class);
    expect($lastEvent->finishReason)->toBe(FinishReason::Stop);

    // Verify the HTTP request
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://bedrock-runtime.ap-southeast-2.amazonaws.com/model/'.
            'us.amazon.nova-micro-v1:0/converse-stream'
            && isset($body['toolConfig']);
    });
});

it('can handle thinking', function (): void {
    FixtureResponse::fakeStreamResponses('converse-stream', 'converse/stream-thinking-1');

    $response = Prism::text()
        ->using('bedrock', 'apac.anthropic.claude-sonnet-4-20250514-v1:0')
        ->withProviderOptions([
            'apiSchema' => BedrockSchema::Converse,
            'additionalModelRequestFields' => [
                'thinking' => [
                    'type' => 'enabled',
                    'budget_tokens' => 1024,
                ],
            ],
            'inferenceConfig' => [
                'maxTokens' => 5000,
            ],
        ])
        ->withPrompt('Who are you?')
        ->asStream();

    $events = collect($response);

    expect($events->where(fn ($event): bool => $event->type() === StreamEventType::ThinkingStart)->sole())
        ->toBeInstanceOf(ThinkingStartEvent::class);

    $thinkingDeltas = $events->where(
        fn (StreamEvent $event): bool => $event->type() === StreamEventType::ThinkingDelta
    );

    $thinkingDeltas
        ->each(function (StreamEvent $event): void {
            expect($event)->toBeInstanceOf(ThinkingEvent::class);
        });

    expect($thinkingDeltas->count())->toBeGreaterThan(5);

    expect($thinkingDeltas->first()->delta)->not->toBeEmpty();

    expect($events->where(fn ($event): bool => $event->type() === StreamEventType::ThinkingComplete)->sole())
        ->toBeInstanceOf(ThinkingCompleteEvent::class);
});
