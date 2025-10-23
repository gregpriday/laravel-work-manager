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
            $table->unsignedSmallInteger('priority')->default(0);

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
            $table->index(['state', 'type'], 'work_orders_state_type_idx');
            $table->index(['requested_by_type', 'requested_by_id'], 'work_orders_requester_idx');
        });

        // Add optimized composite index for checkout queries
        // Supports: WHERE state='queued' ORDER BY priority DESC, created_at ASC
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb' || $driver === 'pgsql') {
            DB::statement('CREATE INDEX work_orders_checkout_idx ON work_orders (state, priority DESC, created_at ASC)');
        } else {
            // SQLite and other drivers don't support index direction
            DB::statement('CREATE INDEX work_orders_checkout_idx ON work_orders (state, priority, created_at)');
        }

        // Work Items table
        Schema::create('work_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('work_orders')->cascadeOnDelete();
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
            $table->string('leased_by_agent_id')->nullable()->index();
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

            // Indexes for common query patterns
            $table->index(['order_id', 'state'], 'work_items_order_state_idx');
            $table->index(['state', 'lease_expires_at'], 'work_items_lease_expiry_idx');
            $table->index(['type', 'state'], 'work_items_type_state_idx');
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

            // Indexes for event queries and timeline pagination
            $table->index(['order_id', 'event'], 'work_events_order_event_idx');
            $table->index(['order_id', 'created_at'], 'work_events_order_timeline_idx');
            $table->index(['item_id', 'event'], 'work_events_item_event_idx');
            $table->index(['event', 'created_at'], 'work_events_event_time_idx');
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

            // Indexes for audit and provenance queries
            $table->index(['order_id', 'created_at'], 'work_prov_order_timeline_idx');
            $table->index('request_fingerprint', 'work_prov_fingerprint_idx');
        });

        // Work Idempotency Keys table
        Schema::create('work_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 80);
            $table->char('key_hash', 64);
            $table->json('response_snapshot')->nullable();
            $table->timestamp('created_at');

            // Unique constraint and indexes
            $table->unique(['scope', 'key_hash'], 'work_idem_scope_hash_unique');
            $table->index('created_at', 'work_idem_created_idx'); // For TTL cleanup
        });

        // Work Item Parts table (for partial submissions)
        Schema::create('work_item_parts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_item_id')->constrained('work_items')->cascadeOnDelete();
            $table->string('part_key', 120)->index();
            $table->unsignedInteger('seq')->nullable(); // Nullable for single/unordered parts
            $table->enum('status', ['draft', 'validated', 'rejected'])->default('draft')->index();

            // Data
            $table->json('payload');
            $table->json('evidence')->nullable();
            $table->text('notes')->nullable();
            $table->json('errors')->nullable();
            $table->string('checksum')->nullable();

            // Agent tracking
            $table->string('submitted_by_agent_id')->index();
            $table->char('idempotency_key_hash', 64)->nullable();

            $table->timestamps();

            // Unique constraint for part_key and seq
            // Note: Multiple NULL seq values are allowed per part_key (by design)
            $table->unique(['work_item_id', 'part_key', 'seq'], 'work_parts_item_key_seq_unique');

            // Indexes for common query patterns
            $table->index(['work_item_id', 'status'], 'work_parts_item_status_idx');
            $table->index(['work_item_id', 'part_key'], 'work_parts_item_key_idx');
        });
    }

    public function down(): void
    {
        // Temporarily disable foreign key constraints for clean drop
        Schema::disableForeignKeyConstraints();

        // Drop tables in reverse order (respecting foreign key constraints)
        Schema::dropIfExists('work_item_parts');
        Schema::dropIfExists('work_idempotency_keys');
        Schema::dropIfExists('work_provenances');
        Schema::dropIfExists('work_events');
        Schema::dropIfExists('work_items');
        Schema::dropIfExists('work_orders');

        Schema::enableForeignKeyConstraints();
    }
};
