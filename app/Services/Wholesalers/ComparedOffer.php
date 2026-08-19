<?php

namespace App\Services\Wholesalers;

readonly class ComparedOffer
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public array $offer,
        public float $score,
        public int $rank,
        public bool $isBest,
        public array $reasons = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->offer,
            'score' => round($this->score, 4),
            'rank' => $this->rank,
            'isBest' => $this->isBest,
            // isSelected is for the quote/request line choice, not the ranking.
            'isSelected' => false,
            'reasons' => $this->reasons,
        ];
    }
}
