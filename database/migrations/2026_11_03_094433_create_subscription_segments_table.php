<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateSubscriptionSegmentsTable extends Migration
{
    public function up()
    {
        Schema::create('subscription_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->dateTime('starts_at', 6);
            $table->dateTime('ends_at', 6)->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('base_price_minor');
            $table->unsignedBigInteger('included_units');
            $table->decimal('overage_rate_minor', 18, 6);
            $table->string('billing_cycle', 20);
            $table->timestamps();

            $table->index(['subscription_id', 'starts_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('subscription_segments');
    }
}
