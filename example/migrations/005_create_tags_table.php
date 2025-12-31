<?php
/**
 * Migration: Create tags table
 */

use NanoORM\Schema;
use NanoORM\Blueprint;

return [
    'up' => Schema::create('tags', function (Blueprint $table) {
        $table->id();
        $table->string('name')->unique();
        $table->string('slug')->unique();
    }),

    'down' => Schema::drop('tags'),
];
