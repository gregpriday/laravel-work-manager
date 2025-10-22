<?php

namespace App\WorkTypes;

use App\Models\Customer;
use App\Models\CustomerEnrichment;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\AbstractOrderType;
use GregPriday\WorkManager\Support\Diff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Example: Customer Research & Enrichment
 *
 * This order type orchestrates comprehensive research on customers/prospects.
 * Agents gather firmographics, web presence, tech stack, contacts, and recent news.
 * All findings require evidence (URLs, quotes, timestamps) for auditability.
 *
 * Use Case: Sales teams need rich context before outreach; automated competitive intelligence.
 *
 * Workflow:
 * 1. CRM event or manual trigger creates order with customer_id
 * 2. Order splits into facets: identity, firmographics, web_presence, contacts, tech_stack, news
 * 3. Agents lease facets, research online, submit structured results with evidence
 * 4. System validates evidence URLs, confidence scores, and required fields
 * 5. When all required facets complete, order is approved
 * 6. Apply writes normalized enrichment records to database
 * 7. Customer marked as enriched with timestamp
 */
class CustomerResearchType extends AbstractOrderType
{
    /**
     * Unique identifier for this work order type.
     */
    public function type(): string
    {
        return 'customer.research';
    }

    /**
     * JSON schema for validating the initial payload.
     *
     * Required: customer_id
     * Optional: company_domain, depth, markets
     */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['customer_id'],
            'properties' => [
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'Database ID of the customer to research',
                ],
                'company_domain' => [
                    'type' => 'string',
                    'format' => 'hostname',
                    'description' => 'Company website domain (e.g., acme.com)',
                ],
                'depth' => [
                    'type' => 'string',
                    'enum' => ['quick', 'standard', 'deep'],
                    'default' => 'standard',
                    'description' => 'Research depth: quick (3 facets), standard (5 facets), deep (all facets)',
                ],
                'markets' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Target markets/regions to focus on',
                ],
            ],
        ];
    }

    /**
     * Break the order into work items (one per research facet).
     *
     * Facets are parallel-executable and can be claimed by different agents.
     */
    public function plan(WorkOrder $order): array
    {
        $depth = $order->payload['depth'] ?? 'standard';

        $allFacets = [
            'identity',          // Company name, legal entity, registration
            'firmographics',     // Size, revenue, industry, location
            'web_presence',      // Website, social profiles, content
            'contacts',          // Key personnel, decision makers
            'tech_stack',        // Technologies used (from job postings, etc.)
            'recent_news',       // Press releases, funding, partnerships
        ];

        // Select facets based on depth
        $facets = match ($depth) {
            'quick' => ['identity', 'firmographics', 'web_presence'],
            'deep' => $allFacets,
            default => ['identity', 'firmographics', 'web_presence', 'contacts', 'tech_stack'],
        };

        return array_map(fn ($facet) => [
            'type' => $this->type(),
            'input' => [
                'facet' => $facet,
                'customer_id' => $order->payload['customer_id'],
                'company_domain' => $order->payload['company_domain'] ?? null,
                'depth' => $depth,
                'markets' => $order->payload['markets'] ?? [],
            ],
            'max_attempts' => 3,
        ], $facets);
    }

    /**
     * Laravel validation rules for agent submissions.
     *
     * Each submission must include:
     * - success flag
     * - facet identifier
     * - sections array with key/value/confidence/evidence
     */
    protected function submissionValidationRules(WorkItem $item): array
    {
        return [
            'success' => 'required|boolean',
            'facet' => 'required|string',
            'sections' => 'required|array|min:1',
            'sections.*.key' => 'required|string',
            'sections.*.value' => 'required',
            'sections.*.confidence' => 'required|numeric|min:0|max:1',
            'sections.*.evidence' => 'required|array|min:1',
            'sections.*.evidence.*.url' => 'required|url',
            'sections.*.evidence.*.retrieved_at' => 'required|date',
            'sections.*.evidence.*.quote' => 'nullable|string|max:500',
            'sections.*.evidence.*.source_name' => 'nullable|string',
        ];
    }

    /**
     * Additional validation after Laravel rules pass.
     *
     * Business logic checks:
     * - Verify evidence URLs are accessible
     * - Require high confidence for critical facets
     * - Validate domain matches expected customer
     */
    protected function afterValidateSubmission(WorkItem $item, array $result): void
    {
        // Check that the reported facet matches the work item input
        if ($result['facet'] !== $item->input['facet']) {
            throw ValidationException::withMessages([
                'facet' => ["Expected facet '{$item->input['facet']}', got '{$result['facet']}'"],
            ]);
        }

        // Verify domains are accessible (sample check on first evidence item)
        foreach (array_slice($result['sections'], 0, 3) as $section) {
            if (! empty($section['evidence'][0]['url'])) {
                $url = $section['evidence'][0]['url'];
                $domain = parse_url($url, PHP_URL_HOST);

                if (! $this->isDomainAccessible($domain)) {
                    throw ValidationException::withMessages([
                        'evidence.url' => ["Domain {$domain} is not accessible or does not exist"],
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
                        'confidence' => ['Critical facets (identity, firmographics) require confidence >= 0.7'],
                    ]);
                }
            }
        }

        // Ensure we have substantive value (not just "N/A" or empty)
        foreach ($result['sections'] as $section) {
            $value = is_string($section['value']) ? $section['value'] : json_encode($section['value']);
            if (strlen(trim($value)) < 3) {
                throw ValidationException::withMessages([
                    'sections.value' => ['Section values must be substantive (min 3 characters)'],
                ]);
            }
        }
    }

    /**
     * Determine if the order is ready for approval.
     *
     * Requirements:
     * - All required facets must be submitted
     * - At least one section per facet with high confidence
     */
    protected function canApprove(WorkOrder $order): bool
    {
        $depth = $order->payload['depth'] ?? 'standard';

        $requiredFacets = match ($depth) {
            'quick' => ['identity', 'firmographics'],
            'deep' => ['identity', 'firmographics', 'web_presence', 'contacts'],
            default => ['identity', 'firmographics', 'web_presence'],
        };

        $completedFacets = $order->items
            ->whereIn('state', ['submitted', 'accepted', 'completed'])
            ->where('result', '!=', null)
            ->pluck('result')
            ->pluck('facet')
            ->unique()
            ->toArray();

        // Check that all required facets are present
        if (count(array_diff($requiredFacets, $completedFacets)) > 0) {
            return false;
        }

        // Ensure at least one high-confidence section per critical facet
        foreach ($order->items as $item) {
            if (! $item->result || ! in_array($item->result['facet'], $requiredFacets)) {
                continue;
            }

            $hasHighConfidence = collect($item->result['sections'] ?? [])
                ->contains(fn ($section) => $section['confidence'] >= 0.8);

            if (! $hasHighConfidence) {
                return false;
            }
        }

        return true;
    }

    /**
     * Hook called before applying the work order.
     *
     * Use for setup or pre-checks.
     */
    protected function beforeApply(WorkOrder $order): void
    {
        $customerId = $order->payload['customer_id'];

        Log::info('Starting customer research application', [
            'order_id' => $order->id,
            'customer_id' => $customerId,
            'facet_count' => $order->items->count(),
        ]);

        // Could do things like:
        // - Verify customer still exists
        // - Acquire locks to prevent concurrent enrichment
        // - Backup existing enrichment data
    }

    /**
     * Apply the research findings to the database.
     *
     * This is idempotent: can be called multiple times safely.
     */
    public function apply(WorkOrder $order): Diff
    {
        $customerId = $order->payload['customer_id'];

        // Capture before state
        $before = [
            'enriched_at' => Customer::find($customerId)->enriched_at,
            'enrichment_count' => CustomerEnrichment::where('customer_id', $customerId)->count(),
        ];

        DB::transaction(function () use ($order, $customerId) {
            // Optionally clear existing enrichment (or use versioning)
            // CustomerEnrichment::where('customer_id', $customerId)->delete();

            foreach ($order->items as $item) {
                if (! $item->result) {
                    continue;
                }

                foreach ($item->result['sections'] as $section) {
                    CustomerEnrichment::updateOrCreate(
                        [
                            'customer_id' => $customerId,
                            'facet' => $item->result['facet'],
                            'key' => $section['key'],
                        ],
                        [
                            'value' => $section['value'],
                            'confidence' => $section['confidence'],
                            'evidence' => $section['evidence'],
                            'verified_at' => now(),
                            'order_id' => $order->id,
                        ]
                    );
                }
            }

            // Mark customer as enriched
            Customer::where('id', $customerId)->update([
                'enriched_at' => now(),
                'enrichment_version' => now()->timestamp,
            ]);
        });

        // Capture after state
        $after = [
            'enriched_at' => Customer::find($customerId)->enriched_at,
            'enrichment_count' => CustomerEnrichment::where('customer_id', $customerId)->count(),
        ];

        return $this->makeDiff(
            $before,
            $after,
            "Enriched customer {$customerId} with {$order->items->count()} facets"
        );
    }

    /**
     * Hook called after successful apply.
     *
     * Use for cleanup or triggering downstream processes.
     */
    protected function afterApply(WorkOrder $order, Diff $diff): void
    {
        $customerId = $order->payload['customer_id'];

        Log::info('Successfully applied customer research', [
            'order_id' => $order->id,
            'customer_id' => $customerId,
            'changes' => $diff->toArray(),
        ]);

        // Trigger follow-up work
        // dispatch(new CalculateICPScore($customerId));
        // dispatch(new GenerateSalesBrief($customerId));

        // Clear caches
        // Cache::tags(['customers', "customer.{$customerId}"])->flush();

        // Send notification
        // event(new CustomerEnriched($customerId, $order));
    }

    /**
     * Check if a domain is accessible (basic validation).
     *
     * In production, you might want to:
     * - Check DNS resolution
     * - Verify HTTP/HTTPS accessibility
     * - Check for robots.txt compliance
     */
    protected function isDomainAccessible(string $domain): bool
    {
        // Basic check: does domain have DNS records?
        $ip = gethostbyname($domain);

        // If gethostbyname returns the input, DNS failed
        if ($ip === $domain) {
            return false;
        }

        // Optional: Check if domain responds to HTTP
        try {
            $response = Http::timeout(5)->get("https://{$domain}");

            return $response->successful() || $response->status() < 500;
        } catch (\Exception $e) {
            // If HTTPS fails, try HTTP
            try {
                $response = Http::timeout(5)->get("http://{$domain}");

                return $response->successful() || $response->status() < 500;
            } catch (\Exception $e) {
                return false;
            }
        }
    }
}
