<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateClicksTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
      //Script will check this table to count clicks
      Schema::create('clicks', function (Blueprint $table) {
          $table->increments('id');
          $table->string('name', 100);
          $table->string('path', 200);
          $table->integer('clicks', 0);
          $table->integer('file_id', 0);
          $table->timestamps();
      });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('clicks');
    }
}
