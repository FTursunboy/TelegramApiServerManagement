<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_app_id')
                ->constrained('telegram_apps')
                ->onDelete('cascade');
            
            $table->string('type')->default('user');
            
            $table->string('phone')->nullable();
            
            $table->string('bot_token')->nullable();
            
            $table->string('session_name')->unique();
            $table->string('webhook_url');
            
            $table->string('container_name')->nullable()->unique();
            $table->unsignedInteger('container_port')->nullable();
            $table->string('container_id')->nullable();
            
            $table->string('status')->default('creating');
            
            $table->text('last_error')->nullable();
            
            $table->string('telegram_user_id')->nullable();
            $table->string('telegram_username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            
            $table->timestamp('last_activity_at')->nullable();
            $table->unsignedInteger('messages_sent_count')->default(0);
            $table->timestamp('authorized_at')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_accounts');
    }
};