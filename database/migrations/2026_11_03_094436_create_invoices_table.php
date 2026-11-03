<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInvoicesTable extends Migration
{
    public function up()
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('subscriptions')->restrictOnDelete();
            $table->dateTime('period_start', 6);
            $table->dateTime('period_end', 6);
            $table->char('currency', 3);
            $table->string('status', 20)->default('draft');
            $table->unsignedBigInteger('subtotal_minor')->default(0);
            $table->unsignedBigInteger('total_minor')->default(0);
            $table->dateTime('generated_at', 6)->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'period_start', 'period_end'], 'invoices_subscription_period_unique');
        });
    }

    public function down()
    {
        Schema::dropIfExists('invoices');
    }
}
