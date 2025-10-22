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
 * Example: Customer Research with Partial Submissions
 *
 * This order type demonstrates the partial submissions feature where agents
 * can incrementally submit research findings as they gather data. Each part
 * is validated independently, then assembled and validated as a whole.
 *
 * Use Case:
 * - Long-running research tasks where agents discover data progressively
 * - Multi-faceted research where different parts can be validated independently
 * - Incremental progress tracking and early validation
 *
 * Workflow:
 * 1. Order created with company name/domain
 * 2. Single work item with multiple required parts (not multiple items)
 * 3. Agent checks out the item
 * 4. Agent submits parts incrementally as research progresses:
 *    - identity (company name, domain, industry)
 *    - firmographics (employees, revenue, locations)
 *    - web_presence (website, social profiles)
 *    - contacts (key contacts with emails)
 *    - tech_stack (technologies used)
 *    - news (recent news/events)
 * 5. Each part is validated independently before being saved
 * 6. Agent finalizes when all required parts are submitted
 * 7. System assembles and validates the complete research profile
 * 8. Order approved and applied (save to CRM/database)
 *
 * Benefits of Partial Submissions:
 * - Early validation feedback (catch errors sooner)
 * - Progress visibility (see which parts are complete)
 * - Incremental checkpoints (resume if interrupted)
 * - Flexible ordering (submit parts in any order)
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
                    'description' => 'Depth of research to perform',
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
                                'item_id' => $item->id,
                            ]);
                        }
                    }
                }
            }
        }
    }

    /**
     * Assemble all parts into a complete research profile.
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

        // Validate required fields are present
        if (empty($assembled['identity']) || empty($assembled['firmographics'])) {
            throw ValidationException::withMessages([
                'assembled' => ['Research must include at least identity and firmographics'],
            ]);
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

                // In real implementation, save to database
                Log::info('Applying customer research profile', [
                    'company' => $companyName,
                    'domain' => $domain,
                    'confidence' => $profile['_meta']['overall_confidence'] ?? 0,
                    'parts_count' => $profile['_meta']['parts_count'] ?? 0,
                ]);

                // Example: Upsert customer record
                // $customer = Customer::updateOrCreate(
                //     ['domain' => $domain],
                //     [
                //         'name' => $companyName,
                //         'industry' => $profile['identity']['industry'] ?? null,
                //         'employees' => $profile['firmographics']['employees'] ?? null,
                //         'enriched_at' => now(),
                //     ]
                // );

                // Example: Store enrichment data
                // foreach ($profile as $facet => $data) {
                //     if (str_starts_with($facet, '_')) continue;
                //     CustomerEnrichment::create([
                //         'customer_id' => $customer->id,
                //         'facet' => $facet,
                //         'data' => $data,
                //         'confidence' => $data['confidence'] ?? 1.0,
                //     ]);
                // }

                $after[$companyName] = 'enriched';
            }
        });

        $companiesCount = count($after);

        return $this->makeDiff(
            $before,
            $after,
            "Applied research for {$companiesCount} " . str('company')->plural($companiesCount)
        );
    }

    /**
     * Normalize a domain for comparison.
     */
    protected function normalizeDomain(string $domain): string
    {
        $parsed = parse_url($domain, PHP_URL_HOST) ?? $domain;

        return strtolower(trim($parsed));
    }

    /**
     * Extract domain from email address.
     */
    protected function getDomainFromEmail(string $email): string
    {
        $parts = explode('@', $email);

        return isset($parts[1]) ? strtolower(trim($parts[1])) : '';
    }
}
