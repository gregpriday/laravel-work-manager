<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Work Orders table
        Schema::create('work_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 120)->index();
            $table->enum('state', [
                'queued',
                'checked_out',
                'in_progress',
                'submitted',
                'approved',
                'applied',
                'completed',
                'rejected',
                'failed',
                'dead_lettered',
            ])->default('queued')->index();
            $table->smallInteger('priority')->default(0);

            // Requester info
            $table->string('requested_by_type', 50)->nullable();
            $table->string('requested_by_id')->nullable();

            // Payload and configuration
            $table->json('payload');
            $table->json('meta')->nullable();
            $table->json('acceptance_config')->nullable();

            // Timestamps
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_transitioned_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['state', 'type']);
        });

        // Add optimized composite index for global checkout queries
        // Priority DESC for highest-priority-first, created_at ASC for FIFO within priority
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('CREATE INDEX work_orders_priority_created_at_index ON work_orders (priority DESC, created_at ASC)');
        } elseif ($driver === 'pgsql') {
            DB::statement('CREATE INDEX work_orders_priority_created_at_index ON work_orders (priority DESC, created_at ASC)');
        } elseif ($driver === 'sqlite') {
            // SQLite doesn't support index direction, but we can still create a composite index
            DB::statement('CREATE INDEX work_orders_priority_created_at_index ON work_orders (priority, created_at)');
        } else {
            // Fallback for other drivers
            DB::statement('CREATE INDEX work_orders_priority_created_at_index ON work_orders (priority, created_at)');
        }

        // Work Items table
        Schema::create('work_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('order_id');
            $table->string('type', 120)->index();
            $table->enum('state', [
                'queued',
                'leased',
                'in_progress',
                'submitted',
                'accepted',
                'rejected',
                'completed',
                'failed',
                'dead_lettered',
            ])->default('queued')->index();

            // Retry configuration
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);

            // Lease information
            $table->string('leased_by_agent_id')->nullable();
            $table->timestamp('lease_expires_at')->nullable()->index();
            $table->timestamp('last_heartbeat_at')->nullable();

            // Data
            $table->json('input');
            $table->json('result')->nullable();
            $table->json('assembled_result')->nullable();
            $table->json('parts_required')->nullable();
            $table->json('parts_state')->nullable();
            $table->json('error')->nullable();

            // Timestamps
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            // Foreign keys
            $table->foreign('order_id')
                ->references('id')
                ->on('work_orders')
                ->onDelete('cascade');

            // Indexes
            $table->index(['order_id', 'state']);
            $table->index(['state', 'lease_expires_at']);
        });

        // Work Events table
        Schema::create('work_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('order_id');
            $table->uuid('item_id')->nullable();
            $table->string('event', 100)->index();

            // Actor information
            $table->string('actor_type', 50)->nullable();
            $table->string('actor_id')->nullable();

            // Event data
            $table->json('payload')->nullable();
            $table->json('diff')->nullable();
            $table->text('message')->nullable();

            $table->timestamp('created_at')->index();

            // Foreign keys
            $table->foreign('order_id')
                ->references('id')
                ->on('work_orders')
                ->onDelete('cascade');

            $table->foreign('item_id')
                ->references('id')
                ->on('work_items')
                ->onDelete('cascade');

            // Indexes
            $table->index(['order_id', 'event']);
            $table->index(['item_id', 'event']);
            $table->index(['event', 'created_at']);
        });

        // Work Provenance table
        Schema::create('work_provenances', function (Blueprint $table) {
            $table->id();
            $table->uuid('order_id')->nullable();
            $table->uuid('item_id')->nullable();
            $table->char('idempotency_key_hash', 64)->nullable()->unique();

            // Agent metadata
            $table->string('agent_version')->nullable();
            $table->string('agent_name')->nullable();
            $table->string('request_fingerprint', 64)->nullable();

            $table->json('extra')->nullable();
            $table->timestamp('created_at');

            // Foreign keys
            $table->foreign('order_id')
                ->references('id')
                ->on('work_orders')
                ->onDelete('cascade');

            $table->foreign('item_id')
                ->references('id')
                ->on('work_items')
                ->onDelete('cascade');
        });

        // Work Idempotency Keys table
        Schema::create('work_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 80);
            $table->char('key_hash', 64);
            $table->json('response_snapshot')->nullable();
            $table->timestamp('created_at');

            // Unique constraint
            $table->unique(['scope', 'key_hash']);
        });

        // Work Item Parts table (for partial submissions)
        Schema::create('work_item_parts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('work_item_id');
            $table->string('part_key', 120)->index();
            $table->unsignedInteger('seq')->nullable();
            $table->enum('status', ['draft', 'validated', 'rejected'])->default('draft')->index();

            // Data
            $table->json('payload');
            $table->json('evidence')->nullable();
            $table->text('notes')->nullable();
            $table->json('errors')->nullable();
            $table->string('checksum')->nullable();

            // Agent tracking
            $table->string('submitted_by_agent_id');
            $table->string('idempotency_key_hash')->nullable();

            $table->timestamps();

            // Foreign key
            $table->foreign('work_item_id')
                ->references('id')
                ->on('work_items')
                ->onDelete('cascade');

            // Unique constraint for part_key and seq
            $table->unique(['work_item_id', 'part_key', 'seq']);

            // Additional indexes
            $table->index(['work_item_id', 'status']);
            $table->index(['work_item_id', 'part_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_parts');
        Schema::dropIfExists('work_idempotency_keys');
        Schema::dropIfExists('work_provenances');
        Schema::dropIfExists('work_events');
        Schema::dropIfExists('work_items');

        // Drop the optimized index before dropping the table
        Schema::table('work_orders', function ($table) {
            $table->dropIndex('work_orders_priority_created_at_index');
        });

        Schema::dropIfExists('work_orders');
    }
};
