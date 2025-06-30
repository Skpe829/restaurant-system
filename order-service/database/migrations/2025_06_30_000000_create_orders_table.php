<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('order_number')->unique();
            $table->string('status')->default('pending');
            $table->integer('quantity');
            $table->string('customer_name');
            $table->json('selected_recipes')->nullable();
            $table->json('required_ingredients')->nullable();
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->timestamp('estimated_completion_at')->nullable();
            $table->timestamps();
            
            // Index for status queries
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
