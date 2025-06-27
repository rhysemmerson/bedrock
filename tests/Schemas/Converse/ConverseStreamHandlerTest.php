<?php

declare(strict_types=1);

namespace Tests\Schemas\Converse;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Bedrock\Enums\BedrockSchema;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Prism;
use Tests\Fixtures\FixtureResponse;

it('streams output', function (): void {
    FixtureResponse::fakeStreamResponses('converse-stream', 'converse/stream-basic-text');

    $response = Prism::text()
        ->using('bedrock', 'apac.anthropic.claude-3-5-sonnet-20240620-v1:0')
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

    expect($finalChunk->usage->promptTokens)->toBe(11);
    expect($finalChunk->usage->completionTokens)->toBe(60);
    expect($finalChunk->usage->cacheWriteInputTokens)->toBeNull();
    expect($finalChunk->usage->cacheReadInputTokens)->toBeNull();

    // Verify the HTTP request
    Http::assertSent(fn(Request $request): bool => str_ends_with($request->url(), 'converse-stream'));

    expect($text)
        ->toBe('I am an AI assistant called Claude. I was created by Anthropic to be helpful, '.
            'harmless, and honest. I don\'t have a physical body or avatar - I\'m a language '.
            'model trained to engage in conversation and help with tasks. How can I assist you today?');
});

// it('handles tool calls', function (): void {

//     $tools = [
//         Tool::as('weather')
//             ->for('useful when you need to search for current weather conditions')
//             ->withStringParameter('city', 'The city that you want the weather for')
//             ->using(fn (string $city): string => 'The weather will be 75° and sunny'),
//         Tool::as('search')
//             ->for('useful for searching curret events or data')
//             ->withStringParameter('query', 'The detailed search query')
//             ->using(fn (string $query): string => 'The tigers game is at 3pm in detroit'),
//     ];

//     $response = Prism::text()
//         ->using('bedrock', 'apac.anthropic.claude-3-5-sonnet-20240620-v1:0')
//         ->withProviderOptions(['apiSchema' => BedrockSchema::Converse])
//         ->withPrompt('What is the weather like in Detroit today?')
//         ->withMaxSteps(2)
//         ->withTools($tools)
//         ->asStream();

//     foreach ($response as $chunk) {
//         echo $chunk->text;
//         if ($chunk->toolCalls !== []) {
//             echo "\nTool calls detected:\n";
//             foreach ($chunk->toolCalls as $toolCall) {
//                 echo "Tool: {$toolCall->name}\n";
//                 echo "Parameters: " . json_encode($toolCall->arguments(), JSON_PRETTY_PRINT) . "\n";
//             }
//         }
//         if ($chunk->toolResults !== []) {
//             echo "\nTool results detected:\n";
//             foreach ($chunk->toolResults as $toolResult) {
//                 echo "Tool: {$toolResult->toolName}\n";
//                 echo "Result: {$toolResult->result}\n";
//             }
//         }
//     }
// });
