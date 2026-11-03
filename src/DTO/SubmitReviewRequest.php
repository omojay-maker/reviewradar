<?php

declare(strict_types=1);

namespace ReviewRadar\DTO;

use ReviewRadar\Exceptions\ValidationException;

/**
 * Validated, normalized inbound review payload for POST api/submit_review.php.
 */
final class SubmitReviewRequest
{
    public const MAX_TEXT_LENGTH = 5000;

    public function __construct(
        public readonly int $companyId,
        public readonly string $text,
        public readonly ?string $authorName,
        public readonly ?float $rating,
        public readonly string $source,
        public readonly ?string $externalId,
        public readonly ?string $sourceUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $payload Raw JSON body.
     *
     * @throws ValidationException When one or more fields are invalid.
     */
    public static function fromArray(array $payload, int $authenticatedCompanyId): self
    {
        $errors = [];
        if ($authenticatedCompanyId < 1) {
            throw new ValidationException('An authenticated company is required.');
        }

        $text = '';
        if (isset($payload['text']) && is_string($payload['text'])) {
            $text = trim($payload['text']);
        }
        if ($text === '') {
            $errors['text'] = 'text is required and must be a non-empty string.';
        } elseif (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            $errors['text'] = 'text must not exceed ' . self::MAX_TEXT_LENGTH . ' characters.';
        }

        $authorName = null;
        if (isset($payload['author_name']) && is_string($payload['author_name'])) {
            $authorName = mb_substr(trim($payload['author_name']), 0, 255);
            if ($authorName === '') {
                $authorName = null;
            }
        }

        $rating = null;
        if (isset($payload['rating']) && $payload['rating'] !== null && $payload['rating'] !== '') {
            $rating = filter_var($payload['rating'], FILTER_VALIDATE_FLOAT);
            if ($rating === false || $rating < 1 || $rating > 5) {
                $errors['rating'] = 'rating must be a number between 1 and 5.';
                $rating = null;
            }
        }

        $source = 'api';
        if (isset($payload['source']) && is_string($payload['source']) && trim($payload['source']) !== '') {
            $source = strtolower(trim($payload['source']));
            if (preg_match('/^[a-z0-9_-]{1,32}$/', $source) !== 1) {
                $errors['source'] = 'source must be 1-32 lowercase letters, digits, dashes or underscores.';
            }
        }

        $externalId = null;
        if (isset($payload['external_id']) && is_string($payload['external_id']) && trim($payload['external_id']) !== '') {
            $externalId = mb_substr(trim($payload['external_id']), 0, 191);
        }

        $sourceUrl = null;
        if (isset($payload['source_url']) && is_string($payload['source_url']) && trim($payload['source_url']) !== '') {
            $candidate = trim($payload['source_url']);
            if (filter_var($candidate, FILTER_VALIDATE_URL) === false || strlen($candidate) > 500) {
                $errors['source_url'] = 'source_url must be a valid URL of at most 500 characters.';
            } else {
                $sourceUrl = $candidate;
            }
        }

        if ($errors !== []) {
            throw new ValidationException('The submitted review failed validation.', $errors);
        }

        return new self(
            companyId: $authenticatedCompanyId,
            text: $text,
            authorName: $authorName,
            rating: $rating === null ? null : round((float) $rating, 1),
            source: $source,
            externalId: $externalId,
            sourceUrl: $sourceUrl,
        );
    }
}
