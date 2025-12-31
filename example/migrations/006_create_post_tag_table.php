<?php
/**
 * Migration: Create post_tag pivot table
 */

use NanoORM\Schema;
use NanoORM\Blueprint;

return [
    'up' => Schema::create('post_tag', function (Blueprint $table) {
        $table->unsignedBigInteger('post_id');
        $table->unsignedBigInteger('tag_id');
        $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
        $table->foreign('tag_id')->references('id')->on('tags')->cascadeOnDelete();
    }),

    'down' => Schema::drop('post_tag'),
];
