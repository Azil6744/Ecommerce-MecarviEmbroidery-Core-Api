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
        Schema::table('store_pickup_locations', function (Blueprint $table) {
            // Pickup Availability
            $table->string('max_future_days')->default('7 Days')->after('max_pickup_radius');
            
            // Pickup Notifications
            $table->boolean('notif_order_ready')->default(true)->after('max_future_days');
            $table->boolean('notif_order_picked_up')->default(true)->after('notif_order_ready');
            $table->boolean('notif_new_pickup_alert')->default(true)->after('notif_order_picked_up');
            $table->boolean('cust_notif_email')->default(true)->after('notif_new_pickup_alert');
            $table->boolean('cust_notif_sms')->default(true)->after('cust_notif_email');
            $table->boolean('staff_notif_email')->default(true)->after('cust_notif_sms');
            $table->boolean('staff_notif_sms')->default(true)->after('staff_notif_email');
            
            // Pickup Policies
            $table->boolean('id_verification_required')->default(true)->after('staff_notif_sms');
            $table->boolean('allow_pickup_by_others')->default(true)->after('id_verification_required');
            $table->boolean('pickup_code_required')->default(true)->after('allow_pickup_by_others');
            $table->string('pickup_code_expiry')->default('24 Hours')->after('pickup_code_required');
            $table->string('order_hold_duration')->default('7 Days')->after('pickup_code_expiry');
            $table->string('late_pickup_action')->default('Cancel Order')->after('order_hold_duration');
            $table->string('refund_policy')->default('Refund to Original Payment Method')->after('late_pickup_action');
            
            // Additional Settings
            $table->boolean('allow_partial_pickup')->default(true)->after('refund_policy');
            $table->boolean('show_pickup_instructions')->default(true)->after('allow_partial_pickup');
            $table->text('additional_pickup_instructions')->nullable()->after('show_pickup_instructions');
            $table->json('pickup_settings')->nullable()->after('additional_pickup_instructions');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('store_pickup_locations', function (Blueprint $table) {
            $table->dropColumn([
                'max_future_days',
                'notif_order_ready',
                'notif_order_picked_up',
                'notif_new_pickup_alert',
                'cust_notif_email',
                'cust_notif_sms',
                'staff_notif_email',
                'staff_notif_sms',
                'id_verification_required',
                'allow_pickup_by_others',
                'pickup_code_required',
                'pickup_code_expiry',
                'order_hold_duration',
                'late_pickup_action',
                'refund_policy',
                'allow_partial_pickup',
                'show_pickup_instructions',
                'additional_pickup_instructions',
                'pickup_settings',
            ]);
        });
    }
};
