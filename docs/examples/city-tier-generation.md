# City Tier Generation Example

This example demonstrates multi-dimensional data classification and rating with evidence-based scoring across multiple criteria.

## Overview

**What we're building**: A comprehensive city rating system that evaluates cities across multiple dimensions (safety, cost of living, job market, etc.) with transparent, evidence-backed scores.

**Use case**: Comparison websites, recommendation engines, data classification, entity ranking, multi-criteria decision systems.

**Difficulty**: Intermediate

**Key Features**:
- Multi-dimensional scoring
- Per-dimension work items (parallel evaluation)
- Citation requirements
- Score-to-tier mapping
- Aggregated ratings
- Data recency validation

## Complete Code

The complete working code is at `/Users/gpriday/Projects/Laravel/laravel-work-manager/examples/CityTierGenerationType.php`. Here are the key sections:

### Schema: Define Dimensions

```php
public function schema(): array
{
    return [
        'type' => 'object',
        'required' => ['city_id', 'dimensions'],
        'properties' => [
            'city_id' => ['type' => 'integer'],
            'city_name' => ['type' => 'string'],
            'country_code' => ['type' => 'string', 'pattern' => '^[A-Z]{2}$'],
            'dimensions' => [
                'type' => 'array',
                'items' => [
                    'type' => 'string',
                    'enum' => [
                        'safety', 'cost_of_living', 'job_market', 'housing',
                        'internet_quality', 'public_transport', 'healthcare',
                        'education', 'climate', 'culture', 'food_scene',
                        'outdoor_activities', 'nightlife', 'walkability',
                        'air_quality', 'diversity',
                    ],
                ],
            ],
            'min_sources_per_dimension' => [
                'type' => 'integer',
                'minimum' => 1,
                'default' => 2,
            ],
        ],
    ];
}
```

### Planning: One Item Per Dimension

This allows specialist agents to focus on specific dimensions:

```php
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
```

Benefits:
- Different agents can specialize (e.g., safety expert, housing expert)
- Parallel processing across dimensions
- Easier to retry failed dimensions independently

### Submission Requirements

Agents must provide structured scores with evidence:

```php
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
        'citations.*.title' => 'required|string',
        'citations.*.data_point' => 'required|string',
        'citations.*.retrieved_at' => 'required|date',
        'citations.*.year' => 'required|integer|min:2020|max:'.(now()->year + 1),
        'metadata' => 'nullable|array',
    ];
}
```

### Score-to-Tier Mapping

Ensure consistency between numeric scores and letter grades:

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
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

    // Verify explanation mentions data
    if (!preg_match('/\d/', strtolower($result['explanation']))) {
        throw ValidationException::withMessages([
            'explanation' => ['Explanation must reference specific data points'],
        ]);
    }
}

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
```

### Apply: Calculate Overall Tier

```php
public function apply(WorkOrder $order): Diff
{
    $cityId = $order->payload['city_id'];

    DB::transaction(function () use ($order, $cityId) {
        // Clear existing ratings
        CityRating::where('city_id', $cityId)->delete();

        $totalScore = 0;
        $dimensionCount = 0;

        foreach ($order->items as $item) {
            if (!$item->result) continue;

            CityRating::create([
                'city_id' => $cityId,
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

        // Calculate overall tier
        $overallScore = $dimensionCount > 0 ? round($totalScore / $dimensionCount, 2) : 0;
        $overallTier = $this->scoreTier($overallScore);

        City::where('id', $cityId)->update([
            'tier' => $overallTier,
            'overall_score' => $overallScore,
            'dimension_count' => $dimensionCount,
            'rated_at' => now(),
        ]);
    });

    return $this->makeDiff($before, $after, "Generated ratings");
}
```

## Example API Workflow

### 1. Propose City Rating Order

```bash
curl -X POST http://your-app.test/api/ai/work/propose \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: city-rating-$(date +%s)" \
  -d '{
    "type": "city.tier.generate",
    "payload": {
      "city_id": 42,
      "city_name": "Austin",
      "country_code": "US",
      "dimensions": [
        "safety",
        "cost_of_living",
        "job_market",
        "housing",
        "internet_quality"
      ],
      "min_sources_per_dimension": 2
    }
  }'
```

**Result**: 5 work items created (one per dimension).

### 2. Agent Evaluates One Dimension

Agent researches safety data for Austin:

```bash
curl -X POST http://your-app.test/api/ai/work/items/item-safety-uuid/submit \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: submit-safety-$(date +%s)" \
  -H "X-Agent-ID: safety-evaluator" \
  -d '{
    "result": {
      "dimension": "safety",
      "score": 7.5,
      "tier": "B",
      "explanation": "Austin ranks well for safety with a violent crime rate of 4.2 per 1,000 residents (below national average of 5.0). Property crime is at 24.3 per 1,000, slightly above national average. City has invested in community policing programs and emergency response infrastructure.",
      "key_points": [
        "Violent crime rate: 4.2 per 1,000 residents (16% below national average)",
        "Property crime rate: 24.3 per 1,000 (8% above national average)",
        "Strong police-to-resident ratio: 2.1 officers per 1,000 people",
        "Well-rated emergency services with 5-minute average response time",
        "Low gang activity compared to similar-sized cities"
      ],
      "citations": [
        {
          "url": "https://fbi.gov/crime-stats/austin-2024",
          "title": "FBI Uniform Crime Report - Austin, TX 2024",
          "data_point": "Violent crime: 4.2 per 1,000; Property crime: 24.3 per 1,000",
          "retrieved_at": "2025-01-22T10:00:00Z",
          "year": 2024,
          "source_type": "official"
        },
        {
          "url": "https://austintexas.gov/police/annual-report-2024",
          "title": "Austin PD Annual Report 2024",
          "data_point": "Officer-to-resident ratio: 2.1 per 1,000; Avg response: 5 min",
          "retrieved_at": "2025-01-22T10:05:00Z",
          "year": 2024,
          "source_type": "official"
        },
        {
          "url": "https://neighborhoodscout.com/tx/austin/crime",
          "title": "NeighborhoodScout Crime Ratings",
          "data_point": "Safety index: 42/100 (safer than 42% of US cities)",
          "retrieved_at": "2025-01-22T10:10:00Z",
          "year": 2024,
          "source_type": "research"
        }
      ],
      "metadata": {
        "units": "per 1,000 residents",
        "sample_size": null
      }
    }
  }'
```

### 3. Submit Cost of Living Dimension

```bash
curl -X POST http://your-app.test/api/ai/work/items/item-col-uuid/submit \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: submit-col-$(date +%s)" \
  -d '{
    "result": {
      "dimension": "cost_of_living",
      "score": 6.0,
      "tier": "C",
      "explanation": "Austin cost of living index is 119 (19% above national average of 100). Housing costs drive the premium with median rent at $1,650/month. Groceries and utilities near national average. No state income tax partially offsets higher housing costs.",
      "key_points": [
        "Overall cost of living index: 119 (19% above national avg)",
        "Median rent: $1,650/month for 1BR apartment",
        "Groceries: 2% above national average",
        "No state income tax (saves avg 5-7% of income)",
        "Gas prices: 8% below national average"
      ],
      "citations": [
        {
          "url": "https://bestplaces.net/cost_of_living/city/texas/austin",
          "title": "BestPlaces Cost of Living - Austin",
          "data_point": "Cost of living index: 119; Housing index: 159",
          "retrieved_at": "2025-01-22T10:20:00Z",
          "year": 2024,
          "source_type": "research"
        },
        {
          "url": "https://apartmentlist.com/renter-life/average-rent-in-austin",
          "title": "Average Rent in Austin, TX",
          "data_point": "Median 1BR rent: $1,650/mo; Median 2BR: $2,100/mo",
          "retrieved_at": "2025-01-22T10:22:00Z",
          "year": 2024,
          "source_type": "commercial"
        }
      ],
      "metadata": {
        "currency": "USD",
        "units": "index (100 = national average)"
      }
    }
  }'
```

### 4. Other Agents Submit Remaining Dimensions

Job market, housing, and internet quality dimensions are submitted by other agents...

### 5. Approve and Calculate Overall Tier

```bash
curl -X POST http://your-app.test/api/ai/work/orders/order-uuid/approve \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: approve-$(date +%s)"
```

**Response:**

```json
{
  "order": {
    "id": "order-uuid",
    "state": "completed"
  },
  "diff": {
    "before": {
      "tier": null,
      "overall_score": null,
      "dimension_count": 0
    },
    "after": {
      "tier": "B",
      "overall_score": 7.2,
      "dimension_count": 5
    },
    "summary": "Generated ratings for 5 dimensions for city 42. Overall: B (7.2/10)"
  }
}
```

## Key Learnings

### 1. Multi-Dimensional Scoring Pattern

Break complex evaluations into independent dimensions:
- Each dimension evaluated separately
- Parallel processing by specialist agents
- Aggregate scores to calculate overall rating
- Transparent methodology (all scores visible)

### 2. Evidence-Based Ratings

Require citations for every score:
- Minimum number of sources per dimension
- Recent data only (< 3 years old)
- Source type categorization (official, research, commercial)
- Specific data points, not just URLs

### 3. Consistency Validation

Ensure internal consistency:
- Score must map to correct tier
- Explanation must reference numbers
- Key points must be substantive
- Metadata must be appropriate for dimension type

### 4. Dimension-Specific Requirements

Different dimensions have different needs:
- Cost-related: Require currency metadata
- Safety: Require per-capita rates
- Quality metrics: Require sample sizes or confidence intervals

## Variations and Extensions

### Variation 1: Weighted Dimensions

Give different importance to different dimensions:

```php
protected function getDimensionWeight(string $dimension): float
{
    return match($dimension) {
        'safety', 'cost_of_living', 'job_market' => 1.5, // Critical dimensions
        'healthcare', 'education' => 1.25, // Important
        'nightlife', 'food_scene' => 0.75, // Nice-to-have
        default => 1.0,
    };
}

public function apply(WorkOrder $order): Diff
{
    $weightedSum = 0;
    $totalWeight = 0;

    foreach ($order->items as $item) {
        $weight = $this->getDimensionWeight($item->result['dimension']);
        $weightedSum += $item->result['score'] * $weight;
        $totalWeight += $weight;
    }

    $overallScore = $totalWeight > 0 ? round($weightedSum / $totalWeight, 2) : 0;

    // ...
}
```

### Variation 2: User-Specific Ratings

Personalize ratings based on user preferences:

```php
public function schema(): array
{
    return [
        'type' => 'object',
        'required' => ['city_id', 'dimensions'],
        'properties' => [
            // ... existing properties
            'user_profile' => [
                'type' => 'object',
                'properties' => [
                    'priorities' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Dimensions user cares most about',
                    ],
                    'budget' => ['type' => 'number'],
                    'family_size' => ['type' => 'integer'],
                ],
            ],
        ],
    ];
}
```

### Variation 3: Comparative Ratings

Rate cities relative to each other:

```php
protected function afterApply(WorkOrder $order, Diff $diff): void
{
    $cityId = $order->payload['city_id'];
    $city = City::find($cityId);

    // Calculate percentile rankings
    $allCities = City::whereNotNull('overall_score')->get();

    foreach (['safety', 'cost_of_living', 'job_market'] as $dimension) {
        $rating = CityRating::where('city_id', $cityId)
            ->where('dimension', $dimension)
            ->first();

        if ($rating) {
            $percentile = $allCities
                ->where("${dimension}_score", '<=', $rating->score)
                ->count() / $allCities->count() * 100;

            $rating->update(['percentile' => round($percentile)]);
        }
    }
}
```

### Variation 4: Temporal Tracking

Track how ratings change over time:

```php
protected function beforeApply(WorkOrder $order): void
{
    $cityId = $order->payload['city_id'];

    // Archive previous ratings
    CityRating::where('city_id', $cityId)
        ->whereNull('archived_at')
        ->update([
            'archived_at' => now(),
            'replaced_by_order_id' => $order->id,
        ]);
}

// Then analyze trends:
// - Safety improving or declining?
// - Cost of living accelerating?
// - Job market recovering?
```

### Variation 5: Confidence Intervals

Add statistical confidence to scores:

```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        // ... existing rules
        'confidence_interval' => 'nullable|array',
        'confidence_interval.lower' => 'required_with:confidence_interval|numeric|min:0|max:10',
        'confidence_interval.upper' => 'required_with:confidence_interval|numeric|min:0|max:10',
        'sample_size' => 'nullable|integer|min:1',
    ];
}
```

## Next Steps

1. **Build Comparison Engine**: Compare multiple cities side-by-side
2. **Add Trend Analysis**: Track dimension scores over time
3. **Build Recommendation System**: Suggest cities based on user preferences
4. **Add Data Freshness Alerts**: Notify when ratings need refreshing

## Troubleshooting

### Score-Tier Mismatch

If validation fails on tier:
- Check the `scoreTier()` mapping function
- Ensure score is in correct range (0-10)
- Verify tier is one of A, B, C, D, F

### Citations Too Old

If data is rejected as stale:
- Find more recent sources
- Adjust `min_year` in validation if needed
- Consider if dimension changes slowly (e.g., climate vs job market)

### Explanation Too Vague

If explanation validation fails:
- Include specific numbers and data points
- Reference citations by name
- Compare to benchmarks or averages
- Explain methodology (how score was calculated)

### Overall Score Doesn't Match Expectations

If aggregate score seems wrong:
- Verify all dimensions have valid scores
- Check for outliers (one very low score)
- Consider if weighting is appropriate
- Review individual dimension explanations

## Real-World Applications

This pattern works for:
- **Product ratings**: Features, performance, value, support
- **Restaurant reviews**: Food quality, service, ambiance, value
- **Employee performance**: Multiple competency dimensions
- **Supplier scorecards**: Quality, delivery, cost, service
- **Content quality**: Accuracy, readability, depth, freshness
