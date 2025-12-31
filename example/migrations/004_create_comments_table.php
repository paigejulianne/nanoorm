<?php
/**
 * Migration: Create comments table
 */

use NanoORM\Schema;
use NanoORM\Blueprint;

return [
    'up' => Schema::create('comments', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('post_id');
        $table->unsignedBigInteger('user_id');
        $table->text('body');
        $table->timestamps();
        $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
        $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    }),

    'down' => Schema::drop('comments'),
];
