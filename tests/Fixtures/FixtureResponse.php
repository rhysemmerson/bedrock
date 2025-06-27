<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

class FixtureResponse
{
    public static function fromFile(
        string $filePath,
        int $statusCode = 200,
        $headers = []
    ): PromiseInterface {
        return Http::response(
            file_get_contents(static::filePath($filePath)),
            $statusCode,
            $headers,
        );
    }

    public static function fakeStreamResponses(string $requestPath, string $name, array $headers = []): void
    {
        $basePath = dirname(static::filePath("{$name}-1.bin"));

        // Find all recorded .bin files for this test
        $files = collect(is_dir($basePath) ? scandir($basePath) : [])
            ->filter(fn ($file): int|false => preg_match('/^'.preg_quote(basename($name), '/').'-\d+\.bin$/', $file))
            ->map(fn ($file): string => $basePath.'/'.$file)
            ->values()
            ->toArray();

        // If no files exist, automatically record the streaming responses
        if (empty($files)) {
            static::recordStreamResponses($requestPath, $name);

            return;
        }

        // Sort files numerically
        usort($files, function ($a, $b): int {
            preg_match('/-(\d+)\.bin$/', $a, $matchesA);
            preg_match('/-(\d+)\.bin$/', $b, $matchesB);

            return (int) $matchesA[1] <=> (int) $matchesB[1];
        });

        // Create response sequence from the files
        $responses = array_map(fn ($file) => Http::response(
            file_get_contents($file),
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'Transfer-Encoding' => 'chunked',
                ...$headers,
            ]
        ), $files);

        if ($responses === []) {
            $responses[] = Http::response(
                "data: {\"error\":\"No recorded stream responses found\"}\n\ndata: [DONE]\n\n",
                200,
                ['Content-Type' => 'text/event-stream']
            );
        }

        // Register the fake responses
        Http::fake([
            $requestPath => Http::sequence($responses),
        ])->preventStrayRequests();
    }

    public static function filePath(string $filePath): string
    {
        return sprintf('%s/%s', __DIR__, $filePath);
    }

    public static function recordResponses(string $requestPath, string $name): void
    {
        $iterator = 0;

        Http::globalResponseMiddleware(function ($response) use ($name, &$iterator) {
            $iterator++;

            $path = static::filePath("{$name}-{$iterator}.json");

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), recursive: true);
            }

            file_put_contents(
                $path,
                (string) $response->getBody()
            );

            return $response;
        });
    }

    public static function fakeResponseSequence(string $requestPath, string $name, array $headers = []): void
    {
        $responses = collect(scandir(dirname(static::filePath($name))))
            ->filter(function (string $file) use ($name): int|false {
                $pathInfo = pathinfo($name);
                $filename = $pathInfo['filename'];

                return preg_match('/^'.preg_quote($filename, '/').'-\d+/', $file);
            })
            ->map(fn ($filename): string => dirname(static::filePath($name)).'/'.$filename)
            ->map(fn ($filePath) => Http::response(
                file_get_contents($filePath),
                200,
                $headers
            ));

        Http::fake([
            $requestPath => Http::sequence($responses->toArray()),
        ])->preventStrayRequests();
    }
}
