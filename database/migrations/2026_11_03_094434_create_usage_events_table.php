<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUsageEventsTable extends Migration
{
    public function up()
    {
        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('subscription_segment_id')->constrained('subscription_segments')->restrictOnDelete();
            $table->string('event_id', 100);
            $table->dateTime('occurred_at', 6);
            $table->unsignedBigInteger('units');
            $table->dateTime('processed_at', 6)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['merchant_id', 'event_id']);
            $table->index(['customer_id', 'occurred_at']);
            $table->index(['processed_at', 'id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('usage_events');
    }
}
