<?php

namespace App\WorkTypes;

use App\Models\City;
use App\Models\CityRating;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\AbstractOrderType;
use GregPriday\WorkManager\Support\Diff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Example: City/Entity Tier & Rating System
 *
 * This order type generates comprehensive ratings for cities (or products, services) across
 * multiple dimensions. Each dimension requires research, scoring, and evidence from multiple sources.
 *
 * Use Case:
 * - Automated content generation for comparison sites
 * - Data-driven rankings and recommendations
 * - Transparent, evidence-based ratings
 * - Regular updates to keep data fresh
 *
 * Workflow:
 * 1. City is added to database or scheduled for refresh
 * 2. System creates order with city_id and desired dimensions
 * 3. Order is planned into work items (one per dimension)
 * 4. Agents lease dimensions, research data, calculate scores with evidence
 * 5. System validates scoring logic, citation recency, and evidence quality
 * 6. When all dimensions complete, order is approved
 * 7. Apply writes dimension ratings and calculates overall city tier
 * 8. City profile is updated with new ratings and timestamp
 */
class CityTierGenerationType extends AbstractOrderType
{
    /**
     * Unique identifier for this work order type.
     */
    public function type(): string
    {
        return 'city.tier.generate';
    }

    /**
     * JSON schema for validating the initial payload.
     *
     * Required: city_id, dimensions
     * Optional: min_sources_per_dimension
     */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['city_id', 'dimensions'],
            'properties' => [
                'city_id' => [
                    'type' => 'integer',
                    'description' => 'Database ID of the city to rate',
                ],
                'city_name' => [
                    'type' => 'string',
                    'description' => 'Name of the city (for agent context)',
                ],
                'country_code' => [
                    'type' => 'string',
                    'pattern' => '^[A-Z]{2}$',
                    'description' => 'ISO 3166-1 alpha-2 country code',
                ],
                'dimensions' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'description' => 'Dimensions to rate',
                    'items' => [
                        'type' => 'string',
                        'enum' => [
                            'safety',
                            'cost_of_living',
                            'job_market',
                            'housing',
                            'internet_quality',
                            'public_transport',
                            'healthcare',
                            'education',
                            'climate',
                            'culture',
                            'food_scene',
                            'outdoor_activities',
                            'nightlife',
                            'walkability',
                            'air_quality',
                            'diversity',
                        ],
                    ],
                ],
                'min_sources_per_dimension' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 10,
                    'default' => 2,
                    'description' => 'Minimum number of citations required per dimension',
                ],
            ],
        ];
    }

    /**
     * Break the order into work items (one per dimension).
     *
     * This allows different specialist agents to pick up dimensions they're good at.
     */
    public function plan(WorkOrder $order): array
    {
        return array_map(fn ($dimension) => [
            'type' => $this->type(),
            'input' => [
                'city_id' => $order->payload['city_id'],
                'city_name' => $order->payload['city_name'],
                'country_code' => $order->payload['country_code'],
                'dimension' => $dimension,
                'min_sources' => $order->payload['min_sources_per_dimension'] ?? 2,
            ],
            'max_attempts' => 3,
        ], $order->payload['dimensions']);
    }

    /**
     * Laravel validation rules for agent submissions.
     *
     * Each submission must include:
     * - dimension identifier
     * - numeric score (0-10)
     * - letter tier (A-F)
     * - explanation (min 100 chars)
     * - key points array
     * - citations with data points
     */
    protected function submissionValidationRules(WorkItem $item): array
    {
        return [
            'dimension' => 'required|string',
            'score' => 'required|numeric|min:0|max:10',
            'tier' => 'required|in:A,B,C,D,F',
            'explanation' => 'required|string|min:100|max:3000',
            'key_points' => 'required|array|min:3|max:10',
            'key_points.*' => 'string|max:500',
            'citations' => 'required|array|min:'.$item->input['min_sources'].'|max:10',
            'citations.*.url' => 'required|url',
            'citations.*.title' => 'required|string|max:500',
            'citations.*.data_point' => 'required|string|max:1000',
            'citations.*.retrieved_at' => 'required|date',
            'citations.*.year' => 'required|integer|min:2020|max:'.(now()->year + 1),
            'citations.*.source_type' => 'nullable|in:official,research,news,community,commercial',
            'metadata' => 'nullable|array',
            'metadata.currency' => 'nullable|string|size:3',
            'metadata.units' => 'nullable|string',
            'metadata.sample_size' => 'nullable|integer',
        ];
    }

    /**
     * Additional validation after Laravel rules pass.
     *
     * Business logic checks:
     * - Verify score aligns with tier
     * - Require recent data (< 3 years old)
     * - Validate dimension matches input
     * - Check for substantive key points
     */
    protected function afterValidateSubmission(WorkItem $item, array $result): void
    {
        // Verify dimension matches
        if ($result['dimension'] !== $item->input['dimension']) {
            throw ValidationException::withMessages([
                'dimension' => ["Expected dimension '{$item->input['dimension']}', got '{$result['dimension']}'"],
            ]);
        }

        // Verify score aligns with tier
        $score = $result['score'];
        $tier = $result['tier'];

        $expectedTier = $this->scoreTier($score);

        if ($tier !== $expectedTier) {
            throw ValidationException::withMessages([
                'tier' => ["Score {$score} should map to tier {$expectedTier}, got {$tier}"],
            ]);
        }

        // Require recent data (< 3 years old)
        $currentYear = now()->year;
        foreach ($result['citations'] as $citation) {
            if ($citation['year'] < $currentYear - 3) {
                throw ValidationException::withMessages([
                    'citations.year' => ['Citations must be from the last 3 years'],
                ]);
            }
        }

        // Check for substantive key points (not just vague statements)
        foreach ($result['key_points'] as $point) {
            if (strlen(trim($point)) < 20) {
                throw ValidationException::withMessages([
                    'key_points' => ['Key points must be substantive (min 20 characters each)'],
                ]);
            }
        }

        // Verify explanation mentions key data points
        $explanation = strtolower($result['explanation']);
        $mentionsData = false;

        // Check if explanation contains numbers/data
        if (preg_match('/\d/', $explanation)) {
            $mentionsData = true;
        }

        if (! $mentionsData) {
            throw ValidationException::withMessages([
                'explanation' => ['Explanation must reference specific data points or numbers'],
            ]);
        }

        // For cost-related dimensions, require currency metadata
        if (in_array($result['dimension'], ['cost_of_living', 'housing']) && empty($result['metadata']['currency'])) {
            throw ValidationException::withMessages([
                'metadata.currency' => ['Cost-related dimensions must specify currency'],
            ]);
        }
    }

    /**
     * Determine if the order is ready for approval.
     *
     * All required dimensions must be completed.
     */
    protected function canApprove(WorkOrder $order): bool
    {
        $requiredDimensions = $order->payload['dimensions'];
        $completedDimensions = $order->items
            ->whereIn('state', ['submitted', 'accepted', 'completed'])
            ->where('result', '!=', null)
            ->pluck('result')
            ->pluck('dimension')
            ->toArray();

        return count(array_diff($requiredDimensions, $completedDimensions)) === 0;
    }

    /**
     * Hook called before applying the work order.
     */
    protected function beforeApply(WorkOrder $order): void
    {
        $cityId = $order->payload['city_id'];

        Log::info('Starting city tier generation', [
            'order_id' => $order->id,
            'city_id' => $cityId,
            'dimension_count' => $order->items->count(),
        ]);

        // Could do things like:
        // - Verify city still exists
        // - Lock city to prevent concurrent rating updates
        // - Archive previous ratings
    }

    /**
     * Apply the ratings to the database.
     *
     * This is idempotent: can be called multiple times safely.
     */
    public function apply(WorkOrder $order): Diff
    {
        $cityId = $order->payload['city_id'];

        // Capture before state
        $before = [
            'tier' => City::find($cityId)->tier,
            'overall_score' => City::find($cityId)->overall_score,
            'rated_at' => City::find($cityId)->rated_at,
            'dimension_count' => CityRating::where('city_id', $cityId)->count(),
        ];

        DB::transaction(function () use ($order, $cityId) {
            // Clear existing ratings for this city (or use versioning with effective_from/effective_to)
            CityRating::where('city_id', $cityId)->delete();

            $totalScore = 0;
            $dimensionCount = 0;

            foreach ($order->items as $item) {
                if (! $item->result) {
                    continue;
                }

                CityRating::create([
                    'city_id' => $cityId,
                    'order_id' => $order->id,
                    'dimension' => $item->result['dimension'],
                    'score' => $item->result['score'],
                    'tier' => $item->result['tier'],
                    'explanation' => $item->result['explanation'],
                    'key_points' => $item->result['key_points'],
                    'citations' => $item->result['citations'],
                    'metadata' => $item->result['metadata'] ?? null,
                    'rated_at' => now(),
                ]);

                $totalScore += $item->result['score'];
                $dimensionCount++;
            }

            // Calculate overall tier and score
            $overallScore = $dimensionCount > 0 ? round($totalScore / $dimensionCount, 2) : 0;
            $overallTier = $this->scoreTier($overallScore);

            City::where('id', $cityId)->update([
                'tier' => $overallTier,
                'overall_score' => $overallScore,
                'dimension_count' => $dimensionCount,
                'rated_at' => now(),
            ]);
        });

        // Capture after state
        $after = [
            'tier' => City::find($cityId)->tier,
            'overall_score' => City::find($cityId)->overall_score,
            'rated_at' => City::find($cityId)->rated_at,
            'dimension_count' => CityRating::where('city_id', $cityId)->count(),
        ];

        return $this->makeDiff(
            $before,
            $after,
            "Generated ratings for {$order->items->count()} dimensions for city {$cityId}. Overall: {$after['tier']} ({$after['overall_score']}/10)"
        );
    }

    /**
     * Hook called after successful apply.
     */
    protected function afterApply(WorkOrder $order, Diff $diff): void
    {
        $cityId = $order->payload['city_id'];

        Log::info('Successfully applied city tier generation', [
            'order_id' => $order->id,
            'city_id' => $cityId,
            'changes' => $diff->toArray(),
        ]);

        // Trigger follow-up work
        // dispatch(new GenerateCityProfile($cityId));
        // dispatch(new UpdateRankings());
        // dispatch(new NotifyCitySubscribers($cityId));

        // Clear caches
        // Cache::tags(['cities', "city.{$cityId}", 'rankings'])->flush();

        // Send notification
        // event(new CityRated($cityId, $order));
    }

    /**
     * Convert numeric score to letter tier.
     */
    protected function scoreTier(float $score): string
    {
        return match (true) {
            $score >= 8.5 => 'A',
            $score >= 7.0 => 'B',
            $score >= 5.5 => 'C',
            $score >= 4.0 => 'D',
            default => 'F',
        };
    }
}
