<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCustomersTable extends Migration
{
    public function up()
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('email')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'name']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('customers');
    }
}
