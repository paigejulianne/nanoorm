<?php
/**
 * Migration: Create users table
 */

use NanoORM\Schema;
use NanoORM\Blueprint;

return [
    'up' => Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('uuid', 36)->unique();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->boolean('is_admin')->default(false);
        $table->text('settings')->nullable();
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }),

    'down' => Schema::drop('users'),
];
