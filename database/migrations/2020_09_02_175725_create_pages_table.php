<?php

use Illuminate\Database\Schema\Blueprint;
use Itstructure\Mult\Classes\MultilingualMigration;

/**
 * Class CreatePagesTable
 */
class CreatePagesTable extends MultilingualMigration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $this->createMultilingualTable('pages', function (Blueprint $table) {
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('content')->nullable();
            $table->string('meta_keys')->nullable();
            $table->string('meta_description')->nullable();

        }, function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->unsignedTinyInteger('active')->default(0)->index();
            $table->string('icon')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $this->dropMultilingualTable('pages');
    }
}
