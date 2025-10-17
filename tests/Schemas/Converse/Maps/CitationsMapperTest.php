<?php

use Prism\Bedrock\Schemas\Converse\Maps\CitationsMapper;
use Prism\Prism\Enums\Citations\CitationSourcePositionType;
use Prism\Prism\Enums\Citations\CitationSourceType;

it('can map citations from converse api', function (): void {
    $citation = CitationsMapper::mapCitationFromConverse([
        'location' => [
            'documentPage' => [
                'documentIndex' => 3,
                'end' => 17,
                'start' => 5,
            ],
        ],
        'sourceContent' => [
            [
                'text' => 'The answer to the ultimate question of life, the universe, and everything is "42".',
            ],
        ],
        'title' => 'The Answer To Life',
    ]);

    expect($citation->sourceType)->toBe(CitationSourceType::Document);
    expect($citation->source)->toBe(3);
    expect($citation->sourceText)->toBe('The answer to the ultimate question of life, the universe, and everything is "42".');
    expect($citation->sourceTitle)->toBe('The Answer To Life');
    expect($citation->sourcePositionType)->toBe(CitationSourcePositionType::Page);
    expect($citation->sourceStartIndex)->toBe(5);
    expect($citation->sourceEndIndex)->toBe(17);
});

it('can map citations with character location', function (): void {
    $citation = CitationsMapper::mapCitationFromConverse([
        'location' => [
            'documentChar' => [
                'documentIndex' => 7,
                'end' => 42,
                'start' => 13,
            ],
        ],
        'sourceContent' => [
            [
                'text' => 'The answer to the ultimate question of life, the universe, and everything is "42".',
            ],
        ],
        'title' => 'The Answer To Life',
    ]);

    expect($citation->sourceType)->toBe(CitationSourceType::Document);
    expect($citation->source)->toBe(7);
    expect($citation->sourceText)->toBe('The answer to the ultimate question of life, the universe, and everything is "42".');
    expect($citation->sourceTitle)->toBe('The Answer To Life');
    expect($citation->sourcePositionType)->toBe(CitationSourcePositionType::Character);
    expect($citation->sourceStartIndex)->toBe(13);
    expect($citation->sourceEndIndex)->toBe(42);
});

it('can map citations with chunk location', function (): void {
    $citation = CitationsMapper::mapCitationFromConverse([
        'location' => [
            'documentChunk' => [
                'documentIndex' => 2,
                'end' => 99,
                'start' => 77,
            ],
        ],
        'sourceContent' => [
            [
                'text' => 'The answer to the ultimate question of life, the universe, and everything is "42".',
            ],
        ],
        'title' => 'The Answer To Life',
    ]);

    expect($citation->sourceType)->toBe(CitationSourceType::Document);
    expect($citation->source)->toBe(2);
    expect($citation->sourceText)->toBe('The answer to the ultimate question of life, the universe, and everything is "42".');
    expect($citation->sourceTitle)->toBe('The Answer To Life');
    expect($citation->sourcePositionType)->toBe(CitationSourcePositionType::Chunk);
    expect($citation->sourceStartIndex)->toBe(77);
    expect($citation->sourceEndIndex)->toBe(99);
});
