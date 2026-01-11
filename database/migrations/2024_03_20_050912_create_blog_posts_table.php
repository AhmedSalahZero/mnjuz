<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateBlogPostsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('blog_posts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('uuid', 50)->unique();
            $table->string('slug')->nullable();
            $table->integer('category_id');
            $table->string('tags')->nullable();
            $table->string('title');
            $table->text('content');
            $table->string('image')->nullable();
            $table->integer('author_id');
            $table->tinyInteger('is_featured')->default(0);
            $table->integer('published')->default(0);
            $table->tinyInteger('deleted')->default(0);
            $table->integer('created_by');
            $table->timestamp('publish_date')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('blog_posts');
    }
}

