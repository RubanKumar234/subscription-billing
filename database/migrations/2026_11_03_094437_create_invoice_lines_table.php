<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInvoiceLinesTable extends Migration
{
    public function up()
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('subscription_segment_id')->constrained('subscription_segments')->restrictOnDelete();
            $table->string('type', 20);
            $table->string('description');
            $table->decimal('quantity', 20, 6);
            $table->decimal('unit_rate_minor', 18, 6);
            $table->unsignedBigInteger('amount_minor');
            $table->timestamps();

            $table->index(['invoice_id', 'type']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('invoice_lines');
    }
}
