<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddCurrencyToMerchantsTable extends Migration
{
    public function up()
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->char('currency', 3)->default('INR')->after('name');
        });
    }

    public function down()
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
}
