<?php
/**
 * Migration: Create posts table
 */

use NanoORM\Schema;
use NanoORM\Blueprint;

return [
    'up' => Schema::create('posts', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->string('title');
        $table->string('slug')->unique();
        $table->text('excerpt')->nullable();
        $table->text('body');
        $table->text('metadata')->nullable();
        $table->boolean('is_published')->default(false);
        $table->timestamp('published_at')->nullable();
        $table->integer('view_count')->default(0);
        $table->timestamps();
        $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    }),

    'down' => Schema::drop('posts'),
];
