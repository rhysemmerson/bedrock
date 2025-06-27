<?php

namespace Prism\Bedrock\Schemas\Converse;

use Aws\Api\Parser\DecodingEventStreamIterator;
use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Prism\Bedrock\Schemas\Converse\Maps\FinishReasonMap;
use Prism\Bedrock\ValueObjects\StreamState;
use Prism\Prism\Concerns\CallsTools;
use Prism\Prism\Enums\ChunkType;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Text\Chunk;
use Prism\Prism\Text\Request;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

class ConverseStreamHandler
{
    use CallsTools;

    protected Response $httpResponse;

    protected StreamState $state;

    public function __construct(protected PendingRequest $client)
    {
        $this->state = new StreamState;
    }

    /**
     * @return Generator<Chunk>
     *
     * @throws PrismChunkDecodeException
     * @throws PrismException
     * @throws PrismRateLimitedException
     */
    public function handle(Request $request): Generator
    {
        $response = $this->sendRequest($request);

        // $file = fopen('/Users/rhysemmerson/Code/packages/bedrock/tests/Fixtures/converse/stream.json', 'w+');

        // while (!$response->getBody()->eof()) {
        //     $chunk = $response->getBody()->read(1024);
        //     fwrite($file, $chunk);
        // }
        // fclose($file);
        // dd();
        yield from $this->processStream($response, $request);
    }
    /**
     * @return array<string,mixed>
     */
    public static function buildPayload(Request $request, int $stepCount = 0): array
    {
        return ConverseTextHandler::buildPayload(
            $request,
            $stepCount
        );
    }

    protected function sendRequest(Request $request): Response
    {
        try {
            return $this->client
                ->withOptions(['stream' => true])
                ->post(
                    'converse-stream',
                    static::buildPayload($request)
                );
        } catch (Throwable $e) {
            throw PrismException::providerRequestError($e->response->getBody()->getContents(), $e);
        }
    }

    protected function processStream(Response $response, Request $request, int $depth = 0)
    {
        $this->state->reset();

        $decoder = new DecodingEventStreamIterator($response->getBody());

        foreach ($decoder as $chunk) {
            $chunk = $this->processChunk($chunk);
            if ($chunk) {
                yield $chunk;
            }
        }

        if ($this->state->hasToolCalls()) {
            yield from $this->handleToolCalls($request, $this->mapToolCalls(), $depth, $this->state->buildAdditionalContent());
        }
    }

    protected function handleToolCalls(Request $request, array $toolCalls, int $depth, ?array $additionalContent = null): Generator
    {
        $toolResults = [];

        foreach ($toolCalls as $toolCall) {
            $tool = $this->resolveTool($toolCall->name, $request->tools());

            try {
                $result = call_user_func_array(
                    $tool->handle(...),
                    $toolCall->arguments()
                );

                $toolResult = new ToolResult(
                    toolCallId: $toolCall->id,
                    toolName: $toolCall->name,
                    args: $toolCall->arguments(),
                    result: $result,
                );

                $toolResults[] = $toolResult;

                yield new Chunk(
                    text: '',
                    toolResults: [$toolResult],
                    chunkType: ChunkType::ToolResult
                );
            } catch (Throwable $e) {
                if ($e instanceof PrismException) {
                    throw $e;
                }

                throw PrismException::toolCallFailed($toolCall, $e);
            }
        }

        $this->addMessagesToRequest($request, $toolResults, $additionalContent);

        $depth++;

        if ($this->shouldContinue($request, $depth)) {
            $nextResponse = $this->sendRequest($request);
            yield from $this->processStream($nextResponse, $request, $depth);
        }
    }

    protected function shouldContinue(Request $request, int $depth): bool
    {
        return $depth < $request->maxSteps();
    }

    /**
     * @param  array<int|string, mixed>  $toolResults
     * @param  array<string, mixed>|null  $additionalContent
     */
    protected function addMessagesToRequest(Request $request, array $toolResults, ?array $additionalContent): void
    {
        $request->addMessage(new AssistantMessage(
            $this->state->text(),
            $this->mapToolCalls(),
            $additionalContent ?? []
        ));

        $message = new ToolResultMessage($toolResults);

        $request->addMessage($message);
    }

    /**
     * @return array<int, ToolCall>
     */
    protected function mapToolCalls(): array
    {
        return array_values(array_map(function (array $toolCall): ToolCall {
            $input = data_get($toolCall, 'input');
            if (is_string($input) && $this->isValidJson($input)) {
                $input = json_decode($input, true);
            }

            return new ToolCall(
                id: data_get($toolCall, 'id'),
                name: data_get($toolCall, 'name'),
                arguments: $input
            );
        }, $this->state->toolCalls()));
    }

    protected function isValidJson(string $string): bool
    {
        if ($string === '' || $string === '0') {
            return false;
        }

        try {
            json_decode($string, true, 512, JSON_THROW_ON_ERROR);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    protected function processChunk(array $chunk)
    {
        $json = json_decode((string) $chunk['payload'], true);

        return match ($chunk['headers'][':event-type']) {
            'contentBlockDelta' => $this->handleContentBlockDelta($json),
            'contentBlockStart' => $this->handleContentBlockStart($json),
            'contentBlockStop' => $this->handleContentBlockStop($json),
            'internalServerException' => $this->handleInternalServerException($json),
            'messageStart' => $this->handleMessageStart($json),
            'messageStop' => $this->handleMessageStop($json),
            'metadata' => $this->handleMetadata($json),
            'modelStreamErrorException' => $this->handleModelStreamErrorException($json),
            'serviceUnavailableException' => $this->handleServiceUnavailableException($json),
            'throttlingException' => $this->handleThrottlingException($json),
            'validationException ' => $this->handleValidationException($json),
        };
    }

    protected function handleContentBlockDelta(array $chunk): ?\Prism\Prism\Text\Chunk
    {
        if ($text = data_get($chunk, 'delta.text')) {
            return new Chunk(
                text: $text,
                additionalContent: [
                    'contentBlockIndex' => data_get($chunk, 'contentBlockIndex'),
                ],
                chunkType: ChunkType::Text
            );
        }

        if ($toolUse = data_get($chunk, 'delta.toolUse')) {
            return $this->handleToolUseBlockDelta($toolUse);
        }

        return null;
    }

    protected function handleContentBlockStart(array $chunk): null
    {
        $blockType = (bool) data_get($chunk, 'start.toolUse')
            ? 'tool_use' : 'text';

        $blockIndex = (int) data_get($chunk, 'contentBlockIndex');

        $this->state
            ->setTempContentBlockType($blockType)
            ->setTempContentBlockIndex($blockIndex);

        if ($blockType === 'tool_use') {
            $this->state->addToolCall($blockIndex, [
                'id' => data_get($chunk, 'start.toolUse.toolUseId'),
                'name' => data_get($chunk, 'start.toolUse.name'),
                'input' => '',
            ]);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $chunk
     */
    protected function handleToolUseBlockDelta(array $toolUse): ?Chunk
    {
        $jsonDelta = data_get($toolUse, 'input');

        $blockIndex = $this->state->tempContentBlockIndex();

        if ($blockIndex !== null) {
            $this->state->appendToolCallInput($blockIndex, $jsonDelta);
        }

        return null;
    }

    protected function handleContentBlockStop(array $chunk): ?\Prism\Prism\Text\Chunk
    {
        info('Bedrock ConverseStream: contentBlockStop');

        $blockType = $this->state->tempContentBlockType();
        $blockIndex = $this->state->tempContentBlockIndex();

        $chunk = null;

        if ($blockType === 'tool_use' && $blockIndex !== null && isset($this->state->toolCalls()[$blockIndex])) {
            $toolCallData = $this->state->toolCalls()[$blockIndex];
            $input = data_get($toolCallData, 'input');

            // if (is_string($input) && $this->isValidJson($input)) {
            $input = json_decode((string) $input, true);
            // }

            $toolCall = new ToolCall(
                id: data_get($toolCallData, 'id'),
                name: data_get($toolCallData, 'name'),
                arguments: $input
            );

            $chunk = new Chunk(
                text: '',
                toolCalls: [$toolCall],
                chunkType: ChunkType::ToolCall
            );
        }

        $this->state->resetContentBlock();

        return $chunk;
    }

    protected function handleInternalServerException(array $chunk)
    {
        info('Bedrock ConverseStream: internalServerException');
    }

    protected function handleMessageStart(array $chunk): \Prism\Prism\Text\Chunk
    {
        info('Bedrock ConverseStream: messageStart');

        // $this->state
        //     ->setModel(data_get($chunk, 'message.model', ''))
        //     ->setRequestId(data_get($chunk, 'message.id', ''))
        //     ->setUsage(data_get($chunk, 'message.usage', []));

        return new Chunk(
            text: '',
            finishReason: null,
            // meta: new Meta(
            // id: $this->state->requestId(),
            // model: $this->state->model(),
            // rateLimits: $this->processRateLimits($response)
            // ),
            chunkType: ChunkType::Meta
        );
    }

    protected function handleMessageStop(array $chunk)
    {
        $this->state->setStopReason(data_get($chunk, 'stopReason'));
    }

    protected function handleMetadata(array $chunk): \Prism\Prism\Text\Chunk
    {
        info('Bedrock ConverseStream: metadata');

        return new Chunk(
            text: $this->state->text(),
            finishReason: FinishReasonMap::map($this->state->stopReason()),
            meta: new Meta(
                id: $this->state->requestId(),
                model: $this->state->model(),
                // rateLimits: $this->processRateLimits($response)
            ),
            usage: new Usage(
                promptTokens: data_get($chunk, 'usage.inputTokens', 0),
                completionTokens: data_get($chunk, 'usage.outputTokens', 0),
            ),
            additionalContent: $this->state->buildAdditionalContent(),
            chunkType: ChunkType::Meta
        );
    }

    protected function handleModelStreamErrorException(array $chunk)
    {
        info('Bedrock ConverseStream: modelStreamErrorException');
    }

    protected function handleServiceUnavailableException(array $chunk)
    {
        info('Bedrock ConverseStream: serviceUnavailableException');
    }

    protected function handleThrottlingException(array $chunk)
    {
        info('Bedrock ConverseStream: throttlingException');
    }

    protected function handleValidationException(array $chunk)
    {
        info('Bedrock ConverseStream: validationException');
    }
}
