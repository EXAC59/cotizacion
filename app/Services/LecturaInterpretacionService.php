<?php

namespace App\Services;

class LecturaInterpretacionService
{
    public function __construct(
        private readonly LecturaLineParser $parser,
    ) {}

    public function interpretar(string $markdown): InterpretacionResult
    {
        return new InterpretacionResult(
            $this->parser->fromMarkdown($markdown),
            'parser',
        );
    }

    public function interpretarTexto(string $text): InterpretacionResult
    {
        return new InterpretacionResult(
            $this->parser->fromPlainText($text),
            'parser',
        );
    }
}
