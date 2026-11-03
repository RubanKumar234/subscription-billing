<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class IndexUsageEventsForAggregation extends Migration
{
    public function up()
    {
        Schema::table('usage_events', function (Blueprint $table) {
            $table->dropIndex('usage_events_processed_at_id_index');
            $table->index(['processed_at', 'occurred_at', 'id'], 'usage_events_aggregation_index');
        });
    }

    public function down()
    {
        Schema::table('usage_events', function (Blueprint $table) {
            $table->dropIndex('usage_events_aggregation_index');
            $table->index(['processed_at', 'id']);
        });
    }
}
