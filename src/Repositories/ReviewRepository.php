<?php

declare(strict_types=1);

namespace ReviewRadar\Repositories;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use PDO;
use PDOException;
use ReviewRadar\DTO\ReviewAnalysis;
use ReviewRadar\DTO\SubmitReviewRequest;
use ReviewRadar\Exceptions\ValidationException;

final class ReviewRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Persist an analyzed review for the authenticated tenant.
     *
     * @throws ValidationException When (company_id, external_id) already exists.
     */
    public function create(SubmitReviewRequest $request, ReviewAnalysis $analysis, string $model): int
    {
        $sql = <<<'SQL'
            INSERT INTO reviews (
                company_id, source, external_id, author_name, rating, raw_text, source_url,
                sentiment, score, categories, summary, requires_action, model, analyzed_at
            ) VALUES (
                :company_id, :source, :external_id, :author_name, :rating, :raw_text, :source_url,
                :sentiment, :score, :categories, :summary, :requires_action, :model, UTC_TIMESTAMP()
            )
            SQL;

        try {
            $categories = json_encode($analysis->categories, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'company_id' => $request->companyId,
                'source' => $request->source,
                'external_id' => $request->externalId,
                'author_name' => $request->authorName,
                'rating' => $request->rating,
                'raw_text' => $request->text,
                'source_url' => $request->sourceUrl,
                'sentiment' => $analysis->sentiment->value,
                'score' => $analysis->score,
                'categories' => $categories,
                'summary' => $analysis->summary,
                'requires_action' => $analysis->requiresAction ? 1 : 0,
                'model' => $model,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ValidationException(
                    'A review with this external_id already exists for this company.',
                    ['external_id' => 'Duplicate external_id for this company.'],
                    $e,
                );
            }
            throw $e;
        } catch (JsonException $e) {
            throw new ValidationException('Failed to encode review categories.', [], $e);
        }

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<array<string, mixed>> */
    public function listForCompany(int $companyId, int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT id, source, author_name, rating, raw_text, sentiment, score,
                   categories, summary, requires_action, source_url, created_at
            FROM reviews
            WHERE company_id = :company_id
            ORDER BY created_at DESC, id DESC
            LIMIT :limit OFFSET :offset
            SQL);
        $stmt->bindValue('company_id', $companyId, PDO::PARAM_INT);
        $stmt->bindValue('limit', max(1, min(250, $limit)), PDO::PARAM_INT);
        $stmt->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        $reviews = [];
        foreach ($stmt->fetchAll() as $row) {
            try {
                $categories = json_decode((string) $row['categories'], true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $categories = [];
            }
            $reviews[] = [
                'id' => (int) $row['id'],
                'source' => (string) $row['source'],
                'author_name' => $row['author_name'] !== null ? (string) $row['author_name'] : null,
                'rating' => $row['rating'] !== null ? (float) $row['rating'] : null,
                'raw_text' => (string) $row['raw_text'],
                'sentiment' => (string) $row['sentiment'],
                'score' => (float) $row['score'],
                'categories' => is_array($categories) ? $categories : [],
                'summary' => (string) $row['summary'],
                'requires_action' => (bool) $row['requires_action'],
                'source_url' => $row['source_url'] !== null ? (string) $row['source_url'] : null,
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $reviews;
    }

    public function countForCompany(int $companyId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM reviews WHERE company_id = :company_id');
        $stmt->execute(['company_id' => $companyId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Aggregated tenant metrics for the analytics dashboard.
     *
     * @return array{
     *     totals: array{total_reviews: int, average_score: float, average_rating: ?float, urgent_action_count: int},
     *     sentiment_breakdown: array{positive: int, neutral: int, negative: int},
     *     top_complaint_categories: list<array{category: string, count: int}>,
     *     trend: list<array{date: string, reviews: int, average_score: ?float}>,
     *     recent_urgent_reviews: list<array<string, mixed>>
     * }
     */
    public function analytics(
        int $companyId,
        DateTimeInterface $from,
        DateTimeInterface $to,
        int $topComplaintsLimit = 5,
        int $recentUrgentLimit = 10,
    ): array {
        return [
            'totals' => $this->fetchTotals($companyId, $from, $to),
            'sentiment_breakdown' => $this->fetchSentimentBreakdown($companyId, $from, $to),
            'top_complaint_categories' => $this->fetchTopComplaintCategories($companyId, $from, $to, $topComplaintsLimit),
            'trend' => $this->fetchTrend($companyId, $from, $to),
            'recent_urgent_reviews' => $this->fetchRecentUrgentReviews($companyId, $from, $to, $recentUrgentLimit),
        ];
    }

    /** @return list<array{date: string, reviews: int, average_score: ?float}> */
    private function fetchTrend(int $companyId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $sql = <<<'SQL'
            SELECT
                DATE(created_at) AS day,
                COUNT(*)         AS review_count,
                AVG(score)       AS average_score
            FROM reviews
            WHERE company_id = :company_id
              AND created_at >= :from_date
              AND created_at <= :to_date
            GROUP BY DATE(created_at)
            ORDER BY day ASC
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'company_id' => $companyId,
            'from_date' => $from->format('Y-m-d H:i:s'),
            'to_date' => $to->format('Y-m-d H:i:s'),
        ]);

        $byDay = [];
        foreach ($stmt->fetchAll() as $row) {
            $byDay[(string) $row['day']] = [
                'reviews' => (int) $row['review_count'],
                'average_score' => $row['average_score'] === null ? null : round((float) $row['average_score'], 3),
            ];
        }

        $trend = [];
        $cursor = new DateTimeImmutable($from->format('Y-m-d'));
        $last = new DateTimeImmutable($to->format('Y-m-d'));

        while ($cursor <= $last) {
            $key = $cursor->format('Y-m-d');
            $trend[] = [
                'date' => $key,
                'reviews' => $byDay[$key]['reviews'] ?? 0,
                'average_score' => $byDay[$key]['average_score'] ?? null,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $trend;
    }

    /** @return array{total_reviews: int, average_score: float, average_rating: ?float, urgent_action_count: int} */
    private function fetchTotals(int $companyId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $sql = <<<'SQL'
            SELECT
                COUNT(*)                                              AS total_reviews,
                COALESCE(AVG(score), 0)                               AS average_score,
                AVG(rating)                                           AS average_rating,
                COALESCE(SUM(requires_action), 0)                     AS urgent_action_count
            FROM reviews
            WHERE company_id = :company_id
              AND created_at >= :from_date
              AND created_at <= :to_date
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'company_id' => $companyId,
            'from_date' => $from->format('Y-m-d H:i:s'),
            'to_date' => $to->format('Y-m-d H:i:s'),
        ]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch() ?: [];

        $averageRating = $row['average_rating'] ?? null;

        return [
            'total_reviews' => (int) ($row['total_reviews'] ?? 0),
            'average_score' => round((float) ($row['average_score'] ?? 0), 3),
            'average_rating' => ($averageRating === null || $averageRating === '')
                ? null
                : round((float) $averageRating, 2),
            'urgent_action_count' => (int) ($row['urgent_action_count'] ?? 0),
        ];
    }

    /** @return array{positive: int, neutral: int, negative: int} */
    private function fetchSentimentBreakdown(int $companyId, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $sql = <<<'SQL'
            SELECT sentiment, COUNT(*) AS review_count
            FROM reviews
            WHERE company_id = :company_id
              AND created_at >= :from_date
              AND created_at <= :to_date
            GROUP BY sentiment
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'company_id' => $companyId,
            'from_date' => $from->format('Y-m-d H:i:s'),
            'to_date' => $to->format('Y-m-d H:i:s'),
        ]);

        $breakdown = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $sentiment = (string) $row['sentiment'];
            if (array_key_exists($sentiment, $breakdown)) {
                $breakdown[$sentiment] = (int) $row['review_count'];
            }
        }

        return $breakdown;
    }

    /**
     * Top topics across negative reviews (MySQL 8 JSON_TABLE expansion of the
     * categories string[] column).
     *
     * @return list<array{category: string, count: int}>
     */
    private function fetchTopComplaintCategories(
        int $companyId,
        DateTimeInterface $from,
        DateTimeInterface $to,
        int $limit,
    ): array {
        $sql = <<<'SQL'
            SELECT jt.category AS category, COUNT(*) AS complaint_count
            FROM reviews r
            JOIN JSON_TABLE(
                r.categories,
                '$[*]' COLUMNS (category VARCHAR(100) PATH '$')
            ) AS jt
            WHERE r.company_id = :company_id
              AND r.sentiment = 'negative'
              AND r.created_at >= :from_date
              AND r.created_at <= :to_date
            GROUP BY jt.category
            ORDER BY complaint_count DESC, jt.category ASC
            LIMIT :top_limit
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('company_id', $companyId, PDO::PARAM_INT);
        $stmt->bindValue('from_date', $from->format('Y-m-d H:i:s'));
        $stmt->bindValue('to_date', $to->format('Y-m-d H:i:s'));
        $stmt->bindValue('top_limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $categories = [];
        foreach ($stmt->fetchAll() as $row) {
            $categories[] = [
                'category' => (string) $row['category'],
                'count' => (int) $row['complaint_count'],
            ];
        }

        return $categories;
    }

    /** @return list<array<string, mixed>> */
    private function fetchRecentUrgentReviews(
        int $companyId,
        DateTimeInterface $from,
        DateTimeInterface $to,
        int $limit,
    ): array {
        $sql = <<<'SQL'
            SELECT id, source, author_name, rating, raw_text, sentiment, score,
                   categories, summary, source_url, created_at
            FROM reviews
            WHERE company_id = :company_id
              AND requires_action = 1
              AND created_at >= :from_date
              AND created_at <= :to_date
            ORDER BY created_at DESC
            LIMIT :limit
            SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('company_id', $companyId, PDO::PARAM_INT);
        $stmt->bindValue('from_date', $from->format('Y-m-d H:i:s'));
        $stmt->bindValue('to_date', $to->format('Y-m-d H:i:s'));
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $reviews = [];
        foreach ($stmt->fetchAll() as $row) {
            try {
                $categories = json_decode((string) $row['categories'], true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $categories = [];
            }

            $reviews[] = [
                'id' => (int) $row['id'],
                'source' => (string) $row['source'],
                'author_name' => $row['author_name'] !== null ? (string) $row['author_name'] : null,
                'rating' => $row['rating'] !== null ? (float) $row['rating'] : null,
                'raw_text' => (string) $row['raw_text'],
                'sentiment' => (string) $row['sentiment'],
                'score' => (float) $row['score'],
                'categories' => is_array($categories) ? $categories : [],
                'summary' => (string) $row['summary'],
                'source_url' => $row['source_url'] !== null ? (string) $row['source_url'] : null,
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $reviews;
    }
}
