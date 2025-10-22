# Customer Research with Partial Submissions

This advanced example demonstrates the partial submissions feature for long-running research tasks where agents can submit results incrementally.

## Overview

**What we're building**: A comprehensive customer research system where agents gather data progressively across multiple facets (identity, firmographics, web presence, contacts, tech stack, news).

**Use case**: Sales intelligence, competitive research, lead enrichment, market analysis - any scenario where research takes time and has multiple independent parts.

**Difficulty**: Advanced

**Key Features**:
- Partial submissions for incremental progress
- Per-part validation
- Assembled result validation
- Flexible ordering (submit parts in any sequence)
- Progress tracking
- Resumable work across sessions

## Why Use Partial Submissions?

**Traditional approach** (without partials):
- Agent must complete ALL research before submitting
- No progress visibility until complete
- If agent crashes, all work is lost
- Can't validate parts independently
- Long lease times required

**With partial submissions**:
- Submit each research facet as it's completed
- Early validation feedback (catch errors sooner)
- Progress is saved incrementally
- Resume from where you left off if interrupted
- Shorter lease times (can release and re-acquire)
- Flexible ordering (research facets in any order)

## Complete Code

Create `app/WorkTypes/CustomerResearchPartialType.php`:

```php
<?php

namespace App\WorkTypes;

use App\Models\Customer;
use App\Models\CustomerEnrichment;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\AbstractOrderType;
use GregPriday\WorkManager\Support\Diff;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Customer Research with Partial Submissions
 *
 * This demonstrates the partial submissions feature where agents
 * can incrementally submit research findings. Each part is validated
 * independently, then assembled and validated as a whole.
 *
 * Workflow:
 * 1. Order created with company name/domain
 * 2. Single work item (not multiple items - one item with multiple parts)
 * 3. Agent checks out the item
 * 4. Agent submits parts incrementally:
 *    - identity (company name, domain, industry)
 *    - firmographics (employees, revenue, locations)
 *    - web_presence (website, social profiles)
 *    - contacts (key contacts with emails)
 *    - tech_stack (technologies used)
 *    - news (recent news/events)
 * 5. Each part is validated independently
 * 6. Agent finalizes when all required parts are submitted
 * 7. System assembles and validates the complete profile
 * 8. Order approved and applied (save to database)
 */
class CustomerResearchPartialType extends AbstractOrderType
{
    public function type(): string
    {
        return 'research.customer.partial';
    }

    /**
     * JSON schema for the order payload.
     */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['company_name'],
            'properties' => [
                'company_name' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'description' => 'Company name to research',
                ],
                'domain' => [
                    'type' => 'string',
                    'format' => 'hostname',
                    'description' => 'Company website domain',
                ],
                'research_depth' => [
                    'type' => 'string',
                    'enum' => ['basic', 'standard', 'comprehensive'],
                    'default' => 'standard',
                ],
            ],
        ];
    }

    /**
     * Define which parts are required based on research depth.
     */
    public function requiredParts(WorkItem $item): array
    {
        $depth = $item->input['research_depth'] ?? 'standard';

        return match ($depth) {
            'basic' => ['identity', 'firmographics'],
            'comprehensive' => ['identity', 'firmographics', 'web_presence', 'contacts', 'tech_stack', 'news'],
            default => ['identity', 'firmographics', 'web_presence', 'contacts'],
        };
    }

    /**
     * Validation rules for each part type.
     *
     * This method is called when an agent submits a part.
     * Return Laravel validation rules specific to the part type.
     */
    public function partialRules(WorkItem $item, string $partKey, ?int $seq): array
    {
        return match ($partKey) {
            'identity' => [
                'name' => 'required|string|min:2',
                'domain' => 'nullable|url',
                'industry' => 'nullable|string',
                'description' => 'nullable|string|max:1000',
                'year_founded' => 'nullable|integer|min:1800|max:' . date('Y'),
                'confidence' => 'required|numeric|min:0|max:1',
                'sources' => 'required|array|min:1',
                'sources.*.url' => 'required|url',
                'sources.*.title' => 'nullable|string',
            ],
            'firmographics' => [
                'employees' => 'nullable|integer|min:1',
                'revenue' => 'nullable|numeric|min:0',
                'revenue_currency' => 'nullable|string|size:3',
                'headquarters' => 'nullable|string',
                'locations' => 'nullable|array',
                'locations.*.city' => 'required_with:locations|string',
                'locations.*.country' => 'required_with:locations|string',
                'confidence' => 'required|numeric|min:0|max:1',
                'sources' => 'required|array|min:1',
                'sources.*.url' => 'required|url',
            ],
            'web_presence' => [
                'website' => 'nullable|url',
                'linkedin' => 'nullable|url',
                'twitter' => 'nullable|string',
                'facebook' => 'nullable|url',
                'github' => 'nullable|url',
                'confidence' => 'required|numeric|min:0|max:1',
                'sources' => 'required|array|min:1',
            ],
            'contacts' => [
                'contacts' => 'required|array|min:1|max:50',
                'contacts.*.name' => 'required|string',
                'contacts.*.title' => 'nullable|string',
                'contacts.*.email' => 'nullable|email',
                'contacts.*.linkedin' => 'nullable|url',
                'contacts.*.phone' => 'nullable|string',
                'contacts.*.confidence' => 'required|numeric|min:0|max:1',
            ],
            'tech_stack' => [
                'technologies' => 'required|array',
                'technologies.*.name' => 'required|string',
                'technologies.*.category' => 'nullable|string',
                'technologies.*.confidence' => 'required|numeric|min:0|max:1',
                'sources' => 'required|array|min:1',
            ],
            'news' => [
                'articles' => 'required|array|max:20',
                'articles.*.title' => 'required|string',
                'articles.*.url' => 'required|url',
                'articles.*.date' => 'nullable|date',
                'articles.*.summary' => 'nullable|string|max:500',
            ],
            default => ['data' => 'required|array'],
        };
    }

    /**
     * Custom validation for each part after rules pass.
     */
    public function afterValidatePart(WorkItem $item, string $partKey, array $payload, ?int $seq): void
    {
        // Validate identity domain matches input
        if ($partKey === 'identity' && isset($item->input['domain']) && isset($payload['domain'])) {
            $inputDomain = $this->normalizeDomain($item->input['domain']);
            $payloadDomain = $this->normalizeDomain($payload['domain']);

            if ($inputDomain !== $payloadDomain) {
                throw ValidationException::withMessages([
                    'domain' => ['Domain does not match the order input'],
                ]);
            }
        }

        // Require high confidence for critical parts
        if (in_array($partKey, ['identity', 'firmographics'])) {
            $confidence = $payload['confidence'] ?? 0;
            if ($confidence < 0.7) {
                throw ValidationException::withMessages([
                    'confidence' => ['Critical parts require confidence >= 0.7'],
                ]);
            }
        }

        // Validate contact email domains match company domain
        if ($partKey === 'contacts') {
            $identityPart = $item->getLatestPart('identity');
            if ($identityPart && isset($identityPart->payload['domain'])) {
                $companyDomain = $this->normalizeDomain($identityPart->payload['domain']);

                foreach ($payload['contacts'] as $index => $contact) {
                    if (isset($contact['email'])) {
                        $emailDomain = $this->getDomainFromEmail($contact['email']);

                        // Log warning for mismatches (but don't fail validation)
                        if ($companyDomain && $emailDomain !== $companyDomain) {
                            Log::warning('Contact email domain mismatch', [
                                'contact_name' => $contact['name'],
                                'email_domain' => $emailDomain,
                                'company_domain' => $companyDomain,
                            ]);
                        }
                    }
                }
            }
        }
    }

    /**
     * Assemble all parts into a complete research profile.
     *
     * This is called after the agent finalizes (submits all required parts).
     */
    public function assemble(WorkItem $item, Collection $latestParts): array
    {
        $assembled = [
            '_meta' => [
                'parts_count' => $latestParts->count(),
                'assembled_at' => now()->toIso8601String(),
                'research_depth' => $item->input['research_depth'] ?? 'standard',
                'company_name' => $item->input['company_name'],
            ],
        ];

        // Collect each part's data
        foreach ($latestParts as $part) {
            $assembled[$part->part_key] = $part->payload;
        }

        // Calculate overall confidence score
        $confidenceScores = [];
        foreach ($latestParts as $part) {
            if (isset($part->payload['confidence'])) {
                $confidenceScores[] = $part->payload['confidence'];
            }
        }
        $assembled['_meta']['overall_confidence'] = !empty($confidenceScores)
            ? array_sum($confidenceScores) / count($confidenceScores)
            : 0;

        return $assembled;
    }

    /**
     * Validate the assembled result (whole-dataset validation).
     *
     * This is called after assembly to validate the complete research profile.
     */
    public function validateAssembled(WorkItem $item, array $assembled): void
    {
        // Ensure identity is present
        if (empty($assembled['identity']['name'])) {
            throw ValidationException::withMessages([
                'identity.name' => ['Company name is required in identity part'],
            ]);
        }

        // Validate overall confidence is acceptable
        if (isset($assembled['_meta']['overall_confidence'])) {
            if ($assembled['_meta']['overall_confidence'] < 0.6) {
                throw ValidationException::withMessages([
                    'confidence' => ['Overall confidence score is too low (minimum 0.6)'],
                ]);
            }
        }

        // Validate contacts don't exceed limits
        if (isset($assembled['contacts']['contacts'])) {
            if (count($assembled['contacts']['contacts']) > 100) {
                throw ValidationException::withMessages([
                    'contacts' => ['Too many contacts (maximum 100)'],
                ]);
            }
        }
    }

    /**
     * Plan creates a single work item (not multiple items per facet).
     */
    public function plan(WorkOrder $order): array
    {
        $item = new WorkItem(['input' => $order->payload]);

        return [[
            'type' => $this->type(),
            'input' => $order->payload,
            'parts_required' => $this->requiredParts($item),
            'max_attempts' => 3,
        ]];
    }

    /**
     * Apply the research to the database.
     */
    public function apply(WorkOrder $order): Diff
    {
        $before = [];
        $after = [];

        DB::transaction(function () use ($order, &$before, &$after) {
            foreach ($order->items as $item) {
                $profile = $item->assembled_result ?? $item->result;

                if (empty($profile)) {
                    continue;
                }

                $companyName = $profile['identity']['name'] ?? 'Unknown';
                $domain = $profile['identity']['domain'] ?? null;

                // Track what we had before
                $existingCustomer = $domain ? Customer::where('domain', $domain)->first() : null;
                $before[$companyName] = $existingCustomer ? 'existing' : 'new';

                // Save to database (simplified for example)
                Log::info('Applying customer research profile', [
                    'company' => $companyName,
                    'domain' => $domain,
                    'confidence' => $profile['_meta']['overall_confidence'] ?? 0,
                ]);

                // In real implementation:
                // $customer = Customer::updateOrCreate(['domain' => $domain], [...]);
                // foreach ($profile as $facet => $data) {
                //     CustomerEnrichment::create([...]);
                // }

                $after[$companyName] = 'enriched';
            }
        });

        return $this->makeDiff(
            $before,
            $after,
            "Applied research for " . count($after) . " companies"
        );
    }

    protected function normalizeDomain(string $domain): string
    {
        $parsed = parse_url($domain, PHP_URL_HOST) ?? $domain;
        return strtolower(trim($parsed));
    }

    protected function getDomainFromEmail(string $email): string
    {
        $parts = explode('@', $email);
        return isset($parts[1]) ? strtolower(trim($parts[1])) : '';
    }
}
```

## Step-by-Step Walkthrough

### 1. Understanding Partial Submissions Architecture

**Key difference from regular submissions**:
- Regular: Agent completes ALL work, submits once
- Partial: Agent submits multiple parts independently, then finalizes

**WorkItem lifecycle with partials**:
```
leased → (submit part 1) → (submit part 2) → ... → (finalize) → submitted
```

**Each part**:
- Has a `part_key` (e.g., "identity", "firmographics")
- Is validated independently
- Is stored separately (WorkItemPart model)
- Can be submitted in any order

### 2. Define Required Parts

```php
public function requiredParts(WorkItem $item): array
{
    $depth = $item->input['research_depth'] ?? 'standard';

    return match ($depth) {
        'basic' => ['identity', 'firmographics'],
        'comprehensive' => ['identity', 'firmographics', 'web_presence',
                            'contacts', 'tech_stack', 'news'],
        default => ['identity', 'firmographics', 'web_presence', 'contacts'],
    };
}
```

The agent must submit all required parts before finalizing.

### 3. Per-Part Validation

Each part type has its own validation rules:

```php
public function partialRules(WorkItem $item, string $partKey, ?int $seq): array
{
    return match ($partKey) {
        'identity' => [
            'name' => 'required|string|min:2',
            'domain' => 'nullable|url',
            'confidence' => 'required|numeric|min:0|max:1',
            'sources' => 'required|array|min:1',
        ],
        'contacts' => [
            'contacts' => 'required|array|min:1|max:50',
            'contacts.*.name' => 'required|string',
            'contacts.*.email' => 'nullable|email',
        ],
        // ... other part types
    };
}
```

### 4. Cross-Part Validation

Validate relationships between parts:

```php
public function afterValidatePart(WorkItem $item, string $partKey, array $payload, ?int $seq): void
{
    // When contacts are submitted, check against identity domain
    if ($partKey === 'contacts') {
        $identityPart = $item->getLatestPart('identity');

        if ($identityPart) {
            $companyDomain = $identityPart->payload['domain'];
            // Validate contact emails match company domain
        }
    }
}
```

### 5. Assembly and Whole-Dataset Validation

After all parts are submitted, assemble and validate the complete result:

```php
public function assemble(WorkItem $item, Collection $latestParts): array
{
    $assembled = ['_meta' => [...]];

    // Combine all parts
    foreach ($latestParts as $part) {
        $assembled[$part->part_key] = $part->payload;
    }

    // Calculate aggregates
    $assembled['_meta']['overall_confidence'] = $this->calculateAvgConfidence($latestParts);

    return $assembled;
}

public function validateAssembled(WorkItem $item, array $assembled): void
{
    // Validate the complete dataset
    if ($assembled['_meta']['overall_confidence'] < 0.6) {
        throw ValidationException::withMessages([
            'confidence' => ['Overall confidence too low'],
        ]);
    }
}
```

## Example API Interactions

### 1. Propose Research Order

```bash
curl -X POST http://your-app.test/api/ai/work/propose \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: research-$(date +%s)" \
  -d '{
    "type": "research.customer.partial",
    "payload": {
      "company_name": "Acme Corporation",
      "domain": "acme.com",
      "research_depth": "standard"
    }
  }'
```

**Response:**

```json
{
  "order": {
    "id": "order-uuid",
    "type": "research.customer.partial",
    "state": "queued"
  },
  "items": [
    {
      "id": "item-uuid",
      "state": "queued",
      "input": {
        "company_name": "Acme Corporation",
        "domain": "acme.com",
        "research_depth": "standard"
      },
      "parts_required": ["identity", "firmographics", "web_presence", "contacts"]
    }
  ]
}
```

### 2. Checkout Work Item

```bash
curl -X POST http://your-app.test/api/ai/work/orders/order-uuid/checkout \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Agent-ID: research-agent-01"
```

**Response:**

```json
{
  "item": {
    "id": "item-uuid",
    "state": "leased",
    "input": {
      "company_name": "Acme Corporation",
      "domain": "acme.com",
      "research_depth": "standard"
    },
    "parts_required": ["identity", "firmographics", "web_presence", "contacts"],
    "parts_submitted": [],
    "lease_expires_at": "2025-01-22T10:10:00Z"
  }
}
```

### 3. Submit First Part (Identity)

```bash
curl -X POST http://your-app.test/api/ai/work/items/item-uuid/submit-part \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: part-identity-$(date +%s)" \
  -H "X-Agent-ID: research-agent-01" \
  -d '{
    "part_key": "identity",
    "payload": {
      "name": "Acme Corporation",
      "domain": "https://acme.com",
      "industry": "Manufacturing",
      "description": "Leading manufacturer of premium widgets",
      "year_founded": 1985,
      "confidence": 0.95,
      "sources": [
        {
          "url": "https://acme.com/about",
          "title": "About Acme Corporation"
        },
        {
          "url": "https://linkedin.com/company/acme",
          "title": "Acme Corporation - LinkedIn"
        }
      ]
    }
  }'
```

**Response:**

```json
{
  "part": {
    "id": "part-uuid-1",
    "part_key": "identity",
    "status": "validated",
    "payload": {...},
    "created_at": "2025-01-22T10:01:00Z"
  },
  "item": {
    "id": "item-uuid",
    "parts_required": ["identity", "firmographics", "web_presence", "contacts"],
    "parts_submitted": ["identity"],
    "parts_remaining": ["firmographics", "web_presence", "contacts"]
  }
}
```

### 4. Submit Second Part (Firmographics)

```bash
curl -X POST http://your-app.test/api/ai/work/items/item-uuid/submit-part \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: part-firmo-$(date +%s)" \
  -H "X-Agent-ID: research-agent-01" \
  -d '{
    "part_key": "firmographics",
    "payload": {
      "employees": 1200,
      "revenue": 150000000,
      "revenue_currency": "USD",
      "headquarters": "San Francisco, CA",
      "locations": [
        {"city": "San Francisco", "country": "USA"},
        {"city": "London", "country": "UK"}
      ],
      "confidence": 0.85,
      "sources": [
        {
          "url": "https://crunchbase.com/organization/acme",
          "title": "Acme Corporation - Crunchbase"
        }
      ]
    }
  }'
```

**Response:**

```json
{
  "part": {
    "id": "part-uuid-2",
    "part_key": "firmographics",
    "status": "validated"
  },
  "item": {
    "parts_submitted": ["identity", "firmographics"],
    "parts_remaining": ["web_presence", "contacts"]
  }
}
```

### 5. Submit Remaining Parts

Continue submitting web_presence and contacts parts...

### 6. List Submitted Parts

```bash
curl -X GET http://your-app.test/api/ai/work/items/item-uuid/parts \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Response:**

```json
{
  "parts": [
    {
      "id": "part-uuid-1",
      "part_key": "identity",
      "status": "validated",
      "created_at": "2025-01-22T10:01:00Z"
    },
    {
      "id": "part-uuid-2",
      "part_key": "firmographics",
      "status": "validated",
      "created_at": "2025-01-22T10:02:30Z"
    },
    {
      "id": "part-uuid-3",
      "part_key": "web_presence",
      "status": "validated",
      "created_at": "2025-01-22T10:04:15Z"
    },
    {
      "id": "part-uuid-4",
      "part_key": "contacts",
      "status": "validated",
      "created_at": "2025-01-22T10:06:00Z"
    }
  ],
  "item": {
    "parts_required": ["identity", "firmographics", "web_presence", "contacts"],
    "parts_submitted": ["identity", "firmographics", "web_presence", "contacts"],
    "parts_remaining": [],
    "ready_to_finalize": true
  }
}
```

### 7. Finalize (Assemble All Parts)

```bash
curl -X POST http://your-app.test/api/ai/work/items/item-uuid/finalize \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "X-Idempotency-Key: finalize-$(date +%s)" \
  -H "X-Agent-ID: research-agent-01"
```

**Response:**

```json
{
  "item": {
    "id": "item-uuid",
    "state": "submitted",
    "assembled_result": {
      "_meta": {
        "parts_count": 4,
        "assembled_at": "2025-01-22T10:07:00Z",
        "overall_confidence": 0.88,
        "company_name": "Acme Corporation"
      },
      "identity": {...},
      "firmographics": {...},
      "web_presence": {...},
      "contacts": {...}
    }
  }
}
```

### 8. Approve and Apply

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
    "before": {"Acme Corporation": "new"},
    "after": {"Acme Corporation": "enriched"},
    "summary": "Applied research for 1 companies"
  }
}
```

## Key Learnings

### 1. When to Use Partial Submissions

Use partial submissions when:
- Work takes > 5 minutes per item
- Work has independent sub-tasks
- Early validation is valuable
- Progress tracking is important
- Work might be interrupted

Don't use partial submissions when:
- Work completes quickly (< 2 minutes)
- All data must be gathered atomically
- Parts have strong dependencies

### 2. Part Key Naming

Use clear, consistent part keys:
- `identity`, `firmographics`, `contacts` (good)
- `part1`, `data`, `result` (bad)

### 3. Validation Strategy

**Three validation levels**:
1. **Per-part validation** (`partialRules`): Field-level rules
2. **Cross-part validation** (`afterValidatePart`): Relationships between parts
3. **Assembled validation** (`validateAssembled`): Whole-dataset constraints

### 4. Progress Visibility

Track progress via:
- `parts_submitted` array
- `parts_remaining` array
- `ready_to_finalize` boolean

### 5. Idempotency with Partials

Parts can be resubmitted:
- Latest part with same `part_key` overwrites previous
- Use `X-Idempotency-Key` for each part submission
- Finalize is also idempotent

## Variations and Extensions

### Variation 1: Optional Parts

Allow some parts to be optional:

```php
public function requiredParts(WorkItem $item): array
{
    return ['identity', 'firmographics']; // Minimum required
}

public function optionalParts(WorkItem $item): array
{
    return ['web_presence', 'contacts', 'tech_stack', 'news'];
}

public function validateAssembled(WorkItem $item, array $assembled): void
{
    // Require at least 2 optional parts
    $optionalPartsSubmitted = count(array_intersect(
        array_keys($assembled),
        $this->optionalParts($item)
    ));

    if ($optionalPartsSubmitted < 2) {
        throw ValidationException::withMessages([
            'parts' => ['At least 2 optional parts required'],
        ]);
    }
}
```

### Variation 2: Sequenced Parts

Require parts to be submitted in order:

```php
public function partialRules(WorkItem $item, string $partKey, ?int $seq): array
{
    // Seq 0: identity must be first
    if ($partKey !== 'identity' && $item->parts()->count() === 0) {
        throw ValidationException::withMessages([
            'part_key' => ['Identity must be submitted first'],
        ]);
    }

    // Seq 1: firmographics must be second
    if ($partKey === 'contacts' && !$item->getLatestPart('firmographics')) {
        throw ValidationException::withMessages([
            'part_key' => ['Firmographics must be submitted before contacts'],
        ]);
    }

    return $this->getPartRules($partKey);
}
```

### Variation 3: Part Versioning

Track changes to parts over time:

```php
public function afterValidatePart(WorkItem $item, string $partKey, array $payload, ?int $seq): void
{
    // Compare to previous version
    $previousPart = $item->getLatestPart($partKey);

    if ($previousPart) {
        $changes = array_diff_assoc($payload, $previousPart->payload);

        Log::info('Part updated', [
            'item_id' => $item->id,
            'part_key' => $partKey,
            'changes' => array_keys($changes),
        ]);
    }
}
```

### Variation 4: Collaborative Research

Allow multiple agents to contribute different parts:

```php
// Agent A submits identity and firmographics
// Agent B submits contacts and tech_stack
// System tracks which agent submitted which part

public function afterValidatePart(WorkItem $item, string $partKey, array $payload, ?int $seq): void
{
    $agentId = request()->header('X-Agent-ID');

    Log::info('Part contributed', [
        'item_id' => $item->id,
        'part_key' => $partKey,
        'agent_id' => $agentId,
    ]);
}
```

## Next Steps

1. **Try Fact-Checking**: See [content-fact-check.md](./content-fact-check.md)
2. **Try Multi-Dimensional Scoring**: See [city-tier-generation.md](./city-tier-generation.md)
3. **Build Production Research System**: Add caching, deduplication
4. **Add Data Quality Metrics**: Track confidence, completeness, freshness

## Troubleshooting

### Part Validation Failed

If a part is rejected:
- Check validation rules for that part type
- Review the error response
- Fix the data and resubmit (overwrites previous)

### Finalize Blocked

If finalize fails:
- Check all required parts are submitted
- Review assembled validation errors
- Submit missing or fix invalid parts

### Cross-Part Validation Issues

If parts conflict:
- Review dependency order (identity before contacts)
- Check data consistency (domains, dates)
- Update dependent parts if needed
