<?php

namespace Prism\Bedrock\Schemas\Converse\Maps;

use Prism\Prism\Enums\Citations\CitationSourcePositionType;
use Prism\Prism\Enums\Citations\CitationSourceType;
use Prism\Prism\ValueObjects\Citation;

class CitationsMapper
{
    public static function mapCitationFromConverse(array $citationData): Citation
    {
        $location = $citationData['location'] ?? [];

        $indices = $location['documentChar'] ?? $location['documentChunk'] ?? $location['documentPage'] ?? null;

        return new Citation(
            sourceType: CitationSourceType::Document,
            source: $indices['documentIndex'] ?? null,
            sourceText: self::mapSourceText($citationData['sourceContent'] ?? []),
            sourceTitle: $citationData['title'] ?? '',
            sourcePositionType: self::mapSourcePositionType($location),
            sourceStartIndex: $indices['start'] ?? null,
            sourceEndIndex: $indices['end'] ?? null,
        );
    }

    protected static function mapSourceText(array $citationData): ?string
    {
        return implode("\n", array_map(
            fn (array $sourceContent): string => $sourceContent['text'] ?? '',
            $citationData
        ));
    }

    protected static function mapSourcePositionType(array $location): ?CitationSourcePositionType
    {
        return match (array_keys($location)[0] ?? null) {
            'documentChar' => CitationSourcePositionType::Character,
            'documentChunk' => CitationSourcePositionType::Chunk,
            'documentPage' => CitationSourcePositionType::Page,
            default => null,
        };
    }
}
