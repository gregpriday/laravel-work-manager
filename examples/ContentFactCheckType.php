<?php

namespace App\WorkTypes;

use App\Models\Content;
use App\Models\ContentFactCheck;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Support\AbstractOrderType;
use GregPriday\WorkManager\Support\Diff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Example: Content Fact-Checking
 *
 * This order type verifies factual claims in content (blog posts, marketing materials, KB articles)
 * before publication. Agents research claims, provide evidence, and flag suspicious statements.
 *
 * Use Case:
 * - Reduce misinformation and legal risk
 * - Maintain brand credibility
 * - Automate editorial review
 * - Build trust with evidence-backed content
 *
 * Workflow:
 * 1. Content draft triggers fact-check order (manual or automated on status change)
 * 2. System extracts claims (or claims are provided in payload)
 * 3. Order creates one work item per claim (enables parallel checking)
 * 4. Agents lease claims, research evidence, submit verdicts with sources
 * 5. System validates evidence quality, source credibility, and recency
 * 6. If any high-confidence false/outdated claims exist, approval is blocked
 * 7. Editor reviews rejections and makes corrections
 * 8. Re-submit for checking or approve verified content
 * 9. Apply writes fact-check records and marks content as verified
 */
class ContentFactCheckType extends AbstractOrderType
{
    /**
     * Unique identifier for this work order type.
     */
    public function type(): string
    {
        return 'content.factcheck';
    }

    /**
     * JSON schema for validating the initial payload.
     *
     * Required: content_id, policy_version, claims
     */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['content_id', 'policy_version', 'claims'],
            'properties' => [
                'content_id' => [
                    'type' => 'integer',
                    'description' => 'Database ID of the content to fact-check',
                ],
                'policy_version' => [
                    'type' => 'string',
                    'description' => 'Fact-checking policy version (for audit trail)',
                ],
                'claims' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'description' => 'Array of factual claims to verify',
                    'items' => [
                        'type' => 'object',
                        'required' => ['id', 'text', 'type'],
                        'properties' => [
                            'id' => [
                                'type' => 'string',
                                'description' => 'Unique identifier for the claim within this content',
                            ],
                            'text' => [
                                'type' => 'string',
                                'description' => 'The exact claim text to verify',
                            ],
                            'type' => [
                                'type' => 'string',
                                'enum' => ['statistic', 'quote', 'fact', 'comparison'],
                                'description' => 'Type of claim for appropriate verification strategy',
                            ],
                            'context' => [
                                'type' => 'string',
                                'description' => 'Surrounding context to help agents understand the claim',
                            ],
                            'line_number' => [
                                'type' => 'integer',
                                'description' => 'Line/paragraph number in original content',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Break the order into work items (one per claim).
     *
     * This keeps leases short and enables parallel fact-checking by multiple agents.
     */
    public function plan(WorkOrder $order): array
    {
        return array_map(fn ($claim) => [
            'type' => $this->type(),
            'input' => [
                'claim_id' => $claim['id'],
                'claim_text' => $claim['text'],
                'claim_type' => $claim['type'],
                'context' => $claim['context'] ?? null,
                'line_number' => $claim['line_number'] ?? null,
                'content_id' => $order->payload['content_id'],
                'policy_version' => $order->payload['policy_version'],
            ],
            'max_attempts' => 2, // Fact-checking usually shouldn't need many retries
        ], $order->payload['claims']);
    }

    /**
     * Laravel validation rules for agent submissions.
     *
     * Each submission must include:
     * - verdict (verified, false, inconclusive, outdated)
     * - confidence score
     * - explanation
     * - evidence array with credible sources
     * - optional corrections for false claims
     */
    protected function submissionValidationRules(WorkItem $item): array
    {
        return [
            'claim_id' => 'required|string',
            'verdict' => 'required|in:verified,false,inconclusive,outdated',
            'confidence' => 'required|numeric|min:0|max:1',
            'explanation' => 'required|string|min:50|max:2000',
            'evidence' => 'required|array|min:1|max:10',
            'evidence.*.url' => 'required|url',
            'evidence.*.title' => 'required|string|max:500',
            'evidence.*.quote' => 'nullable|string|max:1000',
            'evidence.*.retrieved_at' => 'required|date',
            'evidence.*.source_credibility' => 'required|in:high,medium,low',
            'evidence.*.author' => 'nullable|string',
            'evidence.*.published_date' => 'nullable|date',
            'corrections' => 'nullable|array',
            'corrections.*.original' => 'required|string',
            'corrections.*.corrected' => 'required|string',
            'corrections.*.source' => 'required|url',
            'corrections.*.reasoning' => 'required|string',
        ];
    }

    /**
     * Additional validation after Laravel rules pass.
     *
     * Business logic checks:
     * - High-confidence false claims must have corrections
     * - Verified claims require at least one high-credibility source
     * - Statistics require recent evidence (< 2 years old)
     * - Claim ID must match input
     */
    protected function afterValidateSubmission(WorkItem $item, array $result): void
    {
        // Verify claim_id matches
        if ($result['claim_id'] !== $item->input['claim_id']) {
            throw ValidationException::withMessages([
                'claim_id' => ["Expected claim_id '{$item->input['claim_id']}', got '{$result['claim_id']}'"],
            ]);
        }

        // High-confidence false claims must have corrections
        if (in_array($result['verdict'], ['false', 'outdated']) && $result['confidence'] >= 0.8) {
            if (empty($result['corrections'])) {
                throw ValidationException::withMessages([
                    'corrections' => ['High-confidence false/outdated claims require corrections'],
                ]);
            }
        }

        // Verified claims require at least one high-credibility source
        if ($result['verdict'] === 'verified') {
            $hasHighCredibility = collect($result['evidence'])
                ->contains('source_credibility', 'high');

            if (! $hasHighCredibility) {
                throw ValidationException::withMessages([
                    'evidence' => ['Verified claims require at least one high-credibility source'],
                ]);
            }
        }

        // Statistics must have recent evidence (< 2 years old)
        if ($item->input['claim_type'] === 'statistic') {
            foreach ($result['evidence'] as $evidence) {
                $retrievedAt = \Carbon\Carbon::parse($evidence['retrieved_at']);
                $ageYears = $retrievedAt->diffInYears(now());

                if ($ageYears > 2) {
                    throw ValidationException::withMessages([
                        'evidence.retrieved_at' => ['Statistics require evidence retrieved within the last 2 years'],
                    ]);
                }

                // If published_date is provided, check that too
                if (! empty($evidence['published_date'])) {
                    $publishedAt = \Carbon\Carbon::parse($evidence['published_date']);
                    $publishAgeYears = $publishedAt->diffInYears(now());

                    if ($publishAgeYears > 3) {
                        throw ValidationException::withMessages([
                            'evidence.published_date' => ['Statistics require sources published within the last 3 years'],
                        ]);
                    }
                }
            }
        }

        // Quotes must have quote text in at least one evidence item
        if ($item->input['claim_type'] === 'quote') {
            $hasQuote = collect($result['evidence'])
                ->contains(fn ($evidence) => ! empty($evidence['quote']));

            if (! $hasQuote) {
                throw ValidationException::withMessages([
                    'evidence.quote' => ['Quote claims require at least one evidence item with the actual quote text'],
                ]);
            }
        }

        // Inconclusive with high confidence is suspicious
        if ($result['verdict'] === 'inconclusive' && $result['confidence'] > 0.7) {
            throw ValidationException::withMessages([
                'verdict' => ['Inconclusive verdict should not have confidence > 0.7'],
            ]);
        }
    }

    /**
     * Determine if the order is ready for approval.
     *
     * Block approval if:
     * - Any claims are false/outdated with high confidence (>= 0.7)
     * - Any claims are inconclusive with low confidence (< 0.5)
     */
    protected function canApprove(WorkOrder $order): bool
    {
        foreach ($order->items as $item) {
            if (! $item->result) {
                continue;
            }

            $verdict = $item->result['verdict'];
            $confidence = $item->result['confidence'];

            // Block on high-confidence false/outdated claims
            if (in_array($verdict, ['false', 'outdated']) && $confidence >= 0.7) {
                return false;
            }

            // Block on low-confidence inconclusive (needs more research)
            if ($verdict === 'inconclusive' && $confidence < 0.5) {
                return false;
            }
        }

        return true;
    }

    /**
     * Hook called before applying the work order.
     */
    protected function beforeApply(WorkOrder $order): void
    {
        $contentId = $order->payload['content_id'];

        Log::info('Starting content fact-check application', [
            'order_id' => $order->id,
            'content_id' => $contentId,
            'claim_count' => $order->items->count(),
        ]);

        // Could do things like:
        // - Verify content still exists and is in draft state
        // - Lock content to prevent concurrent modifications
        // - Create backup of current content
    }

    /**
     * Apply the fact-check results to the database.
     *
     * This is idempotent: can be called multiple times safely.
     */
    public function apply(WorkOrder $order): Diff
    {
        $contentId = $order->payload['content_id'];

        // Capture before state
        $before = [
            'status' => Content::find($contentId)->status,
            'fact_checked_at' => Content::find($contentId)->fact_checked_at,
        ];

        DB::transaction(function () use ($order, $contentId) {
            // Clear existing fact-checks for this content (or use versioning)
            ContentFactCheck::where('content_id', $contentId)->delete();

            foreach ($order->items as $item) {
                if (! $item->result) {
                    continue;
                }

                ContentFactCheck::create([
                    'content_id' => $contentId,
                    'order_id' => $order->id,
                    'claim_id' => $item->result['claim_id'],
                    'claim_text' => $item->input['claim_text'],
                    'claim_type' => $item->input['claim_type'],
                    'line_number' => $item->input['line_number'],
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
                'policy_version' => $order->payload['policy_version'],
            ]);
        });

        // Capture after state
        $after = [
            'status' => Content::find($contentId)->status,
            'fact_checked_at' => Content::find($contentId)->fact_checked_at,
        ];

        $summary = $this->generateCheckSummary($order);

        return $this->makeDiff(
            $before,
            $after,
            "Fact-checked {$order->items->count()} claims for content {$contentId}. {$summary}"
        );
    }

    /**
     * Hook called after successful apply.
     */
    protected function afterApply(WorkOrder $order, Diff $diff): void
    {
        $contentId = $order->payload['content_id'];

        Log::info('Successfully applied content fact-check', [
            'order_id' => $order->id,
            'content_id' => $contentId,
            'changes' => $diff->toArray(),
        ]);

        // Trigger follow-up work
        // dispatch(new NotifyEditor($contentId));
        // dispatch(new GenerateContentReport($contentId));

        // Clear caches
        // Cache::tags(['content', "content.{$contentId}"])->flush();

        // Send notification
        // event(new ContentFactChecked($contentId, $order));
    }

    /**
     * Generate a summary of fact-check results.
     */
    protected function generateCheckSummary(WorkOrder $order): string
    {
        $verdicts = $order->items
            ->pluck('result.verdict')
            ->countBy()
            ->toArray();

        $parts = [];
        foreach ($verdicts as $verdict => $count) {
            $parts[] = "{$count} {$verdict}";
        }

        return implode(', ', $parts);
    }
}
