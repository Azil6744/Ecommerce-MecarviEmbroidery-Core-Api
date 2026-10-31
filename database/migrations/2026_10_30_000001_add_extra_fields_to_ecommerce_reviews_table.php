<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ecommerce_reviews')) {
            return;
        }

        Schema::table('ecommerce_reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('ecommerce_reviews', 'customer_email')) {
                $table->string('customer_email')->nullable()->after('customer_name');
            }
            if (! Schema::hasColumn('ecommerce_reviews', 'order_number')) {
                $table->string('order_number')->nullable()->after('customer_email');
            }
            if (! Schema::hasColumn('ecommerce_reviews', 'verified_buyer')) {
                $table->boolean('verified_buyer')->default(true)->after('order_number');
            }
            if (! Schema::hasColumn('ecommerce_reviews', 'images')) {
                $table->json('images')->nullable()->after('comment');
            }
            if (! Schema::hasColumn('ecommerce_reviews', 'admin_reply')) {
                $table->text('admin_reply')->nullable()->after('status');
            }
            if (! Schema::hasColumn('ecommerce_reviews', 'admin_reply_at')) {
                $table->timestamp('admin_reply_at')->nullable()->after('admin_reply');
            }
        });

        DB::table('ecommerce_reviews')
            ->whereRaw('LOWER(status) = ?', ['pending'])
            ->update(['status' => 'pending']);

        DB::table('ecommerce_reviews')
            ->whereRaw('LOWER(status) = ?', ['approved'])
            ->update(['status' => 'approved']);

        DB::table('ecommerce_reviews')
            ->whereRaw("LOWER(status) IN ('rejected', 'declined')")
            ->update(['status' => 'rejected']);

        if (Schema::hasTable('ecommerce_product_questions') && Schema::hasTable('products') && DB::table('ecommerce_product_questions')->count() === 0) {
            $products = DB::table('products')->limit(3)->get();
            foreach ($products as $p) {
                $q1Id = DB::table('ecommerce_product_questions')->insertGetId([
                    'product_id' => $p->id,
                    'customer_name' => 'Sarah Jenkins',
                    'customer_email' => 'sarah.jenkins@example.com',
                    'question' => 'Can I upload a custom DST or vector logo file for embroidery on ' . $p->name . '?',
                    'status' => 'answered',
                    'created_at' => now()->subDays(2),
                    'updated_at' => now()->subDay(),
                ]);

                if (Schema::hasTable('ecommerce_product_question_replies')) {
                    DB::table('ecommerce_product_question_replies')->insert([
                        'product_question_id' => $q1Id,
                        'name' => 'Mecarvi Support Team',
                        'role' => 'admin',
                        'content' => 'Yes! You can upload DST, EMB, PDF, AI, EPS, or high-resolution PNG files directly during customization and our digitizing team will prepare a proof for your approval.',
                        'helpful_count' => 3,
                        'created_at' => now()->subDay(),
                        'updated_at' => now()->subDay(),
                    ]);
                }

                DB::table('ecommerce_product_questions')->insert([
                    'product_id' => $p->id,
                    'customer_name' => 'Michael Reynolds',
                    'customer_email' => 'm.reynolds@example.com',
                    'question' => 'Does the embroidery backing hold up well after repeated industrial washing?',
                    'status' => 'unanswered',
                    'created_at' => now()->subHours(6),
                    'updated_at' => now()->subHours(6),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('ecommerce_reviews')) {
            return;
        }

        Schema::table('ecommerce_reviews', function (Blueprint $table) {
            $columns = ['customer_email', 'order_number', 'verified_buyer', 'images', 'admin_reply', 'admin_reply_at'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('ecommerce_reviews', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
