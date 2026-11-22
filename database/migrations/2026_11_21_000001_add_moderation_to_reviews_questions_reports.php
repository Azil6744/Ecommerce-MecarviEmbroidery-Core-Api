<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Moderation rules for product reviews, product Q&A and reported products:
 * - one admin activity log shared by all three features
 * - Q&A approval (questions and answers are hidden until approved) and Q&A abuse reports
 * - reported-product statuses Pending / Under Review / Resolved / Dismissed
 * - one review per customer per product, enforced by the database where the data allows it
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('moderation_activity_logs')) {
            Schema::create('moderation_activity_logs', function (Blueprint $table) {
                $table->id();
                $table->string('feature', 40);          // review | question | product_report
                $table->string('subject_type', 60);     // review | question | reply | product_report | qa_report
                $table->unsignedBigInteger('subject_id');
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('action', 60);
                $table->unsignedBigInteger('admin_id')->nullable();
                $table->string('admin_name')->nullable();
                $table->string('admin_email')->nullable();
                $table->json('details')->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->timestamps();

                $table->index(['feature', 'subject_type', 'subject_id']);
                $table->index('admin_id');
                $table->index('created_at');
            });
        }

        if (! Schema::hasColumn('ecommerce_product_questions', 'moderation_status')) {
            Schema::table('ecommerce_product_questions', function (Blueprint $table) {
                $table->string('moderation_status', 20)->default('pending')->after('status');
                $table->string('normalized_question', 500)->nullable()->after('question');
                $table->index(['product_id', 'moderation_status']);
            });
            // Questions that were already live stay live.
            DB::table('ecommerce_product_questions')->update(['moderation_status' => 'approved']);
        }

        DB::table('ecommerce_product_questions')->whereNull('normalized_question')->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('ecommerce_product_questions')->where('id', $row->id)->update([
                        'normalized_question' => \App\Models\EcommerceProductQuestion::normalizeText((string) $row->question),
                    ]);
                }
            });

        if (! Schema::hasColumn('ecommerce_product_question_replies', 'moderation_status')) {
            Schema::table('ecommerce_product_question_replies', function (Blueprint $table) {
                $table->string('moderation_status', 20)->default('pending')->after('content');
                $table->unsignedBigInteger('edited_by')->nullable()->after('moderation_status');
                $table->timestamp('edited_at')->nullable()->after('edited_by');
            });
            DB::table('ecommerce_product_question_replies')->update(['moderation_status' => 'approved']);
        }

        if (! Schema::hasTable('ecommerce_qa_reports')) {
            Schema::create('ecommerce_qa_reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_question_id')->constrained('ecommerce_product_questions')->onDelete('cascade');
                $table->foreignId('reply_id')->nullable()->constrained('ecommerce_product_question_replies')->onDelete('cascade');
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->string('reason', 60);
                $table->text('comment')->nullable();
                $table->string('status', 20)->default('open'); // open | resolved | dismissed
                $table->unsignedBigInteger('handled_by')->nullable();
                $table->timestamp('handled_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at']);
            });
        }

        if (Schema::hasTable('product_reports')) {
            DB::table('product_reports')->where('status', 'In Progress')->update(['status' => 'Under Review']);
            DB::table('product_reports')->where('status', 'Closed')->update(['status' => 'Dismissed']);
            if (! Schema::hasColumn('product_reports', 'reason')) {
                Schema::table('product_reports', function (Blueprint $table) {
                    $table->string('reason', 60)->nullable()->after('issue_type');
                    $table->index(['user_id', 'product_id', 'status']);
                });
            }
        }

        $this->addReviewUniqueness();
    }

    /**
     * One review per customer per product. Partial unique indexes (PostgreSQL / SQLite) cover
     * both account reviews and older guest reviews keyed by email. Skipped when existing data
     * already has duplicates, so the migration never fails on a live database; the controller
     * check still applies in that case.
     */
    private function addReviewUniqueness(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            return;
        }

        try {
            $dupUsers = DB::select('SELECT product_id, user_id FROM ecommerce_reviews WHERE user_id IS NOT NULL GROUP BY product_id, user_id HAVING COUNT(*) > 1 LIMIT 1');
            if (empty($dupUsers)) {
                DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS ecommerce_reviews_product_user_unique ON ecommerce_reviews (product_id, user_id) WHERE user_id IS NOT NULL');
            } else {
                Log::warning('Skipped ecommerce_reviews_product_user_unique: duplicate reviews already exist.');
            }

            if (Schema::hasColumn('ecommerce_reviews', 'customer_email')) {
                $dupEmails = DB::select('SELECT product_id FROM ecommerce_reviews WHERE user_id IS NULL AND customer_email IS NOT NULL GROUP BY product_id, LOWER(customer_email) HAVING COUNT(*) > 1 LIMIT 1');
                if (empty($dupEmails)) {
                    DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS ecommerce_reviews_product_guest_email_unique ON ecommerce_reviews (product_id, LOWER(customer_email)) WHERE user_id IS NULL AND customer_email IS NOT NULL');
                } else {
                    Log::warning('Skipped ecommerce_reviews_product_guest_email_unique: duplicate guest reviews already exist.');
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Could not add review uniqueness indexes: ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        try {
            DB::statement('DROP INDEX IF EXISTS ecommerce_reviews_product_user_unique');
            DB::statement('DROP INDEX IF EXISTS ecommerce_reviews_product_guest_email_unique');
        } catch (\Throwable $e) {
        }

        if (Schema::hasColumn('product_reports', 'reason')) {
            Schema::table('product_reports', function (Blueprint $table) {
                $table->dropIndex(['user_id', 'product_id', 'status']);
                $table->dropColumn('reason');
            });
        }

        Schema::dropIfExists('ecommerce_qa_reports');

        if (Schema::hasColumn('ecommerce_product_question_replies', 'moderation_status')) {
            Schema::table('ecommerce_product_question_replies', function (Blueprint $table) {
                $table->dropColumn(['moderation_status', 'edited_by', 'edited_at']);
            });
        }

        if (Schema::hasColumn('ecommerce_product_questions', 'moderation_status')) {
            Schema::table('ecommerce_product_questions', function (Blueprint $table) {
                $table->dropIndex(['product_id', 'moderation_status']);
                $table->dropColumn(['moderation_status', 'normalized_question']);
            });
        }

        Schema::dropIfExists('moderation_activity_logs');
    }
};
