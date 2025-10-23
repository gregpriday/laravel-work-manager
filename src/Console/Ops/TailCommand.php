<?php

namespace GregPriday\WorkManager\Console\Ops;

use GregPriday\WorkManager\Models\WorkEvent;
use Illuminate\Console\Command;

/**
 * Tail WorkManager events (audit log).
 *
 * @internal Admin command for debugging
 *
 * @see docs/reference/commands-reference.md
 */
class TailCommand extends Command
{
    protected $signature = 'work-manager:ops:tail
        {--order= : Limit to a specific order}
        {--item= : Limit to a specific item}
        {--event= : Filter by event name}
        {--follow : Keep streaming}
        {--limit=50 : Number of events to show per refresh}';

    protected $description = 'Tail WorkManager events (audit log)';

    public function handle(): int
    {
        $lastId = null;

        do {
            $q = WorkEvent::query()->orderBy('id', 'desc');

            if ($o = $this->option('order')) {
                $q->where('work_order_id', $o);
            }
            if ($i = $this->option('item')) {
                $q->where('work_item_id', $i);
            }
            if ($e = $this->option('event')) {
                $q->where('event', $e);
            }

            // Only show new events if following
            if ($lastId && $this->option('follow')) {
                $q->where('id', '>', $lastId);
            }

            $events = $q->limit((int) $this->option('limit'))->get()->reverse();

            if ($events->isEmpty() && ! $this->option('follow')) {
                $this->info('No events found.');

                return self::SUCCESS;
            }

            foreach ($events as $event) {
                $actorInfo = $event->actor_type
                    ? "[{$event->actor_type->value}:".($event->actor_id ?? 'system').']'
                    : '[system]';

                $itemInfo = $event->work_item_id ? " Item:{$event->work_item_id}" : '';

                $this->line(sprintf(
                    '<fg=gray>%s</> <fg=cyan>%s</> Order:%s%s %s',
                    $event->created_at->format('Y-m-d H:i:s'),
                    $event->event->value,
                    $event->work_order_id,
                    $itemInfo,
                    $actorInfo
                ));

                if ($event->message) {
                    $this->line("  Message: {$event->message}");
                }

                if (! empty($event->metadata)) {
                    $this->line('  Metadata: '.json_encode($event->metadata));
                }

                $lastId = max($lastId ?? 0, $event->id);
            }

            if (! $this->option('follow')) {
                break;
            }

            sleep(2);
        } while (true);

        return self::SUCCESS;
    }
}
