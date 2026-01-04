<?php
/**
 * Migration: Create profiles table
 */

use NanoORM\Schema;
use NanoORM\Blueprint;

return [
    'up' => Schema::create('profiles', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id')->unique();
        $table->string('bio', 500)->nullable();
        $table->string('website')->nullable();
        $table->string('location')->nullable();
        $table->string('avatar_url')->nullable();
        $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    }),

    'down' => Schema::drop('profiles'),
];
