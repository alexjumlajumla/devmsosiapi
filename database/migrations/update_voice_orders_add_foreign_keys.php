<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('voice_orders', function (Blueprint $table) {
            // Add missing foreign keys
            $table->foreignId('shop_id')->nullable()->constrained('shops')->nullOnDelete()->after('user_id');
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete()->after('shop_id');
            $table->foreignId('address_id')->nullable()->constrained('user_addresses')->nullOnDelete()->after('currency_id');
            $table->foreignId('deliveryman_id')->nullable()->constrained('users')->nullOnDelete()->after('address_id');
            
            // Add order-related fields
            $table->string('delivery_type')->nullable()->after('deliveryman_id');
            $table->double('total_price')->nullable()->after('delivery_type');
            $table->float('delivery_fee')->nullable()->after('total_price');
            $table->float('tax')->nullable()->after('delivery_fee');
            $table->float('service_fee')->nullable()->after('tax');
            $table->text('address')->nullable()->after('service_fee');
            $table->json('location')->nullable()->after('address');
            $table->timestamp('delivery_date')->nullable()->after('location');
            $table->string('delivery_time')->nullable()->after('delivery_date');
            
            // Add indexes for faster queries
            $table->index('shop_id');
            $table->index('deliveryman_id');
            $table->index('currency_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('voice_orders', function (Blueprint $table) {
            $table->dropForeign(['shop_id']);
            $table->dropForeign(['currency_id']);
            $table->dropForeign(['address_id']);
            $table->dropForeign(['deliveryman_id']);
            
            $table->dropIndex(['shop_id']);
            $table->dropIndex(['deliveryman_id']);
            $table->dropIndex(['currency_id']);
            
            $table->dropColumn([
                'shop_id', 
                'currency_id', 
                'address_id', 
                'deliveryman_id',
                'delivery_type',
                'total_price',
                'delivery_fee',
                'tax',
                'service_fee',
                'address',
                'location',
                'delivery_date',
                'delivery_time'
            ]);
        });
    }
}; 