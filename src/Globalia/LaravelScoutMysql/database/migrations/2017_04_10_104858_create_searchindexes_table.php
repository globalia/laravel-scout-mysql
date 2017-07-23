<?php

use Globalia\LaravelScoutMysql\Models\SearchIndex;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSearchindexesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create(self::table(), function (Blueprint $table) {
            $table->increments('id');
            $table->string('indexable_type');
            $table->unsignedInteger('indexable_id');
            $table->timestamp('indexed_at');

            $table->unique(['indexable_type', 'indexable_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists(self::table());
    }

    private static function table()
    {
        return with(new SearchIndex)->getTable();
    }
}
