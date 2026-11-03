<?php

declare(strict_types=1);

namespace ReviewRadar\DTO;

use ReviewRadar\Enums\Sentiment;
use ReviewRadar\Exceptions\AnalysisException;

/**
 * Structured AI output for a single review:
 * sentiment, score (-1..1), categories[], summary, requires_action.
 */
final class ReviewAnalysis
{
    public const MAX_CATEGORIES = 10;
    public const MAX_SUMMARY_LENGTH = 512;

    /** @param list<string> $categories */
    public function __construct(
        public readonly Sentiment $sentiment,
        public readonly float $score,
        public readonly array $categories,
        public readonly string $summary,
        public readonly bool $requiresAction,
    ) {
    }

    /**
     * Defensive parser for the Groq structured-JSON completion. Every field is
     * re-validated here so a malformed model response can never corrupt the database.
     *
     * @param array<string, mixed> $payload
     *
     * @throws AnalysisException
     */
    public static function fromArray(array $payload): self
    {
        $sentiment = Sentiment::tryFrom(strtolower(trim((string) ($payload['sentiment'] ?? ''))));
        if ($sentiment === null) {
            throw new AnalysisException('Analysis failed: invalid sentiment value returned by the model.');
        }

        $rawScore = $payload['score'] ?? null;
        if (!is_numeric($rawScore)) {
            throw new AnalysisException('Analysis failed: non-numeric score returned by the model.');
        }
        $score = max(-1.0, min(1.0, (float) $rawScore));

        $rawCategories = $payload['categories'] ?? null;
        if (!is_array($rawCategories)) {
            throw new AnalysisException('Analysis failed: categories must be an array of strings.');
        }
        $categories = [];
        foreach ($rawCategories as $category) {
            if (!is_string($category)) {
                continue;
            }
            $category = mb_strtolower(trim($category));
            if ($category === '' || in_array($category, $categories, true)) {
                continue;
            }
            $categories[] = $category;
            if (count($categories) >= self::MAX_CATEGORIES) {
                break;
            }
        }

        $summary = trim((string) ($payload['summary'] ?? ''));
        if ($summary === '') {
            throw new AnalysisException('Analysis failed: empty summary returned by the model.');
        }

        $requiresAction = filter_var($payload['requires_action'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return new self(
            sentiment: $sentiment,
            score: round($score, 3),
            categories: $categories,
            summary: mb_substr($summary, 0, self::MAX_SUMMARY_LENGTH),
            requiresAction: $requiresAction,
        );
    }

    /** @return array{sentiment: string, score: float, categories: list<string>, summary: string, requires_action: bool} */
    public function toArray(): array
    {
        return [
            'sentiment' => $this->sentiment->value,
            'score' => $this->score,
            'categories' => $this->categories,
            'summary' => $this->summary,
            'requires_action' => $this->requiresAction,
        ];
    }
}
