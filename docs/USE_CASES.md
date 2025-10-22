# Use Cases for Laravel Work Manager

This document provides concrete use cases and implementation patterns for the Laravel Work Manager package. Each use case demonstrates how to leverage the work order system's guarantees (typed work, leases, idempotency, verification, audit trail) to orchestrate AI agent work reliably.

## Table of Contents

- [Core Use Cases](#core-use-cases)
  - [1. Customer Research & Enrichment](#1-customer-research--enrichment)
  - [2. Content Fact-Checking](#2-content-fact-checking)
  - [3. City/Entity Rating System](#3-cityentity-rating-system)
- [Additional Use Cases](#additional-use-cases)
  - [Lead Enrichment & ICP Scoring](#lead-enrichment--icp-scoring)
  - [Link Integrity & SEO Checks](#link-integrity--seo-checks)
  - [Data Hygiene & Deduplication](#data-hygiene--deduplication)
  - [Knowledge Base Grooming](#knowledge-base-grooming)
  - [Compliance & Audit Tasks](#compliance--audit-tasks)
- [Implementation Patterns](#implementation-patterns)
- [Best Practices](#best-practices)

---

## Core Use Cases

### 1. Customer Research & Enrichment

**Scenario**: Your CRM needs comprehensive research on prospects/customers. Agents should gather firmographics, web presence, tech stack, contacts, and recent news—with evidence for each claim.

**Business Value**:
- Sales teams get rich context before outreach
- Automated competitive intelligence
- ICP (Ideal Customer Profile) scoring
- Territory planning with real data

**Implementation Pattern**:

```php
// examples/CustomerResearchType.php
class CustomerResearchType extends AbstractOrderType
{
    public function type(): string
    {
        return 'customer.research';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['customer_id'],
            'properties' => [
                'customer_id' => ['type' => 'integer'],
                'company_domain' => ['type' => 'string', 'format' => 'hostname'],
                'depth' => [
                    'type' => 'string',
                    'enum' => ['quick', 'standard', 'deep'],
                    'default' => 'standard'
                ],
                'markets' => [
                    'type' => 'array',
                    'items' => ['type' => 'string']
                ],
            ],
        ];
    }

    public function plan(WorkOrder $order): array
    {
        $facets = [
            'identity',          // Company name, legal entity, registration
            'firmographics',     // Size, revenue, industry, location
            'web_presence',      // Website, social profiles, content
            'contacts',          // Key personnel, decision makers
            'tech_stack',        // Technologies used (from job postings, etc.)
            'recent_news',       // Press releases, funding, partnerships
        ];

        return array_map(fn($facet) => [
            'type' => $this->type(),
            'input' => [
                'facet' => $facet,
                'customer_id' => $order->payload['customer_id'],
                'company_domain' => $order->payload['company_domain'] ?? null,
                'depth' => $order->payload['depth'] ?? 'standard',
            ],
            'max_attempts' => 3,
        ], $facets);
    }

    protected function submissionValidationRules(WorkItem $item): array
    {
        return [
            'success' => 'required|boolean',
            'facet' => 'required|string',
            'sections' => 'required|array',
            'sections.*.key' => 'required|string',
            'sections.*.value' => 'required',
            'sections.*.confidence' => 'required|numeric|min:0|max:1',
            'sections.*.evidence' => 'required|array|min:1',
            'sections.*.evidence.*.url' => 'required|url',
            'sections.*.evidence.*.retrieved_at' => 'required|date',
            'sections.*.evidence.*.quote' => 'nullable|string',
        ];
    }

    protected function afterValidateSubmission(WorkItem $item, array $result): void
    {
        // Verify domains are accessible
        foreach ($result['sections'] as $section) {
            foreach ($section['evidence'] as $evidence) {
                $domain = parse_url($evidence['url'], PHP_URL_HOST);
                if (!$this->isDomainAccessible($domain)) {
                    throw ValidationException::withMessages([
                        'evidence.url' => ["Domain {$domain} is not accessible"],
                    ]);
                }
            }
        }

        // Require minimum confidence for critical facets
        $facet = $item->input['facet'];
        if (in_array($facet, ['identity', 'firmographics'])) {
            foreach ($result['sections'] as $section) {
                if ($section['confidence'] < 0.7) {
                    throw ValidationException::withMessages([
                        'confidence' => ['Critical facets require confidence >= 0.7'],
                    ]);
                }
            }
        }
    }

    protected function canApprove(WorkOrder $order): bool
    {
        // All facets must be present and verified
        $requiredFacets = ['identity', 'firmographics', 'web_presence', 'contacts'];
        $completedFacets = $order->items
            ->where('state', 'submitted')
            ->pluck('result.facet')
            ->unique()
            ->toArray();

        return count(array_diff($requiredFacets, $completedFacets)) === 0;
    }

    public function apply(WorkOrder $order): Diff
    {
        $customerId = $order->payload['customer_id'];
        $before = Customer::find($customerId)->toArray();

        DB::transaction(function () use ($order, $customerId) {
            foreach ($order->items as $item) {
                foreach ($item->result['sections'] as $section) {
                    CustomerEnrichment::create([
                        'customer_id' => $customerId,
                        'facet' => $item->result['facet'],
                        'key' => $section['key'],
                        'value' => $section['value'],
                        'confidence' => $section['confidence'],
                        'evidence' => $section['evidence'],
                        'verified_at' => now(),
                    ]);
                }
            }

            Customer::where('id', $customerId)->update([
                'enriched_at' => now(),
                'enrichment_version' => now()->timestamp,
            ]);
        });

        $after = Customer::find($customerId)->toArray();

        return $this->makeDiff(
            $before,
            $after,
            "Enriched customer {$customerId} with " . $order->items->count() . " facets"
        );
    }
}
```

**Agent Submission Example**:

```json
{
  "success": true,
  "facet": "firmographics",
  "sections": [
    {
      "key": "employee_count",
      "value": "50-200",
      "confidence": 0.85,
      "evidence": [
        {
          "url": "https://www.linkedin.com/company/acme",
          "retrieved_at": "2025-01-15T10:30:00Z",
          "quote": "51-200 employees on LinkedIn"
        }
      ]
    },
    {
      "key": "annual_revenue",
      "value": "$10M-$50M",
      "confidence": 0.75,
      "evidence": [
        {
          "url": "https://www.crunchbase.com/organization/acme",
          "retrieved_at": "2025-01-15T10:32:00Z",
          "quote": "Estimated annual revenue: $25M"
        }
      ]
    }
  ]
}
```

**Workflow**:
1. CRM event triggers `work-manager:generate` or manual proposal
2. System creates order with customer ID and domain
3. Order is planned into 6 work items (one per facet)
4. Agents lease items, research online, submit structured results with evidence
5. System validates each submission (evidence URLs, confidence scores)
6. When all required facets complete, order is ready for approval
7. Apply writes normalized enrichment records to database
8. Customer record is marked as enriched with timestamp

---

### 2. Content Fact-Checking

**Scenario**: Blog posts, marketing content, or knowledge base articles need verification before publication. Agents should verify factual claims, provide evidence, and flag suspicious statements.

**Business Value**:
- Reduce misinformation and legal risk
- Maintain brand credibility
- Automate editorial review
- Build trust with evidence-backed content

**Implementation Pattern**:

```php
// examples/ContentFactCheckType.php
class ContentFactCheckType extends AbstractOrderType
{
    public function type(): string
    {
        return 'content.factcheck';
    }

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
                            'type' => [
                                'type' => 'string',
                                'enum' => ['statistic', 'quote', 'fact', 'comparison']
                            ],
                            'context' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function plan(WorkOrder $order): array
    {
        // One work item per claim (keeps leases short, enables parallel checking)
        return array_map(fn($claim) => [
            'type' => $this->type(),
            'input' => [
                'claim_id' => $claim['id'],
                'claim_text' => $claim['text'],
                'claim_type' => $claim['type'],
                'context' => $claim['context'] ?? null,
                'content_id' => $order->payload['content_id'],
                'policy_version' => $order->payload['policy_version'],
            ],
            'max_attempts' => 2,
        ], $order->payload['claims']);
    }

    protected function submissionValidationRules(WorkItem $item): array
    {
        return [
            'claim_id' => 'required|string',
            'verdict' => 'required|in:verified,false,inconclusive,outdated',
            'confidence' => 'required|numeric|min:0|max:1',
            'explanation' => 'required|string|min:50',
            'evidence' => 'required|array|min:1',
            'evidence.*.url' => 'required|url',
            'evidence.*.title' => 'required|string',
            'evidence.*.quote' => 'nullable|string',
            'evidence.*.retrieved_at' => 'required|date',
            'evidence.*.source_credibility' => 'required|in:high,medium,low',
            'corrections' => 'array',
            'corrections.*.original' => 'required|string',
            'corrections.*.corrected' => 'required|string',
            'corrections.*.source' => 'required|url',
        ];
    }

    protected function afterValidateSubmission(WorkItem $item, array $result): void
    {
        // High-confidence false claims must have corrections
        if ($result['verdict'] === 'false' && $result['confidence'] >= 0.8) {
            if (empty($result['corrections'])) {
                throw ValidationException::withMessages([
                    'corrections' => ['High-confidence false claims require corrections'],
                ]);
            }
        }

        // Require at least one high-credibility source for verified claims
        if ($result['verdict'] === 'verified') {
            $hasHighCredibility = collect($result['evidence'])
                ->contains('source_credibility', 'high');

            if (!$hasHighCredibility) {
                throw ValidationException::withMessages([
                    'evidence' => ['Verified claims require at least one high-credibility source'],
                ]);
            }
        }

        // Statistics must have recent evidence (< 2 years old)
        if ($item->input['claim_type'] === 'statistic') {
            foreach ($result['evidence'] as $evidence) {
                $age = now()->diffInYears($evidence['retrieved_at']);
                if ($age > 2) {
                    throw ValidationException::withMessages([
                        'evidence.retrieved_at' => ['Statistics require evidence < 2 years old'],
                    ]);
                }
            }
        }
    }

    protected function canApprove(WorkOrder $order): bool
    {
        // Block approval if any claims are false or inconclusive with high confidence
        foreach ($order->items as $item) {
            if (!$item->result) {
                continue;
            }

            $verdict = $item->result['verdict'];
            $confidence = $item->result['confidence'];

            if (in_array($verdict, ['false', 'outdated']) && $confidence >= 0.7) {
                return false;
            }

            if ($verdict === 'inconclusive' && $confidence < 0.5) {
                return false;
            }
        }

        return true;
    }

    public function apply(WorkOrder $order): Diff
    {
        $contentId = $order->payload['content_id'];
        $before = ['status' => Content::find($contentId)->status];

        DB::transaction(function () use ($order, $contentId) {
            foreach ($order->items as $item) {
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

            // Mark content as verified
            Content::where('id', $contentId)->update([
                'status' => 'fact_checked',
                'fact_checked_at' => now(),
                'policy_version' => $order->payload['policy_version'],
            ]);
        });

        $after = ['status' => Content::find($contentId)->status];

        return $this->makeDiff(
            $before,
            $after,
            "Fact-checked {$order->items->count()} claims for content {$contentId}"
        );
    }
}
```

**Agent Submission Example**:

```json
{
  "claim_id": "claim_001",
  "verdict": "verified",
  "confidence": 0.92,
  "explanation": "The statistic about global smartphone users is accurate according to multiple credible sources from 2024. The actual figure is 6.92 billion as of January 2024.",
  "evidence": [
    {
      "url": "https://www.statista.com/statistics/330695/number-of-smartphone-users-worldwide/",
      "title": "Number of smartphone users worldwide 2024 | Statista",
      "quote": "6.92 billion smartphone users worldwide in 2024",
      "retrieved_at": "2025-01-15T14:22:00Z",
      "source_credibility": "high"
    },
    {
      "url": "https://datareportal.com/reports/digital-2024-global-overview",
      "title": "Digital 2024: Global Overview Report",
      "quote": "Global smartphone adoption reached 6.9 billion unique users",
      "retrieved_at": "2025-01-15T14:25:00Z",
      "source_credibility": "high"
    }
  ],
  "corrections": []
}
```

**Workflow**:
1. Content draft triggers fact-check order (manual or automated)
2. System extracts claims and creates work items
3. Agents lease claims, research evidence, submit verdicts
4. System validates evidence quality, source credibility, and recency
5. If any high-confidence false claims exist, approval is blocked
6. Editor reviews rejections and makes corrections
7. Re-submit for checking or approve verified content
8. Apply writes fact-check records and marks content as verified

---

### 3. City/Entity Rating System

**Scenario**: Generate comprehensive ratings for cities, products, or services across multiple dimensions. Each dimension requires research, scoring, and evidence from multiple sources.

**Business Value**:
- Automated content generation for comparison sites
- Data-driven rankings and recommendations
- Transparent, evidence-based ratings
- Regular updates to keep data fresh

**Implementation Pattern**:

```php
// examples/CityTierGenerationType.php
class CityTierGenerationType extends AbstractOrderType
{
    public function type(): string
    {
        return 'city.tier.generate';
    }

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
                    'minItems' => 1,
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

    public function plan(WorkOrder $order): array
    {
        // One work item per dimension
        return array_map(fn($dimension) => [
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

    protected function submissionValidationRules(WorkItem $item): array
    {
        return [
            'dimension' => 'required|string',
            'score' => 'required|numeric|min:0|max:10',
            'tier' => 'required|in:A,B,C,D,F',
            'explanation' => 'required|string|min:100',
            'key_points' => 'required|array|min:3',
            'key_points.*' => 'string',
            'citations' => 'required|array|min:' . $item->input['min_sources'],
            'citations.*.url' => 'required|url',
            'citations.*.title' => 'required|string',
            'citations.*.data_point' => 'required|string',
            'citations.*.retrieved_at' => 'required|date',
            'citations.*.year' => 'required|integer|min:2020',
            'metadata' => 'array',
            'metadata.currency' => 'nullable|string',
            'metadata.units' => 'nullable|string',
        ];
    }

    protected function afterValidateSubmission(WorkItem $item, array $result): void
    {
        // Verify score aligns with tier
        $score = $result['score'];
        $tier = $result['tier'];

        $expectedTier = match(true) {
            $score >= 8.5 => 'A',
            $score >= 7.0 => 'B',
            $score >= 5.5 => 'C',
            $score >= 4.0 => 'D',
            default => 'F',
        };

        if ($tier !== $expectedTier) {
            throw ValidationException::withMessages([
                'tier' => ["Score {$score} should map to tier {$expectedTier}, got {$tier}"],
            ]);
        }

        // Require recent data (< 3 years old)
        foreach ($result['citations'] as $citation) {
            if ($citation['year'] < now()->year - 3) {
                throw ValidationException::withMessages([
                    'citations.year' => ['Citations must be from the last 3 years'],
                ]);
            }
        }
    }

    protected function canApprove(WorkOrder $order): bool
    {
        // All required dimensions must be completed
        $requiredDimensions = $order->payload['dimensions'];
        $completedDimensions = $order->items
            ->where('state', 'submitted')
            ->pluck('result.dimension')
            ->toArray();

        return count(array_diff($requiredDimensions, $completedDimensions)) === 0;
    }

    public function apply(WorkOrder $order): Diff
    {
        $cityId = $order->payload['city_id'];
        $before = City::find($cityId)->only(['tier', 'overall_score', 'rated_at']);

        DB::transaction(function () use ($order, $cityId) {
            // Clear existing ratings for this city
            CityRating::where('city_id', $cityId)->delete();

            $totalScore = 0;
            $dimensionCount = 0;

            foreach ($order->items as $item) {
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

            // Calculate overall tier and score
            $overallScore = round($totalScore / $dimensionCount, 2);
            $overallTier = match(true) {
                $overallScore >= 8.5 => 'A',
                $overallScore >= 7.0 => 'B',
                $overallScore >= 5.5 => 'C',
                $overallScore >= 4.0 => 'D',
                default => 'F',
            };

            City::where('id', $cityId)->update([
                'tier' => $overallTier,
                'overall_score' => $overallScore,
                'rated_at' => now(),
            ]);
        });

        $after = City::find($cityId)->only(['tier', 'overall_score', 'rated_at']);

        return $this->makeDiff(
            $before,
            $after,
            "Generated ratings for {$order->items->count()} dimensions for city {$cityId}"
        );
    }
}
```

**Agent Submission Example**:

```json
{
  "dimension": "internet_quality",
  "score": 8.7,
  "tier": "A",
  "explanation": "Berlin offers excellent internet infrastructure with widespread fiber availability, competitive pricing, and strong public WiFi coverage. Average download speeds exceed 100 Mbps, and multiple ISPs provide reliable service. The city is known for its tech-friendly infrastructure and digital nomad community.",
  "key_points": [
    "Average download speed: 120 Mbps (fiber)",
    "Multiple ISP options with competitive pricing (€30-50/month)",
    "Extensive public WiFi in cafes, coworking spaces, and public areas",
    "Strong 5G mobile coverage across the city",
    "Reliable uptime (>99.5%) reported by residents"
  ],
  "citations": [
    {
      "url": "https://www.speedtest.net/global-index/germany",
      "title": "Speedtest Global Index - Germany",
      "data_point": "Average fixed broadband: 118.04 Mbps in Berlin",
      "retrieved_at": "2025-01-15T16:00:00Z",
      "year": 2024
    },
    {
      "url": "https://www.numbeo.com/cost-of-living/in/Berlin",
      "title": "Cost of Living in Berlin",
      "data_point": "Internet (60 Mbps or More): 35.42 EUR/month",
      "retrieved_at": "2025-01-15T16:05:00Z",
      "year": 2024
    }
  ],
  "metadata": {
    "currency": "EUR",
    "units": "Mbps"
  }
}
```

**Workflow**:
1. City is added to database or scheduled for refresh
2. System creates order with city ID and desired dimensions
3. Order is planned into work items (one per dimension)
4. Agents lease dimensions, research data, calculate scores
5. System validates scoring logic, citation recency, and evidence quality
6. When all dimensions complete, order is approved
7. Apply writes dimension ratings and calculates overall city tier
8. City profile is updated with new ratings and timestamp

---

## Additional Use Cases

### Lead Enrichment & ICP Scoring

**Scenario**: Automatically enrich leads from forms/signups and score them against your Ideal Customer Profile.

**Order Type**: `lead.enrichment`

**Planning**: Split into parallel items:
- Company identification (from email domain)
- Firmographic data (size, revenue, industry)
- Technology stack detection
- Social proof (funding, team size)
- ICP fit scoring

**Evidence Required**:
- LinkedIn company page
- Crunchbase/similar
- Job postings for tech stack
- Public SEC filings for revenue (if applicable)

**Apply**: Write to `leads` table with enrichment data and ICP score; trigger sales workflow if score > threshold.

---

### Link Integrity & SEO Checks

**Scenario**: After publishing content, verify all outbound links are valid, not broken, and align with SEO best practices.

**Order Type**: `content.link_check`

**Planning**: One item per link or logical group of links.

**Agent Tasks**:
- Check HTTP status codes
- Verify SSL certificates
- Check for redirects (3xx)
- Analyze anchor text quality
- Detect nofollow/dofollow
- Check for toxic domains

**Apply**: Update link status in CMS; optionally create fix orders for broken links.

---

### Data Hygiene & Deduplication

**Scenario**: Detect and merge duplicate records (customers, products, content) with confidence scores.

**Order Type**: `data.deduplicate`

**Planning**: Each work item contains a candidate duplicate pair.

**Agent Tasks**:
- Compare fields (fuzzy matching)
- Identify canonical record
- Propose merge strategy
- Provide confidence score

**Validation**: Require high confidence (>0.9) for auto-merge; route lower scores to human review.

**Apply**: Merge records, redirect references, archive duplicates with before/after `Diff`.

---

### Knowledge Base Grooming

**Scenario**: Keep internal documentation/KB up-to-date by detecting outdated content, suggesting updates, and summarizing changes.

**Order Type**: `kb.groom`

**Planning**: One item per document or section.

**Agent Tasks**:
- Detect outdated references (old product versions, deprecated APIs)
- Suggest content updates based on recent changes
- Generate summaries
- Flag unclear or ambiguous sections

**Apply**: Create edit suggestions in CMS; optionally auto-apply low-risk changes (typos, formatting).

---

### Compliance & Audit Tasks

**Scenario**: Review logs, transactions, or content for compliance violations (GDPR, PII exposure, policy violations).

**Order Type**: `compliance.audit`

**Planning**: Batch records into work items (e.g., 100 transactions per item).

**Agent Tasks**:
- Scan for PII patterns (emails, SSNs, credit cards)
- Flag policy violations
- Suggest redactions
- Provide risk scores

**Validation**: Require evidence for each flagged item; block approval if high-risk violations detected.

**Apply**: Log violations, trigger workflows (notify DPO, redact data), record `WorkEvent` for audit trail.

---

## Implementation Patterns

### Pattern 1: Multi-Facet Research

**When to use**: Complex subjects requiring multiple perspectives (customer research, entity profiles).

**Structure**:
- Single order with multiple facets as work items
- Each facet can be assigned to different specialist agents
- Cross-facet validation in `canApprove()`

**Example**: Customer research split into identity, firmographics, web presence, contacts, tech stack, news.

---

### Pattern 2: Sequential Verification

**When to use**: Tasks requiring ordered stages (initial research → fact-check → legal review).

**Structure**:
- Create multiple orders in sequence
- First order's `afterApply()` hook proposes next order
- Link orders via `meta` field for traceability

**Example**: Content draft → fact-check → legal review → publish.

---

### Pattern 3: Batch Processing

**When to use**: High-volume tasks that can be chunked (link checking, data deduplication).

**Structure**:
- Plan creates work items with batches (e.g., 50 links per item)
- Agents process batches in parallel
- Aggregated results in `apply()`

**Example**: Check 500 links → 10 work items of 50 links each.

---

### Pattern 4: Confidence-Based Routing

**When to use**: Tasks where low-confidence results need human review.

**Structure**:
- Agents submit confidence scores
- `canApprove()` checks aggregate confidence
- Low-confidence items trigger human review workflow
- High-confidence items auto-approve

**Example**: Duplicate detection with confidence < 0.8 routed to manual review.

---

### Pattern 5: Evidence-Driven Decisions

**When to use**: Any task requiring explainability and auditability.

**Structure**:
- Require structured evidence in submission schema
- Validate evidence quality (URLs, dates, credibility)
- Store evidence in `WorkEvent` for compliance
- Render evidence in diffs for transparency

**Example**: Fact-checking where every claim requires 2+ credible sources.

---

## Best Practices

### 1. Design for Idempotency

**Always** ensure your `apply()` method can be called multiple times safely:
- Use `updateOrCreate()` instead of `create()`
- Check for existing state before mutations
- Wrap in database transactions
- Return consistent `Diff` on replays

```php
public function apply(WorkOrder $order): Diff
{
    return DB::transaction(function () use ($order) {
        // Idempotent mutations
        Model::updateOrCreate(
            ['id' => $order->payload['entity_id']],
            ['status' => 'processed']
        );

        return $this->makeDiff($before, $after, 'Description');
    });
}
```

---

### 2. Require Evidence for Claims

For any factual assertion, require agents to provide:
- Source URL
- Retrieved timestamp
- Relevant quote/excerpt
- Source credibility rating
- Currency/units if applicable

```php
protected function submissionValidationRules(WorkItem $item): array
{
    return [
        'evidence' => 'required|array|min:2',
        'evidence.*.url' => 'required|url',
        'evidence.*.retrieved_at' => 'required|date',
        'evidence.*.credibility' => 'required|in:high,medium,low',
    ];
}
```

---

### 3. Implement Cross-Item Validation

Use `canApprove()` to enforce constraints across work items:

```php
protected function canApprove(WorkOrder $order): bool
{
    // Example: Require all dimensions present
    $required = ['dim_a', 'dim_b', 'dim_c'];
    $completed = $order->items
        ->pluck('result.dimension')
        ->toArray();

    return count(array_diff($required, $completed)) === 0;
}
```

---

### 4. Use Confidence Scores

Ask agents to provide confidence scores (0-1) for their findings:
- High confidence (>0.8): Auto-approve
- Medium confidence (0.5-0.8): Require additional evidence
- Low confidence (<0.5): Block approval or route to human

```php
protected function afterValidateSubmission(WorkItem $item, array $result): void
{
    if ($result['confidence'] < 0.7 && $item->input['critical']) {
        throw ValidationException::withMessages([
            'confidence' => ['Critical items require confidence >= 0.7'],
        ]);
    }
}
```

---

### 5. Plan for Failure

- Set appropriate `max_attempts` per item type
- Use retry backoff and jitter (already in config)
- Implement dead-letter handling
- Monitor `work_events` for failure patterns

```php
public function plan(WorkOrder $order): array
{
    return [
        'type' => $this->type(),
        'input' => [...],
        'max_attempts' => 3, // Allow retries
    ];
}
```

---

### 6. Audit Everything

Leverage `WorkEvent` and `WorkProvenance` for complete traceability:
- Record agent metadata (name, version, model)
- Store request fingerprints
- Log all evidence URLs
- Keep before/after diffs

Agents should include metadata in requests:

```json
{
  "result": {...},
  "evidence": [...],
  "_agent": {
    "name": "research-agent",
    "version": "1.2.0",
    "model": "claude-3-opus"
  }
}
```

---

### 7. Separate Validation from Execution

Keep validation logic in `submissionValidationRules()` and `afterValidateSubmission()`.

Keep execution logic in `apply()`.

**Never** perform side effects during validation.

---

### 8. Use Lifecycle Hooks Strategically

- `beforeApply()`: Setup, acquire locks, validate system state
- `afterApply()`: Trigger downstream jobs, clear caches, send notifications
- Don't use hooks for core business logic (keep that in `apply()`)

```php
protected function afterApply(WorkOrder $order, Diff $diff): void
{
    // Trigger follow-up work
    ProcessEnrichment::dispatch($order->payload['entity_id']);

    // Clear caches
    Cache::tags(['entities'])->flush();

    // Send notification
    event(new EntityEnriched($order));
}
```

---

### 9. Version Your Schemas and Policies

Include version numbers in payloads so you can evolve order types:

```php
public function schema(): array
{
    return [
        'type' => 'object',
        'required' => ['_schema_version'],
        'properties' => [
            '_schema_version' => ['const' => '2.0.0'],
            // ... rest of schema
        ],
    ];
}
```

---

### 10. Monitor Queue Health

Set up alerts for:
- High item lease expiration rate
- Growing dead-letter queue
- Long-running orders (stuck in states)
- Low agent throughput

Use the metrics system (already configured) to export to Prometheus/Datadog.

---

## Next Steps

1. **Choose a use case** from this document that aligns with your business needs
2. **Implement the order type** following the patterns above
3. **Register the type** in your `AppServiceProvider`
4. **Test the full lifecycle** with a development agent
5. **Set up monitoring** using `WorkEvent` queries
6. **Deploy** and monitor initial orders
7. **Iterate** based on agent feedback and validation failures

For implementation details on lifecycle hooks, see `examples/LIFECYCLE.md`.

For architecture and integration patterns, see `docs/ARCHITECTURE.md`.

For MCP server setup (agent integration), see `docs/MCP_SERVER.md`.
