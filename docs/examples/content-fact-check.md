# Content Fact-Check Example

This example demonstrates a content verification workflow where agents research and verify factual claims before publication.

## Overview

**What we're building**: An automated fact-checking system that verifies claims in content (blog posts, marketing materials, knowledge base articles) with evidence-based validation.

**Use case**: Editorial workflows, compliance verification, misinformation prevention, legal risk reduction.

**Difficulty**: Intermediate

**Key Features**:
- Claim extraction and verification
- Evidence requirement with source credibility
- Confidence scoring
- Approval gates based on findings
- Multi-item parallelization (one item per claim)

## Complete Code

The complete working code is at `/Users/gpriday/Projects/Laravel/laravel-work-manager/examples/ContentFactCheckType.php`. Here are the key sections explained:

### Schema Definition

```php
public function schema(): array
{
    return [
        'type' => 'object',
        'required' => ['content_id', 'policy_version', 'claims'],
        'properties' => [
            'content_id' => ['type' => 'integer'],
            'policy_version' => ['type' => 'string'],
            'claims' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'required' => ['id', 'text', 'type'],
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'text' => ['type' => 'string'],
                        'type' => ['type' => 'string', 'enum' => ['statistic', 'quote', 'fact', 'comparison']],
                        'context' => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ];
}
```

### Planning: One Item Per Claim

This enables parallel fact-checking:

```php
public function plan(WorkOrder $order): array
{
    return array_map(fn ($claim) => [
        'type' => $this->type(),
        'input' => [
            'claim_id' => $claim['id'],
            'claim_text' => $claim['text'],
            'claim_type' => $claim['type'],
            'context' => $claim['context'] ?? null,
            'content_id' => $order->payload['content_id'],
        ],
        'max_attempts' => 2,
    ], $order->payload['claims']);
}
```

### Submission Validation

Agents must provide verdicts with evidence:

```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'claim_id' => 'required|string',
        'verdict' => 'required|in:verified,false,inconclusive,outdated',
        'confidence' => 'required|numeric|min:0|max:1',
        'explanation' => 'required|string|min:50|max:2000',
        'evidence' => 'required|array|min:1|max:10',
        'evidence.*.url' => 'required|url',
        'evidence.*.title' => 'required|string',
        'evidence.*.quote' => 'nullable|string',
        'evidence.*.retrieved_at' => 'required|date',
        'evidence.*.source_credibility' => 'required|in:high,medium,low',
        'corrections' => 'nullable|array',
    ];
}
```

### Custom Validation Rules

Different claim types have different requirements:

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    // High-confidence false claims must have corrections
    if (in_array($result['verdict'], ['false', 'outdated']) && $result['confidence'] >= 0.8) {
        if (empty($result['corrections'])) {
            throw ValidationException::withMessages([
                'corrections' => ['High-confidence false claims require corrections'],
            ]);
        }
    }

    // Verified claims require high-credibility sources
    if ($result['verdict'] === 'verified') {
        $hasHighCredibility = collect($result['evidence'])
            ->contains('source_credibility', 'high');

        if (!$hasHighCredibility) {
            throw ValidationException::withMessages([
                'evidence' => ['Verified claims require at least one high-credibility source'],
            ]);
        }
    }

    // Statistics require recent evidence (< 2 years)
    if ($item->input['claim_type'] === 'statistic') {
        foreach ($result['evidence'] as $evidence) {
            $retrievedAt = \Carbon\Carbon::parse($evidence['retrieved_at']);
            if ($retrievedAt->diffInYears(now()) > 2) {
                throw ValidationException::withMessages([
                    'evidence.retrieved_at' => ['Statistics require recent evidence'],
                ]);
            }
        }
    }
}
```

### Approval Gate

Block approval if high-confidence false claims exist:

```php
protected function canApprove(WorkOrder $order): bool
{
    foreach ($order->items as $item) {
        if (!$item->result) continue;

        $verdict = $item->result['verdict'];
        $confidence = $item->result['confidence'];

        // Block on high-confidence false/outdated claims
        if (in_array($verdict, ['false', 'outdated']) && $confidence >= 0.7) {
            return false;
        }
    }

    return true;
}
```

### Apply: Write Fact-Check Records

```php
public function apply(WorkOrder $order): Diff
{
    $contentId = $order->payload['content_id'];

    DB::transaction(function () use ($order, $contentId) {
        // Clear existing fact-checks
        ContentFactCheck::where('content_id', $contentId)->delete();

        foreach ($order->items as $item) {
            if (!$item->result) continue;

            ContentFactCheck::create([
                'content_id' => $contentId,
                'claim_id' => $item->result['claim_id'],
                'claim_text' => $item->input['claim_text'],
                'verdict' => $item->result['verdict'],
                'confidence' => $item->result['confidence'],
                'explanation' => $item->result['explanation'],
                'evidence' => $item->result['evidence'],
                'corrections' => $item->result['corrections'] ?? null,
                'checked_at' => now(),
            ]);
        }

        // Mark content as fact-checked
        Content::where('id', $contentId)->update([
            'status' => 'fact_checked',
            'fact_checked_at' => now(),
        ]);
    });

    return $this->makeDiff($before, $after, "Fact-checked content");
}
```

## Example API Workflow

### 1. Propose Fact-Check Order

```bash
curl -X POST http://your-app.test/api/agent/work/propose \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: factcheck-$(date +%s)" \
  -d '{
    "type": "content.factcheck",
    "payload": {
      "content_id": 123,
      "policy_version": "2024.1",
      "claims": [
        {
          "id": "claim-1",
          "text": "Our product has 10,000+ active users",
          "type": "statistic",
          "context": "Product marketing page",
          "line_number": 15
        },
        {
          "id": "claim-2",
          "text": "Rated #1 by TechCrunch in 2024",
          "type": "fact",
          "context": "Homepage hero section",
          "line_number": 3
        }
      ]
    }
  }'
```

**Result**: 2 work items created (one per claim), agents can work in parallel.

### 2. Agent Checks Out and Researches Claim

Agent researches the claim using web search, official sources, databases, etc.

### 3. Submit Verification Result

```bash
curl -X POST http://your-app.test/api/agent/work/items/item-uuid/submit \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: submit-$(date +%s)" \
  -H "X-Agent-ID: factcheck-agent" \
  -d '{
    "result": {
      "claim_id": "claim-1",
      "verdict": "verified",
      "confidence": 0.95,
      "explanation": "Analytics dashboard shows 12,345 active users as of Jan 2025. The claim of 10,000+ is verified and conservative.",
      "evidence": [
        {
          "url": "https://analytics.company.com/public-stats",
          "title": "Public Analytics Dashboard",
          "quote": "Active users (30 days): 12,345",
          "retrieved_at": "2025-01-22T10:00:00Z",
          "source_credibility": "high",
          "published_date": "2025-01-22"
        },
        {
          "url": "https://company.com/blog/growth-2024",
          "title": "2024 Growth Report",
          "quote": "We surpassed 10,000 users in Q3 2024",
          "retrieved_at": "2025-01-22T10:01:00Z",
          "source_credibility": "high",
          "published_date": "2024-12-15"
        }
      ]
    }
  }'
```

### 4. Submit False Claim with Correction

```bash
curl -X POST http://your-app.test/api/agent/work/items/item-uuid-2/submit \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: submit-2-$(date +%s)" \
  -H "X-Agent-ID: factcheck-agent" \
  -d '{
    "result": {
      "claim_id": "claim-2",
      "verdict": "false",
      "confidence": 0.9,
      "explanation": "TechCrunch did not publish a #1 ranking for this product in 2024. The product was mentioned in a roundup article but not ranked #1.",
      "evidence": [
        {
          "url": "https://techcrunch.com/2024/products-roundup",
          "title": "Best Products of 2024",
          "quote": "Among the notable products this year...",
          "retrieved_at": "2025-01-22T10:15:00Z",
          "source_credibility": "high",
          "published_date": "2024-12-20"
        }
      ],
      "corrections": [
        {
          "original": "Rated #1 by TechCrunch in 2024",
          "corrected": "Featured in TechCrunch Best Products of 2024",
          "source": "https://techcrunch.com/2024/products-roundup",
          "reasoning": "Product was featured but not ranked #1"
        }
      ]
    }
  }'
```

### 5. Attempt Approval (Blocked)

```bash
curl -X POST http://your-app.test/api/agent/work/orders/order-uuid/approve \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: approve-$(date +%s)"
```

**Response (Error 422):**

```json
{
  "message": "Order cannot be approved",
  "reason": "High-confidence false claims must be corrected before approval",
  "claims_requiring_correction": ["claim-2"]
}
```

### 6. Editor Corrects Content

The editor updates the content based on the correction suggestion, then resubmits for fact-checking or directly approves if corrections are acceptable.

## Key Learnings

### 1. Claim Type Strategy

Different claim types require different verification:
- **Statistics**: Recent sources, numerical verification
- **Quotes**: Actual quote text required, attribution verification
- **Facts**: Multiple corroborating sources
- **Comparisons**: Context-specific benchmarking

### 2. Source Credibility Levels

Define clear credibility criteria:
- **High**: Official sources, peer-reviewed, government data
- **Medium**: Reputable news, industry reports, verified accounts
- **Low**: User-generated, unverified, anonymous

### 3. Confidence Scoring

Use confidence to determine action:
- `>= 0.8`: High confidence, strong evidence
- `0.5 - 0.8`: Moderate confidence, review recommended
- `< 0.5`: Low confidence, requires more research

### 4. Corrections Format

Structured corrections help editors:
- Original text (what's wrong)
- Corrected text (what it should be)
- Source (evidence for correction)
- Reasoning (why the correction is needed)

## Variations and Extensions

### Variation 1: Automated Claim Extraction

Instead of providing claims in payload, extract them automatically:

```php
public function schema(): array
{
    return [
        'type' => 'object',
        'required' => ['content_id'],
        'properties' => [
            'content_id' => ['type' => 'integer'],
            'auto_extract_claims' => ['type' => 'boolean', 'default' => true],
        ],
    ];
}

public function plan(WorkOrder $order): array
{
    $content = Content::find($order->payload['content_id']);

    if ($order->payload['auto_extract_claims'] ?? true) {
        // Use AI to extract factual claims from content
        $claims = $this->extractClaims($content->body);
    } else {
        $claims = $order->payload['claims'];
    }

    return array_map(fn($claim) => [...], $claims);
}
```

### Variation 2: Tiered Verification

Require more sources for critical claims:

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    $claimImportance = $item->input['importance'] ?? 'normal';

    $minSources = match($claimImportance) {
        'critical' => 3,
        'high' => 2,
        default => 1,
    };

    $highCredSources = collect($result['evidence'])
        ->where('source_credibility', 'high')
        ->count();

    if ($highCredSources < $minSources) {
        throw ValidationException::withMessages([
            'evidence' => ["Require {$minSources} high-credibility sources"],
        ]);
    }
}
```

### Variation 3: Fact-Check Expiration

Track when fact-checks become stale:

```php
protected function afterApply(WorkOrder $order, Diff $diff): void
{
    $contentId = $order->payload['content_id'];

    // Set expiration based on claim types
    $expiresAt = now()->addMonths(6); // Default 6 months

    foreach ($order->items as $item) {
        if ($item->input['claim_type'] === 'statistic') {
            $expiresAt = now()->addMonths(3); // Statistics expire faster
        }
    }

    Content::where('id', $contentId)->update([
        'fact_check_expires_at' => $expiresAt,
    ]);
}
```

### Variation 4: External Fact-Check APIs

Integrate with third-party fact-checking services:

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    // Cross-reference with external fact-check database
    $claim = $item->input['claim_text'];
    $externalResult = Http::get('https://factcheck-api.com/verify', [
        'query' => $claim,
    ])->json();

    if ($externalResult['found'] && $externalResult['rating'] !== $result['verdict']) {
        Log::warning('Verdict mismatch with external API', [
            'claim' => $claim,
            'our_verdict' => $result['verdict'],
            'external_verdict' => $externalResult['rating'],
        ]);
    }
}
```

## Next Steps

1. **Try Multi-Dimensional Scoring**: See [city-tier-generation.md](./city-tier-generation.md)
2. **Build Claim Extraction**: Use AI to automatically identify claims
3. **Add Source Verification**: Verify URLs are accessible and contain quotes
4. **Build Editor Dashboard**: UI for reviewing fact-check results

## Troubleshooting

### Approval Blocked by False Claims

If approval is blocked:
- Review the false claims and corrections
- Update the content to fix inaccuracies
- Either resubmit for fact-checking or manually approve if corrections are acceptable

### Evidence URL Validation Fails

If evidence URLs are unreachable:
- Verify URLs are publicly accessible
- Use archived versions (Wayback Machine)
- Provide alternative sources

### Confidence Score Too Low

If confidence is consistently low:
- Improve research methodology
- Use more authoritative sources
- Consider the claim as "inconclusive" rather than verified
