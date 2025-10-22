<?php

namespace GregPriday\WorkManager\Tests\Feature;

use GregPriday\WorkManager\Events\WorkItemFinalized;
use GregPriday\WorkManager\Events\WorkItemPartRejected;
use GregPriday\WorkManager\Events\WorkItemPartSubmitted;
use GregPriday\WorkManager\Events\WorkItemPartValidated;
use GregPriday\WorkManager\Models\WorkItem;
use GregPriday\WorkManager\Models\WorkOrder;
use GregPriday\WorkManager\Services\LeaseService;
use GregPriday\WorkManager\Services\WorkAllocator;
use GregPriday\WorkManager\Services\WorkExecutor;
use GregPriday\WorkManager\Support\AbstractOrderType;
use GregPriday\WorkManager\Support\ActorType;
use GregPriday\WorkManager\Support\Diff;
use GregPriday\WorkManager\Support\EventType;
use GregPriday\WorkManager\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

class PartialSubmissionsEventsTest extends TestCase
{
    use RefreshDatabase;

    protected WorkAllocator $allocator;
    protected WorkExecutor $executor;
    protected LeaseService $leaseService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->allocator = app(WorkAllocator::class);
        $this->executor = app(WorkExecutor::class);
        $this->leaseService = app(LeaseService::class);

        app('work-manager')->registry()->register(new TestPartialEventsOrderType());
    }

    public function test_submit_part_dispatches_submitted_and_validated_events()
    {
        Event::fake([WorkItemPartSubmitted::class, WorkItemPartValidated::class]);

        $order = $this->allocator->propose(
            type: 'test.partial.events',
            payload: ['company' => 'Acme'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');
        $item = $item->fresh();

        $this->executor->submitPart($item, 'identity', null, ['name' => 'Acme'], 'agent-1');

        Event::assertDispatched(WorkItemPartSubmitted::class, function ($event) {
            return $event->part->part_key === 'identity';
        });

        Event::assertDispatched(WorkItemPartValidated::class, function ($event) {
            return $event->part->part_key === 'identity' &&
                   $event->part->status->value === 'validated';
        });
    }

    public function test_invalid_part_dispatches_rejected_event()
    {
        Event::fake([WorkItemPartRejected::class]);

        $order = $this->allocator->propose(
            type: 'test.partial.events',
            payload: ['company' => 'Acme'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');
        $item = $item->fresh();

        try {
            $this->executor->submitPart($item, 'identity', null, [], 'agent-1'); // Missing required field
        } catch (ValidationException $e) {
            // Expected
        }

        Event::assertDispatched(WorkItemPartRejected::class, function ($event) {
            return $event->part->part_key === 'identity' &&
                   $event->part->status->value === 'rejected' &&
                   !empty($event->part->errors);
        });
    }

    public function test_finalize_dispatches_finalized_event()
    {
        Event::fake([WorkItemFinalized::class]);

        $order = $this->allocator->propose(
            type: 'test.partial.events',
            payload: ['company' => 'Acme'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');
        $item = $item->fresh();

        $this->executor->submitPart($item, 'identity', null, ['name' => 'Acme'], 'agent-1');
        $this->executor->submitPart($item, 'contacts', null, ['email' => 'test@acme.com'], 'agent-1');

        $this->executor->finalizeItem($item);

        Event::assertDispatched(WorkItemFinalized::class, function ($event) use ($item) {
            return $event->item->id === $item->id &&
                   $event->item->state->value === 'submitted';
        });
    }

    public function test_work_event_records_part_submission()
    {
        $order = $this->allocator->propose(
            type: 'test.partial.events',
            payload: ['company' => 'Acme'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');
        $item = $item->fresh();

        $evidence = [['url' => 'https://acme.com']];
        $notes = 'Found on website';

        $this->executor->submitPart(
            $item,
            'identity',
            1,
            ['name' => 'Acme'],
            'agent-1',
            $evidence,
            $notes
        );

        // Check that work event was recorded
        $events = $item->events()
            ->where('event', EventType::SUBMITTED->value)
            ->get();

        $this->assertNotEmpty($events);

        $event = $events->first();
        $this->assertEquals('identity', $event->payload['part_key']);
        $this->assertEquals(1, $event->payload['seq']);
        $this->assertEquals($evidence, $event->payload['evidence']);
        $this->assertEquals($notes, $event->payload['notes']);
        $this->assertEquals('agent-1', $event->actor_id);
        $this->assertEquals(ActorType::AGENT->value, $event->actor_type->value);
    }

    public function test_finalize_records_event_with_parts_count()
    {
        $order = $this->allocator->propose(
            type: 'test.partial.events',
            payload: ['company' => 'Acme'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');
        $item = $item->fresh();

        $this->executor->submitPart($item, 'identity', null, ['name' => 'Acme'], 'agent-1');
        $this->executor->submitPart($item, 'contacts', null, ['email' => 'test@acme.com'], 'agent-1');

        $this->executor->finalizeItem($item);

        // Check finalize event payload
        $events = $item->events()
            ->where('event', EventType::SUBMITTED->value)
            ->get();

        $finalizeEvent = $events->first(fn ($e) => isset($e->payload['assembled']));

        $this->assertNotNull($finalizeEvent);
        $this->assertTrue($finalizeEvent->payload['assembled']);
        $this->assertEquals(2, $finalizeEvent->payload['parts_count']);
    }

    public function test_part_events_include_payload_data()
    {
        Event::fake([WorkItemPartValidated::class]);

        $order = $this->allocator->propose(
            type: 'test.partial.events',
            payload: ['company' => 'Acme'],
            requestedByType: ActorType::AGENT,
            requestedById: 'agent-1'
        );

        $item = $order->items()->first();
        $item = $this->leaseService->acquire($item->id, 'agent-1');
        $item = $item->fresh();

        $payload = ['name' => 'Acme Corporation', 'industry' => 'Technology'];
        $this->executor->submitPart($item, 'identity', null, $payload, 'agent-1');

        Event::assertDispatched(WorkItemPartValidated::class, function ($event) use ($payload) {
            return $event->part->payload === $payload &&
                   $event->part->submitted_by_agent_id === 'agent-1';
        });
    }
}

class TestPartialEventsOrderType extends AbstractOrderType
{
    public function type(): string
    {
        return 'test.partial.events';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['company'],
            'properties' => ['company' => ['type' => 'string']],
        ];
    }

    public function requiredParts(WorkItem $item): array
    {
        return ['identity', 'contacts'];
    }

    public function partialRules(WorkItem $item, string $partKey, ?int $seq): array
    {
        return match ($partKey) {
            'identity' => ['name' => 'required|string'],
            'contacts' => ['email' => 'required|email'],
            default => [],
        };
    }

    public function plan(WorkOrder $order): array
    {
        return [[
            'type' => $this->type(),
            'input' => $order->payload,
            'parts_required' => ['identity', 'contacts'],
            'max_attempts' => 3,
        ]];
    }

    public function apply(WorkOrder $order): Diff
    {
        return $this->emptyDiff();
    }
}
