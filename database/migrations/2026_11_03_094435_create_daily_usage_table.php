<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateDailyUsageTable extends Migration
{
    public function up()
    {
        Schema::create('daily_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_segment_id')->constrained('subscription_segments')->restrictOnDelete();
            $table->date('usage_date');
            $table->unsignedBigInteger('units')->default(0);
            $table->timestamps();

            $table->unique(['subscription_segment_id', 'usage_date']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('daily_usage');
    }
}
