<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Admin\RolePermissionController;
use App\Http\Controllers\Api\Admin\CategoryController;
use App\Http\Controllers\Api\Admin\ProductController;
use App\Http\Controllers\Api\Admin\ProductColorController;
use App\Http\Controllers\Api\Admin\ProductLabelController;
use App\Http\Controllers\Api\Admin\ProductBrandController;
use App\Http\Controllers\Api\Admin\ProductReportController;
use App\Http\Controllers\Api\Admin\ProductDetailDataController;
use App\Http\Controllers\Api\Admin\HomePageController;
use App\Http\Controllers\Api\Admin\MembershipPageController;
use App\Http\Controllers\Api\Admin\GiftCardPageController;
use App\Http\Controllers\Api\Admin\AboutSectionController;
use App\Http\Controllers\Api\Admin\ServiceSectionController;
use App\Http\Controllers\Api\Admin\ServiceCardController;
use App\Http\Controllers\Api\Admin\WhatWeCreateSectionController;
use App\Http\Controllers\Api\Admin\WhatWeCreateTabController;
use App\Http\Controllers\Api\Admin\CategoryTabController;
use App\Http\Controllers\Api\Admin\WhyChooseUsSectionController;
use App\Http\Controllers\Api\Admin\WhyChooseUsTabController;
use App\Http\Controllers\Api\Admin\OurFactsSectionController;
use App\Http\Controllers\Api\Admin\OurFactController;
use App\Http\Controllers\Api\Admin\OurPromiseController;
use App\Http\Controllers\Api\Admin\ProcessStepController;
use App\Http\Controllers\Api\Admin\ReviewsSectionController;
use App\Http\Controllers\Api\Admin\ReviewController;
use App\Http\Controllers\Api\Admin\PortfolioSectionController;
use App\Http\Controllers\Api\Admin\PortfolioItemController;
use App\Http\Controllers\Api\Admin\QuoteSectionController;
use App\Http\Controllers\Api\Admin\aboutpage\HeroSectionController;
use App\Http\Controllers\Api\Admin\aboutpage\AboutFounderSectionController;
use App\Http\Controllers\Api\Admin\aboutpage\AboutCompanySectionController;
use App\Http\Controllers\Api\Admin\aboutpage\MissionVisionSectionController;
use App\Http\Controllers\Api\Admin\aboutpage\CoreValueController;
use App\Http\Controllers\Api\Admin\aboutpage\CoreValuesSectionController;
use App\Http\Controllers\Api\Admin\faqpage\FAQHeroSectionController;
use App\Http\Controllers\Api\Admin\faqpage\FAQIntroParagraphController;
use App\Http\Controllers\Api\Admin\faqpage\FAQCategoryController;
use App\Http\Controllers\Api\Admin\faqpage\FAQItemController;
use App\Http\Controllers\Api\Admin\faqpage\UserSubmittedQuestionController;
use App\Http\Controllers\Api\Admin\faqpage\FaqAskQuestionSectionController;
use App\Http\Controllers\Api\Admin\CareerPage\CareerPageHeroSectionController;
use App\Http\Controllers\Api\Admin\CareerPage\CareerCardController;
use App\Http\Controllers\Api\Admin\CareerPage\JobSectionController;
use App\Http\Controllers\Api\Admin\CareerPage\FaqSectionController;
use App\Http\Controllers\Api\Admin\CareerPage\SupportSectionController;
use App\Http\Controllers\Api\Admin\CareerPage\CareerProcedureController;
use App\Http\Controllers\Api\Admin\CareerPage\CareerCtaSectionController;
use App\Http\Controllers\Api\Admin\ContactFormSubmissionController;
use App\Http\Controllers\Api\Admin\CareerSupportFormSubmissionController;
use App\Http\Controllers\Api\QuotePage\QuoteFormSubmissionController;
use App\Http\Controllers\Api\Admin\TeamMemberController;
use App\Http\Controllers\Api\Admin\ContactPage\ContactPageHeroSectionController;
use App\Http\Controllers\Api\Admin\ContactPage\ContactCardController;
use App\Http\Controllers\Api\Admin\ContactPage\HoursOfOperationController;
use App\Http\Controllers\Api\Admin\SocialLinkController;
use App\Http\Controllers\Api\Admin\servicepage\ServicePageController;
use App\Http\Controllers\Api\Admin\servicepage\FeaturesSectionController;
use App\Http\Controllers\Api\Admin\servicepage\AnalyticsSectionController;
use App\Http\Controllers\Api\Admin\servicepage\ChartSectionController;
use App\Http\Controllers\Api\Admin\servicepage\TabSectionController;
use App\Http\Controllers\Api\Admin\servicepage\ShowcaseSectionController;
use App\Http\Controllers\Api\Admin\footer\FooterController;
use App\Http\Controllers\Api\Admin\sitesettings\SiteSettingController;
use App\Http\Controllers\Api\Admin\PageSeoSettingController;
use App\Http\Controllers\Api\Admin\ProductPage\ProductPageHeroSectionController;
use App\Http\Controllers\Api\Admin\ProductPage\ProductTabController;
use App\Http\Controllers\Api\Admin\ProductPage\ProductItemController;
use App\Http\Controllers\Api\Admin\ProductPage\ProductPageSlideController;
use App\Http\Controllers\Api\Admin\servicepage\ServiceHowItWorksSectionController;
use App\Http\Controllers\Api\Admin\PolicySectionController;
use App\Http\Controllers\Api\Admin\QuoteFormFieldController;
use App\Http\Controllers\Api\Admin\SectionLabelController;
use App\Http\Controllers\Api\Admin\ScheduleFormSubmissionController;
use App\Http\Controllers\Api\Admin\AdminOrderController;
use App\Http\Controllers\Api\Admin\AdminReviewController;
use App\Http\Controllers\Api\Admin\AdminReturnController;
use App\Http\Controllers\Api\Admin\AdminQuotationController;
use App\Http\Controllers\Api\Admin\AdminTicketController;
use App\Http\Controllers\Api\Admin\AdminWalletController;
use App\Http\Controllers\Api\Admin\AdminFinancialTransactionController;
use App\Http\Controllers\Api\Admin\AdminSubscriptionPlanController;
use App\Http\Controllers\Api\Admin\MarketingCampaignController;


/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

/*
|--------------------------------------------------------------------------
| API Version 1 Routes
|--------------------------------------------------------------------------
|
| All API endpoints are versioned under /api/v1/ prefix to allow for
| future API versions while maintaining backward compatibility.
|
*/

Route::prefix('v1')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Public Authentication Routes
    |--------------------------------------------------------------------------
    |
    | These routes are publicly accessible and do not require authentication.
    | They handle user registration and login functionality.
    |
    */

    // User Registration Endpoint
    // POST /api/v1/register
    // Accepts: name, email, password, password_confirmation
    // Returns: User object with authentication token
    Route::post('/register', [RegisterController::class, 'register'])
        ->name('api.v1.register');

    // User Login Endpoint
    // POST /api/v1/login
    // Accepts: email, password
    // Returns: User object with authentication token
    Route::post('/login', [LoginController::class, 'login'])
        ->name('api.v1.login');

    Route::post('/auth/login', [LoginController::class, 'login'])
        ->name('api.v1.auth.login');

    Route::post('/forgot-password', [LoginController::class, 'forgotPassword'])
        ->name('api.v1.forgot-password');

    // Get Last Content Update Time (Public)
    // GET /api/v1/content-last-updated
    // Returns: Last content update timestamp for polling
    Route::get('/content-last-updated', [\App\Http\Controllers\Api\ContentUpdateController::class, 'getLastUpdateTime'])
        ->name('api.v1.content-last-updated');

    Route::get('/public/home-payload', [\App\Http\Controllers\Api\PublicHomePayloadController::class, 'show'])
        ->name('api.v1.public.home-payload.show');

    Route::post('/internal/notifications/user-registered', [\App\Http\Controllers\Api\InternalNotificationController::class, 'userRegistered'])
        ->name('api.v1.internal.notifications.user-registered');

    Route::post('/internal/notifications/referral-registered', [\App\Http\Controllers\Api\InternalNotificationController::class, 'referralRegistered'])
        ->name('api.v1.internal.notifications.referral-registered');

    Route::get('/internal/check-email', [\App\Http\Controllers\Api\InternalNotificationController::class, 'checkEmail'])
        ->name('api.v1.internal.check-email');

    Route::get('/internal/fetch-user-credentials', [\App\Http\Controllers\Api\InternalNotificationController::class, 'fetchUserCredentials'])
        ->name('api.v1.internal.fetch-user-credentials');

    Route::get('/internal/verify-user-id', [\App\Http\Controllers\Api\InternalNotificationController::class, 'verifyUserId'])
        ->name('api.v1.internal.verify-user-id');


    // Get Home Page Content (Public)
    // GET /api/v1/home-page
    // Returns: Current home page configuration (public access for viewing)
    Route::get('/home-page', [HomePageController::class, 'index'])
        ->name('api.v1.home-page.index');

    // View Specific Home Page by ID (Public)
    // GET /api/v1/home-page/{id}
    // Returns: Specific home page configuration by ID
    Route::get('/home-page/{id}', [HomePageController::class, 'show'])
        ->name('api.v1.home-page.show');

    // Get Service Page Content (Public)
    // GET /api/v1/service-page
    // Returns: Current service page configuration (public access for viewing)
    Route::get('/service-page', [ServicePageController::class, 'index'])
        ->name('api.v1.service-page.index');

    // View Specific Service Page by ID (Public)
    // GET /api/v1/service-page/{id}
    // Returns: Specific service page configuration by ID
    Route::get('/service-page/{id}', [ServicePageController::class, 'show'])
        ->name('api.v1.service-page.show');

    // Get Features Sections Content (Public)
    // GET /api/v1/features-sections
    // Returns: Current features sections configuration (public access for viewing)
    Route::get('/features-sections', [FeaturesSectionController::class, 'index'])
        ->name('api.v1.features-sections.index');

    // View Specific Features Section by ID (Public)
    // GET /api/v1/features-sections/{id}
    // Returns: Specific features section configuration by ID
    Route::get('/features-sections/{id}', [FeaturesSectionController::class, 'show'])
        ->name('api.v1.features-sections.show');

    // Get Analytics Sections Content (Public)
    // GET /api/v1/analytics-sections
    // Returns: Current analytics sections configuration (public access for viewing)
    Route::get('/analytics-sections', [AnalyticsSectionController::class, 'index'])
        ->name('api.v1.analytics-sections.index');

    // View Specific Analytics Section by ID (Public)
    // GET /api/v1/analytics-sections/{id}
    // Returns: Specific analytics section configuration by ID
    Route::get('/analytics-sections/{id}', [AnalyticsSectionController::class, 'show'])
        ->name('api.v1.analytics-sections.show');

    // Get Chart Sections Content (Public)
    // GET /api/v1/chart-sections
    // Returns: Current chart sections configuration (public access for viewing)
    Route::get('/chart-sections', [ChartSectionController::class, 'index'])
        ->name('api.v1.chart-sections.index');

    // View Specific Chart Section by ID (Public)
    // GET /api/v1/chart-sections/{id}
    // Returns: Specific chart section configuration by ID
    Route::get('/chart-sections/{id}', [ChartSectionController::class, 'show'])
        ->name('api.v1.chart-sections.show');

    // Get Tab Sections Content (Public)
    // GET /api/v1/tab-sections
    // Returns: Current tab sections configuration (public access for viewing)
    Route::get('/tab-sections', [TabSectionController::class, 'index'])
        ->name('api.v1.tab-sections.index');

    // View Specific Tab Section by ID (Public)
    // GET /api/v1/tab-sections/{id}
    // Returns: Specific tab section configuration by ID
    Route::get('/tab-sections/{id}', [TabSectionController::class, 'show'])
        ->name('api.v1.tab-sections.show');

    // Get Showcase Sections Content (Public)
    // GET /api/v1/showcase-sections
    // Returns: Current showcase sections configuration (public access for viewing)
    Route::get('/showcase-sections', [ShowcaseSectionController::class, 'index'])
        ->name('api.v1.showcase-sections.index');

    // View Specific Showcase Section by ID (Public)
    // GET /api/v1/showcase-sections/{id}
    // Returns: Specific showcase section configuration by ID
    Route::get('/showcase-sections/{id}', [ShowcaseSectionController::class, 'show'])
        ->name('api.v1.showcase-sections.show');

    // Get About Section Content (Public)
    // GET /api/v1/about-section
    // Returns: Current about section configuration (public access for viewing)
    Route::get('/about-section', [AboutSectionController::class, 'index'])
        ->name('api.v1.about-section.index');

    // Get Service Section Content (Public)
    // GET /api/v1/service-section
    // Returns: Current service section configuration (public access for viewing)
    Route::get('/service-section', [ServiceSectionController::class, 'index'])
        ->name('api.v1.service-section.index');

    // Get All Service Cards (Public)
    // GET /api/v1/service-cards
    // Returns: All service cards ordered by order field (public access for viewing)
    Route::get('/service-cards', [ServiceCardController::class, 'index'])
        ->name('api.v1.service-cards.index');

    // Get Service Card by ID (Public)
    // GET /api/v1/service-cards/{id}
    // Returns: Specific service card configuration by ID
    Route::get('/service-cards/{id}', [ServiceCardController::class, 'show'])
        ->name('api.v1.service-cards.show');

    // Get What We Create Section Content (Public)
    Route::get('/what-we-create-section', [WhatWeCreateSectionController::class, 'index'])
        ->name('api.v1.what-we-create-section.index');

    // Get All What We Create Tabs (Public)
    Route::get('/what-we-create-tabs', [WhatWeCreateTabController::class, 'index'])
        ->name('api.v1.what-we-create-tabs.index');

    // Get What We Create Tab by ID (Public)
    Route::get('/what-we-create-tabs/{id}', [WhatWeCreateTabController::class, 'show'])
        ->name('api.v1.what-we-create-tabs.show');

    // Get All Category Tabs (Public)
    Route::get('/category-tabs', [CategoryTabController::class, 'index'])
        ->name('api.v1.category-tabs.index');

    // Get Category Tab by ID (Public)
    Route::get('/category-tabs/{id}', [CategoryTabController::class, 'show'])
        ->name('api.v1.category-tabs.show');

    // Get Why Choose Us Section Content (Public)
    Route::get('/why-choose-us-section', [WhyChooseUsSectionController::class, 'index'])
        ->name('api.v1.why-choose-us-section.index');

    // Get All Why Choose Us Tabs (Public)
    Route::get('/why-choose-us-tabs', [WhyChooseUsTabController::class, 'index'])
        ->name('api.v1.why-choose-us-tabs.index');

    // Get Why Choose Us Tab by ID (Public)
    Route::get('/why-choose-us-tabs/{id}', [WhyChooseUsTabController::class, 'show'])
        ->name('api.v1.why-choose-us-tabs.show');

    // Get Our Facts Section Content (Public)
    Route::get('/our-facts-section', [OurFactsSectionController::class, 'index'])
        ->name('api.v1.our-facts-section.index');

    // Get All Our Facts (Public)
    Route::get('/our-facts', [OurFactController::class, 'index'])
        ->name('api.v1.our-facts.index');

    // Get Our Fact by ID (Public)
    Route::get('/our-facts/{id}', [OurFactController::class, 'show'])
        ->name('api.v1.our-facts.show');

    // Get Our Promise Content (Public)
    Route::get('/our-promise', [OurPromiseController::class, 'index'])
        ->name('api.v1.our-promise.index');

    // Get All Process Steps (Public)
    Route::get('/process-steps', [ProcessStepController::class, 'index'])
        ->name('api.v1.process-steps.index');

    // Get Process Step by ID (Public)
    Route::get('/process-steps/{id}', [ProcessStepController::class, 'show'])
        ->name('api.v1.process-steps.show');

    // Get Reviews Section Content (Public)
    Route::get('/reviews-section', [ReviewsSectionController::class, 'index'])
        ->name('api.v1.reviews-section.index');

    // Get All Reviews (Public)
    Route::get('/reviews', [ReviewController::class, 'index'])
        ->name('api.v1.reviews.index');

    // Get Review by ID (Public)
    Route::get('/reviews/{id}', [ReviewController::class, 'show'])
        ->name('api.v1.reviews.show');

    // Get Portfolio Section Content (Public)
    Route::get('/portfolio-section', [PortfolioSectionController::class, 'index'])
        ->name('api.v1.portfolio-section.index');

    // Get All Portfolio Items (Public)
    Route::get('/portfolio-items', [PortfolioItemController::class, 'index'])
        ->name('api.v1.portfolio-items.index');

    // Get Portfolio Item by ID (Public)
    Route::get('/portfolio-items/{id}', [PortfolioItemController::class, 'show'])
        ->name('api.v1.portfolio-items.show');

    // Get Quote Section Content (Public)
    Route::get('/quote-section', [QuoteSectionController::class, 'index'])
        ->name('api.v1.quote-section.index');

    // Get Quote Form Fields (Public - for dynamic form rendering)
    Route::get('/quote-form-fields', [QuoteFormFieldController::class, 'publicIndex'])
        ->name('api.v1.quote-form-fields.public');

    // Subscription Plans & Membership Benefits (Public)
    Route::get('/admin/subscription-plans/public', [\App\Http\Controllers\Api\Admin\AdminSubscriptionPlanController::class, 'publicIndex']);
    Route::get('/subscription-plans', [\App\Http\Controllers\Api\Admin\AdminSubscriptionPlanController::class, 'publicIndex']);
    Route::get('/ecommerce/subscription-plans', [\App\Http\Controllers\Api\Admin\AdminSubscriptionPlanController::class, 'publicIndex']);
    Route::get('/admin/membership-benefits/public', [\App\Http\Controllers\Api\Admin\AdminMembershipBenefitController::class, 'publicIndex']);
    Route::get('/membership-benefits', [\App\Http\Controllers\Api\Admin\AdminMembershipBenefitController::class, 'publicIndex']);
    Route::get('/ecommerce/membership-benefits', [\App\Http\Controllers\Api\Admin\AdminMembershipBenefitController::class, 'publicIndex']);

    // Get Hero Section Content (Public)
    Route::get('/hero-section', [HeroSectionController::class, 'index'])
        ->name('api.v1.hero-section.index');

    // Get Hero Section by ID (Public)
    Route::get('/hero-section/{id}', [HeroSectionController::class, 'show'])
        ->name('api.v1.hero-section.show');

    // Get About Founder Section Content (Public)
    Route::get('/about-founder-section', [AboutFounderSectionController::class, 'index'])
        ->name('api.v1.about-founder-section.index');

    // Get About Founder Section by ID (Public)
    Route::get('/about-founder-section/{id}', [AboutFounderSectionController::class, 'show'])
        ->name('api.v1.about-founder-section.show');

    // Get About Company Section Content (Public)
    Route::get('/about-company-section', [AboutCompanySectionController::class, 'index'])
        ->name('api.v1.about-company-section.index');

    // Get About Company Section by ID (Public)
    Route::get('/about-company-section/{id}', [AboutCompanySectionController::class, 'show'])
        ->name('api.v1.about-company-section.show');

    // Get Mission and Vision Section Content (Public)
    Route::get('/mission-vision-section', [MissionVisionSectionController::class, 'index'])
        ->name('api.v1.mission-vision-section.index');

    // Get Mission and Vision Section by ID (Public)
    Route::get('/mission-vision-section/{id}', [MissionVisionSectionController::class, 'show'])
        ->name('api.v1.mission-vision-section.show');

    // Get Core Values Section (section title & description) (Public)
    Route::get('/core-values-section', [CoreValuesSectionController::class, 'index'])
        ->name('api.v1.core-values-section.index');

    // Get Core Values Section by ID (Public)
    Route::get('/core-values-section/{id}', [CoreValuesSectionController::class, 'show'])
        ->name('api.v1.core-values-section.show');

    // Get All Core Values (Public)
    Route::get('/core-values', [CoreValueController::class, 'index'])
        ->name('api.v1.core-values.index');

    // Get Core Value by ID (Public)
    Route::get('/core-values/{id}', [CoreValueController::class, 'show'])
        ->name('api.v1.core-values.show');

    // Get FAQ Hero Section Content (Public)
    Route::get('/faq-hero-section', [FAQHeroSectionController::class, 'index'])
        ->name('api.v1.faq-hero-section.index');

    // Get FAQ Hero Section by ID (Public)
    Route::get('/faq-hero-section/{id}', [FAQHeroSectionController::class, 'show'])
        ->name('api.v1.faq-hero-section.show');

    // Get FAQ Intro Paragraph Content (Public)
    Route::get('/faq-intro-paragraph', [FAQIntroParagraphController::class, 'index'])
        ->name('api.v1.faq-intro-paragraph.index');

    // Get FAQ Intro Paragraph by ID (Public)
    Route::get('/faq-intro-paragraph/{id}', [FAQIntroParagraphController::class, 'show'])
        ->name('api.v1.faq-intro-paragraph.show');

    // Get All FAQ Categories (Public)
    Route::get('/faq-categories', [FAQCategoryController::class, 'index'])
        ->name('api.v1.faq-categories.index');

    // Get Career Page Hero Section Content (Public)
    Route::get('/career-page-hero-section', [CareerPageHeroSectionController::class, 'index'])
        ->name('api.v1.career-page-hero-section.index');

    // Get Career Page Hero Section by ID (Public)
    Route::get('/career-page-hero-section/{id}', [CareerPageHeroSectionController::class, 'show'])
        ->name('api.v1.career-page-hero-section.show');

    // Get Career Cards (Public)
    Route::get('/career-cards', [CareerCardController::class, 'index'])
        ->name('api.v1.career-cards.index');

    // Get Career Card by ID (Public)
    Route::get('/career-cards/{id}', [CareerCardController::class, 'show'])
        ->name('api.v1.career-cards.show');

    // Get Job Sections (Public)
    Route::get('/job-sections', [JobSectionController::class, 'index'])
        ->name('api.v1.job-sections.index');

    // Get Job Section by ID (Public)
    Route::get('/job-sections/{id}', [JobSectionController::class, 'show'])
        ->name('api.v1.job-sections.show');

    // Get Career CTA Sections (Public)
    Route::get('/career-cta-sections', [CareerCtaSectionController::class, 'index'])
        ->name('api.v1.career-cta-sections.index');

    // Get Career CTA Section by ID (Public)
    Route::get('/career-cta-sections/{id}', [CareerCtaSectionController::class, 'show'])
        ->name('api.v1.career-cta-sections.show');

    // Get Career Procedures (Public)
    Route::get('/career-procedures', [CareerProcedureController::class, 'index'])
        ->name('api.v1.career-procedures.index');

    // Get Career Procedure by ID (Public)
    Route::get('/career-procedures/{id}', [CareerProcedureController::class, 'show'])
        ->name('api.v1.career-procedures.show');

    // Get FAQ Sections (Public)
    Route::get('/faq-sections', [FaqSectionController::class, 'index'])
        ->name('api.v1.faq-sections.index');

    // Get FAQ Section by ID (Public)
    Route::get('/faq-sections/{id}', [FaqSectionController::class, 'show'])
        ->name('api.v1.faq-sections.show');

    // Get Support Sections (Public)
    Route::get('/support-sections', [SupportSectionController::class, 'index'])
        ->name('api.v1.support-sections.index');

    // Get Specific Support Section (Public)
    Route::get('/support-sections/{id}', [SupportSectionController::class, 'show'])
        ->name('api.v1.support-sections.show');

    // Test endpoint for debugging job section creation
    Route::post('/job-sections/test', function (Request $request) {
        try {
            \Log::info('Test endpoint hit with data:', $request->all());

            $user = $request->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'No authenticated user',
                    'debug' => 'Please authenticate first'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'message' => 'Test endpoint working',
                'debug' => [
                    'user_authenticated' => true,
                    'user_role' => $user->role,
                    'has_admin_access' => $user->hasAdminAccess(),
                    'request_data' => $request->all()
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Test endpoint error:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Test endpoint error',
                'error' => $e->getMessage()
            ], 500);
        }
    })->middleware('auth:sanctum');

    // Test endpoint to check database columns
    Route::get('/job-sections/check-columns', function () {
        try {
            $columns = \Schema::getColumnListing('job_sections');
            return response()->json([
                'success' => true,
                'columns' => $columns,
                'has_employment_type' => in_array('employment_type', $columns),
                'has_experience_required' => in_array('experience_required', $columns),
                'has_company_name' => in_array('company_name', $columns)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    });

    // Get FAQ Category by ID (Public)
    Route::get('/faq-categories/{id}', [FAQCategoryController::class, 'show'])
        ->name('api.v1.faq-categories.show');

    // Get All FAQ Items (Public)
    Route::get('/faq-items', [FAQItemController::class, 'index'])
        ->name('api.v1.faq-items.index');

    // Get FAQ Item by ID (Public)
    Route::get('/faq-items/{id}', [FAQItemController::class, 'show'])
        ->name('api.v1.faq-items.show');

    // Get FAQ Ask Question Form Section (heading & description) (Public)
    Route::get('/faq-ask-question-section', [FaqAskQuestionSectionController::class, 'index'])
        ->name('api.v1.faq-ask-question-section.index');

    // Get FAQ Ask Question Section by ID (Public)
    Route::get('/faq-ask-question-section/{id}', [FaqAskQuestionSectionController::class, 'show'])
        ->name('api.v1.faq-ask-question-section.show');

    // Get All User Submitted Questions (Public - for admin viewing)
    Route::get('/user-submitted-questions', [UserSubmittedQuestionController::class, 'index'])
        ->name('api.v1.user-submitted-questions.index');

    // Get User Submitted Question by ID (Public - for admin viewing)
    Route::get('/user-submitted-questions/{id}', [UserSubmittedQuestionController::class, 'show'])
        ->name('api.v1.user-submitted-questions.show');

    // Submit User Question (Public - for website form)
    Route::post('/user-submitted-questions', [UserSubmittedQuestionController::class, 'store'])
        ->name('api.v1.user-submitted-questions.store');

    // Submit Contact Form (Public)
    Route::post('/contact-form', [ContactFormSubmissionController::class, 'store'])
        ->name('api.v1.contact-form.store');

    // Submit Career Support Form (Public)
    Route::post('/career-support-form', [CareerSupportFormSubmissionController::class, 'store'])
        ->name('api.v1.career-support-form.store');

    // Submit Quote Form (Public)
    Route::post('/quote-form', [QuoteFormSubmissionController::class, 'store'])
        ->name('api.v1.quote-form.store');

    // Submit Schedule Form (Public)
    Route::post('/schedule-form', [ScheduleFormSubmissionController::class, 'store'])
        ->name('api.v1.schedule-form.store');

    // Submit Product Report (Public)
    Route::post('/product-reports/submit', [ProductReportController::class, 'submit'])
        ->name('api.v1.product-reports.submit');
    Route::post('/product-reports', [ProductReportController::class, 'submit'])
        ->name('api.v1.product-reports.store');

    // Customer Reported Products APIs
    Route::get('/customer/reported-products', [\App\Http\Controllers\Api\Customer\ReportedProductController::class, 'index']);
    Route::get('/customer/reported-products/{id}', [\App\Http\Controllers\Api\Customer\ReportedProductController::class, 'show']);
    Route::post('/customer/reported-products/{id}/reply', [\App\Http\Controllers\Api\Customer\ReportedProductController::class, 'reply']);

    // Admin Reported Products APIs
    Route::get('/admin/product-reports', [ProductReportController::class, 'index']);
    Route::get('/admin/product-reports/{id}', [ProductReportController::class, 'show']);
    Route::put('/admin/product-reports/{id}', [ProductReportController::class, 'update']);
    Route::post('/admin/product-reports/{id}/reply', [ProductReportController::class, 'reply']);
    Route::delete('/admin/product-reports/{id}', [ProductReportController::class, 'destroy']);


    // Get Contact Page Hero Sections (Public)
    Route::get('/contact-page-hero-sections', [ContactPageHeroSectionController::class, 'index'])
        ->name('api.v1.contact-page-hero-sections.index');

    // Get Specific Contact Page Hero Section (Public)
    Route::get('/contact-page-hero-sections/{id}', [ContactPageHeroSectionController::class, 'show'])
        ->name('api.v1.contact-page-hero-sections.show');

    // Get Contact Page Cards (Public)
    Route::get('/contact-page-cards', [ContactCardController::class, 'index'])
        ->name('api.v1.contact-page-cards.index');

    // Get Specific Contact Page Card (Public)
    Route::get('/contact-page-cards/{id}', [ContactCardController::class, 'show'])
        ->name('api.v1.contact-page-cards.show');

    // Get Hours of Operation (Public)
    Route::get('/hours-of-operation', [HoursOfOperationController::class, 'index'])
        ->name('api.v1.hours-of-operation.index');

    // Get Specific Hours of Operation (Public)
    Route::get('/hours-of-operation/{id}', [HoursOfOperationController::class, 'show'])
        ->name('api.v1.hours-of-operation.show');

    // Get Social Media Section and Links (Public)
    Route::get('/social-media', [SocialLinkController::class, 'index'])
        ->name('api.v1.social-media.index');

    // Get Social Media Section (Public)
    Route::get('/social-media/section', [SocialLinkController::class, 'getSection'])
        ->name('api.v1.social-media.section');

    // Get Specific Social Link (Public)
    Route::get('/social-links/{id}', [SocialLinkController::class, 'show'])
        ->name('api.v1.social-links.show');

    // Get All Team Members (Public)
    Route::get('/team-members', [TeamMemberController::class, 'index'])
        ->name('api.v1.team-members.index');

    // Get Specific Team Member (Public)
    Route::get('/team-members/{id}', [TeamMemberController::class, 'show'])
        ->name('api.v1.team-members.show');

    // Get Footer Content (Public)
    Route::get('/footer', [FooterController::class, 'index'])
        ->name('api.v1.footer.index');

    // Get Site Settings (Public)
    Route::get('/site-settings', [SiteSettingController::class, 'index'])
        ->name('api.v1.site-settings.index');

    // Get All Page SEO Settings (Public)
    Route::get('/page-seo-settings', [PageSeoSettingController::class, 'index'])
        ->name('api.v1.page-seo-settings.index');

    // Get Page SEO Settings by Slug (Public)
    Route::get('/page-seo-settings/{pageSlug}', [PageSeoSettingController::class, 'show'])
        ->name('api.v1.page-seo-settings.show');

    // Get Product Page Hero Section (Public)
    Route::get('/product-page-hero-section', [ProductPageHeroSectionController::class, 'index'])
        ->name('api.v1.product-page-hero-section.index');

    // Get All Product Tabs with Items (Public)
    Route::get('/product-tabs', [ProductTabController::class, 'index'])
        ->name('api.v1.product-tabs.index');

    // Get Product Page Slides (Public)
    Route::get('/product-page-slide', [ProductPageSlideController::class, 'index'])
        ->name('api.v1.product-page-slide.index');

    // Get Specific Product Page Slide (Public)
    Route::get('/product-page-slide/{id}', [ProductPageSlideController::class, 'show'])
        ->name('api.v1.product-page-slide.show');

    // Get Service How It Works Section (Public)
    Route::get('/service-how-it-works-section', [ServiceHowItWorksSectionController::class, 'index'])
        ->name('api.v1.service-how-it-works-section.index');

    // Get All Policy Sections (Public)
    Route::get('/policy-sections', [PolicySectionController::class, 'index'])
        ->name('api.v1.policy-sections.index');

    // Get Policy Section by ID (Public)
    Route::get('/policy-sections/{id}', [PolicySectionController::class, 'show'])
        ->name('api.v1.policy-sections.show');

    // Get Policy Section by Type (Public)
    Route::get('/policy-sections/type/{type}', [PolicySectionController::class, 'showByType'])
        ->name('api.v1.policy-sections.show-by-type');

    // Get All Section Labels (Public)
    Route::get('/section-labels', [SectionLabelController::class, 'index'])
        ->name('api.v1.section-labels.index');

    // Get Section Labels for a Page (Public)
    Route::get('/section-labels/{pageSlug}', [SectionLabelController::class, 'show'])
        ->name('api.v1.section-labels.show');

    // Get Membership Page Content (Public)
    Route::get('/membership-page', [MembershipPageController::class, 'index'])
        ->name('api.v1.membership-page.index');

    // Get Gift Card Page Content (Public)
    Route::get('/gift-card-page', [GiftCardPageController::class, 'index'])
        ->name('api.v1.gift-card-page.index');

    // Get Page Popups (Public)
    Route::get('/popups/page/{pageKey}', [\App\Http\Controllers\Api\PopupController::class, 'byPage'])
        ->name('api.v1.popups.byPage');

    // Get Active Popup Template (Public)
    Route::get('/popups/{eventKey}', [\App\Http\Controllers\Api\PopupController::class, 'show'])
        ->name('api.v1.popups.show');

    /*
     |--------------------------------------------------------------------------
     | Protected Authentication Routes
    |--------------------------------------------------------------------------
    |
    | These routes require authentication via Laravel Sanctum.
    | Users must include a valid Bearer token in the Authorization header.
    |
    */

    Route::middleware('central.auth')->group(function () {

        // Get Authenticated User
        // GET /api/v1/user
        // Returns: Currently authenticated user information
        Route::get('/user', function (Request $request) {
            $user = $request->user();
            $token = $request->bearerToken();
            $centralUrl = rtrim(config('services.central_auth.url'), '/');

            $walletBalance = 0.00;
            $loyaltyPoints = 0;

            try {
                if ($token) {
                    $walletRes = \Illuminate\Support\Facades\Http::acceptJson()
                        ->withToken($token)
                        ->timeout(2)
                        ->get($centralUrl . '/user/wallet');
                    if ($walletRes->successful()) {
                        $walletBalance = (float) $walletRes->json('data.balance');
                    }

                    $loyaltyRes = \Illuminate\Support\Facades\Http::acceptJson()
                        ->withToken($token)
                        ->timeout(2)
                        ->get($centralUrl . '/user/loyalty');
                    if ($loyaltyRes->successful()) {
                        $loyaltyPoints = (int) $loyaltyRes->json('data.points');
                    }
                }
            } catch (\Throwable $e) {
                \Log::warning('Failed to fetch central user ledger details: ' . $e->getMessage());
            }

            $userData = $user->toArray();
            $userData['wallet_balance'] = $walletBalance;
            $userData['loyalty_points'] = $loyaltyPoints;

            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $userData,
                ],
            ]);
        })->name('api.v1.user');

        // Logout (Revoke Current Token)
        // POST /api/v1/logout
        // Revokes the current access token
        Route::post('/logout', function (Request $request) {
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Successfully logged out',
            ]);
        })->name('api.v1.logout');

        // User management routes handled by UserController below

        // Get All Users (Admin Only)
        // GET /api/v1/users
        // Returns: Paginated list of users with roles and permissions
        Route::get('/users', [UserController::class, 'index'])
            ->name('api.v1.users.index');

        // Get Specific User (Admin Only)
        // GET /api/v1/users/{id}
        // Returns: User object with roles and permissions
        Route::get('/users/{id}', [UserController::class, 'show'])
            ->name('api.v1.users.show');

        // Create New User with Roles (Admin Only)
        // POST /api/v1/users
        // Returns: Created user object
        Route::post('/users', [UserController::class, 'store'])
            ->name('api.v1.users.store');

        // Update User (Admin Only)
        // PUT/PATCH /api/v1/users/{id}
        // Returns: Updated user object
        Route::put('/users/{id}', [UserController::class, 'update'])
            ->name('api.v1.users.update');
        Route::patch('/users/{id}', [UserController::class, 'update'])
            ->name('api.v1.users.update.patch');

        // Assign Roles to User (Admin Only)
        // POST /api/v1/users/{id}/assign-roles
        // Returns: Updated user object with new roles
        Route::post('/users/{id}/assign-roles', [UserController::class, 'assignRoles'])
            ->name('api.v1.users.assign-roles');

        // Remove Roles from User (Admin Only)
        // POST /api/v1/users/{id}/remove-roles
        // Returns: Updated user object with roles removed
        Route::post('/users/{id}/remove-roles', [UserController::class, 'removeRoles'])
            ->name('api.v1.users.remove-roles');

        // Delete User (Admin Only)
        // DELETE /api/v1/users/{id}
        // Returns: Success message
        Route::delete('/users/{id}', [UserController::class, 'destroy'])
            ->name('api.v1.users.destroy');

        /*
        |--------------------------------------------------------------------------
        | Role & Permission Management Routes (Admin Only)
        |--------------------------------------------------------------------------
        */

        // Roles CRUD
        Route::get('/roles', [RolePermissionController::class, 'indexRoles'])
            ->name('api.v1.roles.index');
        Route::post('/roles', [RolePermissionController::class, 'storeRole'])
            ->name('api.v1.roles.store');
        Route::put('/roles/{id}', [RolePermissionController::class, 'updateRole'])
            ->name('api.v1.roles.update');
        Route::delete('/roles/{id}', [RolePermissionController::class, 'destroyRole'])
            ->name('api.v1.roles.destroy');

        // Permissions
        Route::get('/permissions', [RolePermissionController::class, 'indexPermissions'])
            ->name('api.v1.permissions.index');
        Route::post('/permissions', [RolePermissionController::class, 'storePermission'])
            ->name('api.v1.permissions.store');

        /*
        |--------------------------------------------------------------------------
        | Admin E-Commerce Routes
        |--------------------------------------------------------------------------
        */

        // Orders
        Route::get('/admin/orders', [AdminOrderController::class, 'index']);
        Route::get('/admin/orders/stats', [AdminOrderController::class, 'stats']);
        Route::get('/admin/orders/{id}', [AdminOrderController::class, 'show']);
        Route::patch('/admin/orders/{id}/status', [AdminOrderController::class, 'updateStatus']);
        Route::delete('/admin/orders/{id}', [AdminOrderController::class, 'destroy']);

        // Order Proofs
        Route::get('/admin/order-proofs/stats', [\App\Http\Controllers\Api\Admin\OrderProofController::class, 'stats']);
        Route::get('/admin/order-proofs', [\App\Http\Controllers\Api\Admin\OrderProofController::class, 'index']);
        Route::post('/admin/order-proofs', [\App\Http\Controllers\Api\Admin\OrderProofController::class, 'store']);
        Route::get('/admin/order-proofs/{id}', [\App\Http\Controllers\Api\Admin\OrderProofController::class, 'show']);
        Route::put('/admin/order-proofs/{id}', [\App\Http\Controllers\Api\Admin\OrderProofController::class, 'update']);
        Route::post('/admin/order-proofs/{id}/send', [\App\Http\Controllers\Api\Admin\OrderProofController::class, 'send']);
        Route::put('/admin/order-proofs/{id}/status', [\App\Http\Controllers\Api\Admin\OrderProofController::class, 'updateStatus']);
        Route::get('/admin/order-proofs/{id}/comments', [\App\Http\Controllers\Api\Admin\OrderProofController::class, 'comments']);
        Route::post('/admin/order-proofs/{id}/comments', [\App\Http\Controllers\Api\Admin\OrderProofController::class, 'addComment']);
        Route::delete('/admin/order-proofs/{id}', [\App\Http\Controllers\Api\Admin\OrderProofController::class, 'destroy']);

        // Order Verifications (Customer & Admin)
        Route::get('/order-verifications', [\App\Http\Controllers\Api\Customer\OrderVerificationController::class, 'index']);
        Route::get('/order-verifications/{id}', [\App\Http\Controllers\Api\Customer\OrderVerificationController::class, 'show']);
        Route::post('/order-verifications/{id}/documents', [\App\Http\Controllers\Api\Customer\OrderVerificationController::class, 'uploadDocuments']);
        Route::post('/order-verifications/{id}/notes', [\App\Http\Controllers\Api\Customer\OrderVerificationController::class, 'addNote']);

        Route::get('/admin/order-verifications', [\App\Http\Controllers\Api\Admin\OrderVerificationController::class, 'index']);
        Route::post('/admin/order-verifications', [\App\Http\Controllers\Api\Admin\OrderVerificationController::class, 'store']);
        Route::get('/admin/order-verifications/{id}', [\App\Http\Controllers\Api\Admin\OrderVerificationController::class, 'show']);
        Route::put('/admin/order-verifications/{id}/status', [\App\Http\Controllers\Api\Admin\OrderVerificationController::class, 'updateStatus']);
        Route::post('/admin/order-verifications/{id}/approve', [\App\Http\Controllers\Api\Admin\OrderVerificationController::class, 'approve']);
        Route::post('/admin/order-verifications/{id}/decline', [\App\Http\Controllers\Api\Admin\OrderVerificationController::class, 'decline']);
        Route::post('/admin/order-verifications/{id}/request-additional', [\App\Http\Controllers\Api\Admin\OrderVerificationController::class, 'requestAdditional']);
        Route::post('/admin/order-verifications/{id}/notes', [\App\Http\Controllers\Api\Admin\OrderVerificationController::class, 'addNote']);
        Route::delete('/admin/order-verifications/{id}', [\App\Http\Controllers\Api\Admin\OrderVerificationController::class, 'destroy']);

        // Customer Verifications
        Route::get('/admin/customer-verifications/stats', [\App\Http\Controllers\Api\Admin\CustomerVerificationController::class, 'stats']);
        Route::get('/admin/customer-verifications', [\App\Http\Controllers\Api\Admin\CustomerVerificationController::class, 'index']);
        Route::get('/admin/customer-verifications/{id}', [\App\Http\Controllers\Api\Admin\CustomerVerificationController::class, 'show']);
        Route::put('/admin/customer-verifications/{id}/approve', [\App\Http\Controllers\Api\Admin\CustomerVerificationController::class, 'approve']);
        Route::put('/admin/customer-verifications/{id}/reject', [\App\Http\Controllers\Api\Admin\CustomerVerificationController::class, 'reject']);
        Route::post('/admin/customer-verifications/{id}/notes', [\App\Http\Controllers\Api\Admin\CustomerVerificationController::class, 'addNote']);

        // Quotations
        Route::get('/admin/quotations', [AdminQuotationController::class, 'index']);
        Route::get('/admin/quotations/{quotation}', [AdminQuotationController::class, 'show']);
        Route::patch('/admin/quotations/{quotation}/status', [AdminQuotationController::class, 'updateStatus']);
        Route::post('/admin/quotations/{quotation}/send-quote', [AdminQuotationController::class, 'sendQuote']);

        // Subscription Plans
        Route::apiResource('/admin/subscription-plans', AdminSubscriptionPlanController::class);
        Route::apiResource('/admin/membership-benefits', \App\Http\Controllers\Api\Admin\AdminMembershipBenefitController::class);

        // Customers (uses UserController with role filter)
        Route::get('/admin/customers', [UserController::class, 'customers']);
        Route::get('/admin/business-users', [UserController::class, 'businessUsers']);
        Route::get('/admin/customers/stats', [UserController::class, 'customerStats']);
        Route::post('/admin/customers', [UserController::class, 'storeCustomer']);
        Route::patch('/admin/customers/{id}/status', [UserController::class, 'updateCustomerStatus']);
        Route::post('/admin/customers/{id}/verify', [UserController::class, 'verifyCustomer']);
        
        // User Profile & Tabs API
        Route::get('/admin/users/{id}/profile-details', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'getProfileDetails']);
        Route::put('/admin/users/{id}/profile-details', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'updateProfile']);
        Route::post('/admin/users/{id}/profile-details', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'updateProfile']);
        Route::get('/admin/users/{id}/order-history', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'getOrderHistory']);
        Route::get('/admin/users/{id}/orders', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'getOrderHistory']);
        Route::get('/admin/users/{id}/login-history', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'getLoginHistory']);
        Route::get('/admin/users/{id}/admin-changes', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'getAdminChanges']);
        Route::get('/admin/users/{id}/messages', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'getMessages']);
        Route::post('/admin/users/{id}/messages', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'sendMessage']);
        Route::get('/admin/users/{id}/financial-transactions', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'getFinancialTransactions']);
        Route::get('/admin/users/{id}/affiliate-history', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'getAffiliateHistory']);
        Route::get('/admin/users/{id}/membership-history', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'getMembershipHistory']);
        Route::get('/admin/users/{id}/loyalty-program', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'getLoyaltyProgram']);
        Route::post('/admin/users/{id}/loyalty-adjust', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'adjustLoyaltyPoints']);
        Route::get('/admin/users/{id}/gift-card-history', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'getGiftCardHistory']);
        Route::get('/admin/users/{id}/support-tickets', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'getSupportTickets']);
        Route::post('/admin/users/{id}/support-tickets/{ticketId}/reply', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'replyTicket']);
        Route::get('/admin/users/{id}/verification-history', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'getVerificationHistory']);
        Route::get('/admin/users/{id}/order-disputes', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'getOrderDisputes']);
        Route::get('/admin/users/{id}/reported-products', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'getReportedProducts']);
        Route::get('/admin/users/{id}/coupons', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'getCoupons']);
        Route::get('/admin/users/{id}/donations', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'getDonations']);
        Route::get('/admin/users/{id}/downloads', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'getDownloads']);
        Route::get('/admin/customers/{id}/profile-details', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'getProfileDetails']);
        Route::put('/admin/customers/{id}/profile-details', [\App\Http\Controllers\Api\Admin\AdminUserProfileController::class, 'updateProfile']);

        // Sellers
        Route::get('/admin/sellers/verifications', [\App\Http\Controllers\Api\Admin\CustomerVerificationController::class, 'sellerIndex']);

        // Gift Cards & Gift Card Orders (Admin Only)
        Route::middleware(['admin.only'])->group(function () {
            Route::get('/admin/gift-card-settings', [\App\Http\Controllers\Api\Ecommerce\EcommerceGiftCardController::class, 'settings']);
            Route::post('/admin/gift-card-settings', [\App\Http\Controllers\Api\Ecommerce\EcommerceGiftCardController::class, 'saveSettings']);
            Route::get('/admin/gift-card-transactions', [\App\Http\Controllers\Api\Ecommerce\EcommerceGiftCardController::class, 'transactions']);
            Route::get('/admin/gift-cards', [\App\Http\Controllers\Api\Ecommerce\EcommerceGiftCardController::class, 'index']);
            Route::post('/admin/gift-cards', [\App\Http\Controllers\Api\Ecommerce\EcommerceGiftCardController::class, 'store']);
            Route::get('/admin/gift-cards/{id}', [\App\Http\Controllers\Api\Ecommerce\EcommerceGiftCardController::class, 'show']);
            Route::put('/admin/gift-cards/{id}', [\App\Http\Controllers\Api\Ecommerce\EcommerceGiftCardController::class, 'update']);
            Route::delete('/admin/gift-cards/{id}', [\App\Http\Controllers\Api\Ecommerce\EcommerceGiftCardController::class, 'destroy']);
            Route::post('/admin/gift-cards/{id}/adjust-balance', [\App\Http\Controllers\Api\Ecommerce\EcommerceGiftCardController::class, 'adjustBalance']);
            Route::post('/admin/gift-cards/{id}/disable', [\App\Http\Controllers\Api\Ecommerce\EcommerceGiftCardController::class, 'disable']);
            Route::post('/admin/gift-cards/{id}/enable', [\App\Http\Controllers\Api\Ecommerce\EcommerceGiftCardController::class, 'enable']);

            // Gift Card Orders
            Route::get('/admin/gift-card-orders', [\App\Http\Controllers\Api\Ecommerce\GiftCardOrderController::class, 'index']);
            Route::post('/admin/gift-card-orders/{id}/issue', [\App\Http\Controllers\Api\Ecommerce\GiftCardOrderController::class, 'issue']);
        });

        // Charity Management
        Route::get('/admin/charities', [\App\Http\Controllers\Api\Admin\CharityController::class, 'index']);
        Route::post('/admin/charities', [\App\Http\Controllers\Api\Admin\CharityController::class, 'store']);
        Route::put('/admin/charities/{id}', [\App\Http\Controllers\Api\Admin\CharityController::class, 'update']);
        Route::delete('/admin/charities/{id}', [\App\Http\Controllers\Api\Admin\CharityController::class, 'destroy']);
        Route::patch('/admin/charities/{id}/status', [\App\Http\Controllers\Api\Admin\CharityController::class, 'toggleStatus']);

        // Donation History
        Route::get('/admin/donations', [\App\Http\Controllers\Api\Admin\DonationController::class, 'index']);

        // Payment Gateways
        Route::get('/admin/payment-gateways', [\App\Http\Controllers\Api\Admin\PaymentGatewayController::class, 'index']);
        Route::post('/admin/payment-gateways', [\App\Http\Controllers\Api\Admin\PaymentGatewayController::class, 'store']);
        Route::post('/admin/payment-gateways/reorder', [\App\Http\Controllers\Api\Admin\PaymentGatewayController::class, 'reorder']);
        Route::post('/admin/payment-gateways/{id}/toggle', [\App\Http\Controllers\Api\Admin\PaymentGatewayController::class, 'toggle']);
        Route::post('/admin/payment-gateways/{id}/test', [\App\Http\Controllers\Api\Admin\PaymentGatewayController::class, 'testConnection']);
        Route::get('/admin/payment-gateways/{id}', [\App\Http\Controllers\Api\Admin\PaymentGatewayController::class, 'show']);
        Route::put('/admin/payment-gateways/{id}', [\App\Http\Controllers\Api\Admin\PaymentGatewayController::class, 'update']);
        Route::delete('/admin/payment-gateways/{id}', [\App\Http\Controllers\Api\Admin\PaymentGatewayController::class, 'destroy']);

        // Shipping Methods
        Route::get('/admin/shipping-methods', [\App\Http\Controllers\Api\Admin\ShippingMethodController::class, 'index']);
        Route::post('/admin/shipping-methods', [\App\Http\Controllers\Api\Admin\ShippingMethodController::class, 'store']);
        Route::put('/admin/shipping-methods/{id}', [\App\Http\Controllers\Api\Admin\ShippingMethodController::class, 'update']);
        Route::delete('/admin/shipping-methods/{id}', [\App\Http\Controllers\Api\Admin\ShippingMethodController::class, 'destroy']);

        // Email Templates
        Route::get('/admin/email-templates', [\App\Http\Controllers\Api\Admin\EmailTemplateController::class, 'index']);
        Route::post('/admin/email-templates', [\App\Http\Controllers\Api\Admin\EmailTemplateController::class, 'store']);
        Route::get('/admin/email-templates/{id}', [\App\Http\Controllers\Api\Admin\EmailTemplateController::class, 'show']);
        Route::put('/admin/email-templates/{id}', [\App\Http\Controllers\Api\Admin\EmailTemplateController::class, 'update']);
        Route::delete('/admin/email-templates/{id}', [\App\Http\Controllers\Api\Admin\EmailTemplateController::class, 'destroy']);

        // Popup Templates
        Route::get('/admin/popup-templates/overview', [\App\Http\Controllers\Api\Admin\PopupTemplateController::class, 'overview']);
        Route::get('/admin/popup-templates', [\App\Http\Controllers\Api\Admin\PopupTemplateController::class, 'index']);
        Route::post('/admin/popup-templates/upload-image', [\App\Http\Controllers\Api\Admin\PopupTemplateController::class, 'uploadImage']);
        Route::post('/admin/popup-templates', [\App\Http\Controllers\Api\Admin\PopupTemplateController::class, 'store']);
        Route::get('/admin/popup-templates/{id}', [\App\Http\Controllers\Api\Admin\PopupTemplateController::class, 'show']);
        Route::put('/admin/popup-templates/{id}', [\App\Http\Controllers\Api\Admin\PopupTemplateController::class, 'update']);
        Route::delete('/admin/popup-templates/{id}', [\App\Http\Controllers\Api\Admin\PopupTemplateController::class, 'destroy']);
        Route::post('/admin/popup-templates/{id}/restore', [\App\Http\Controllers\Api\Admin\PopupTemplateController::class, 'restore']);

        Route::get('/admin/email/overview', [\App\Http\Controllers\Api\Admin\EmailNotificationController::class, 'overview']);
        Route::get('/admin/email/settings', [\App\Http\Controllers\Api\Admin\EmailNotificationController::class, 'settings']);
        Route::post('/admin/email/settings', [\App\Http\Controllers\Api\Admin\EmailNotificationController::class, 'saveSettings']);
        Route::get('/admin/email/templates', [\App\Http\Controllers\Api\Admin\EmailNotificationController::class, 'templates']);
        Route::put('/admin/email/templates/{template}', [\App\Http\Controllers\Api\Admin\EmailNotificationController::class, 'updateTemplate']);
        Route::post('/admin/email/templates/{template}/restore', [\App\Http\Controllers\Api\Admin\EmailNotificationController::class, 'restoreTemplate']);
        Route::post('/admin/email/test', [\App\Http\Controllers\Api\Admin\EmailNotificationController::class, 'test']);
        Route::post('/admin/email/trigger', [\App\Http\Controllers\Api\Admin\EmailNotificationController::class, 'triggerEvent']);
        Route::post('/admin/email/templates/upload-image', [\App\Http\Controllers\Api\Admin\EmailNotificationController::class, 'uploadImage']);
        Route::get('/admin/email/logs', [\App\Http\Controllers\Api\Admin\EmailNotificationController::class, 'logs']);
        Route::post('/admin/email/logs/{log}/retry', [\App\Http\Controllers\Api\Admin\EmailNotificationController::class, 'retry']);

        // Marketing Campaigns
        Route::get('/admin/marketing-campaigns', [MarketingCampaignController::class, 'index']);
        Route::get('/admin/marketing-campaigns/compose/{channel}', [MarketingCampaignController::class, 'compose']);
        Route::get('/admin/marketing-campaigns/{marketingCampaign}', [MarketingCampaignController::class, 'show'])->whereNumber('marketingCampaign');
        Route::post('/admin/marketing-campaigns', [MarketingCampaignController::class, 'store']);
        Route::post('/admin/marketing-campaigns/send', [MarketingCampaignController::class, 'send']);
        Route::post('/admin/marketing-campaigns/test', [MarketingCampaignController::class, 'test']);

        // Sales Report (aggregate)
        Route::get('/admin/sales-report', [AdminOrderController::class, 'stats']);

        // Transactions (combined financial ledger)
        Route::get('/admin/transactions', [AdminFinancialTransactionController::class, 'index']);

        /*
        |--------------------------------------------------------------------------
        | Home Page Management Routes (Admin Only)
        |--------------------------------------------------------------------------
        |
        | These routes handle home page content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update Home Page Content (Admin Only)
        // POST /api/v1/home-page
        // Creates new home page or updates existing one
        Route::post('/home-page', [HomePageController::class, 'store'])
            ->name('api.v1.home-page.store');

        // Update Home Page Content (Admin Only)
        // PUT/PATCH/POST /api/v1/home-page/{id}
        // Updates existing home page configuration
        // POST is supported for form-data uploads with _method=PUT
        Route::put('/home-page/{id}', [HomePageController::class, 'update'])
            ->name('api.v1.home-page.update');
        Route::patch('/home-page/{id}', [HomePageController::class, 'update'])
            ->name('api.v1.home-page.update.patch');
        Route::post('/home-page/{id}', [HomePageController::class, 'update'])
            ->name('api.v1.home-page.update.post');

        // Delete Home Page Content (Admin Only)
        // DELETE /api/v1/home-page/{id}
        // Deletes home page and associated images
        Route::delete('/home-page/{id}', [HomePageController::class, 'destroy'])
            ->name('api.v1.home-page.destroy');

        // Delete Specific Field from Home Page (Admin Only)
        // DELETE /api/v1/home-page/{id}/field/{field}
        // Sets specific field to null and removes associated images if applicable
        Route::delete('/home-page/{id}/field/{field}', [HomePageController::class, 'deleteField'])
            ->name('api.v1.home-page.delete-field');

        // Membership Page Content (Admin Only)
        Route::post('/membership-page', [MembershipPageController::class, 'store'])
            ->name('api.v1.membership-page.store');
        Route::put('/membership-page/{id}', [MembershipPageController::class, 'update'])
            ->name('api.v1.membership-page.update');
        Route::patch('/membership-page/{id}', [MembershipPageController::class, 'update'])
            ->name('api.v1.membership-page.update.patch');
        Route::delete('/membership-page/{id}', [MembershipPageController::class, 'destroy'])
            ->name('api.v1.membership-page.destroy');

        // Gift Card Page Content (Admin Only)
        Route::post('/gift-card-page', [GiftCardPageController::class, 'store'])
            ->name('api.v1.gift-card-page.store');
        Route::put('/gift-card-page/{id}', [GiftCardPageController::class, 'update'])
            ->name('api.v1.gift-card-page.update');
        Route::patch('/gift-card-page/{id}', [GiftCardPageController::class, 'update'])
            ->name('api.v1.gift-card-page.update.patch');

        // Create or Update Service Page Content (Admin Only)
        // POST /api/v1/service-page
        // Creates new service page or updates existing one
        Route::post('/service-page', [ServicePageController::class, 'store'])
            ->name('api.v1.service-page.store');

        // Update Service Page Content (Admin Only)
        // PUT/PATCH/POST /api/v1/service-page/{id}
        // Updates existing service page configuration
        // POST is supported for form-data uploads with _method=PUT
        Route::put('/service-page/{id}', [ServicePageController::class, 'update'])
            ->name('api.v1.service-page.update');
        Route::patch('/service-page/{id}', [ServicePageController::class, 'update'])
            ->name('api.v1.service-page.update.patch');
        Route::post('/service-page/{id}', [ServicePageController::class, 'update'])
            ->name('api.v1.service-page.update.post');

        // Delete Service Page Content (Admin Only)
        // DELETE /api/v1/service-page/{id}
        // Deletes service page and associated images
        Route::delete('/service-page/{id}', [ServicePageController::class, 'destroy'])
            ->name('api.v1.service-page.destroy');

        // Delete Specific Field from Service Page (Admin Only)
        // DELETE /api/v1/service-page/{id}/field/{field}
        // Sets specific field to null and removes associated images if applicable
        Route::delete('/service-page/{id}/field/{field}', [ServicePageController::class, 'deleteField'])
            ->name('api.v1.service-page.delete-field');

        /*
        |--------------------------------------------------------------------------
        | Features Section Management Routes (Admin Only)
        |--------------------------------------------------------------------------
        |
        | These routes handle features section content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update Features Section Content (Admin Only)
        // POST /api/v1/features-sections
        // Creates new features section or updates existing one
        Route::post('/features-sections', [FeaturesSectionController::class, 'store'])
            ->name('api.v1.features-sections.store');

        // Update Features Section Content (Admin Only)
        // PUT/PATCH/POST /api/v1/features-sections/{id}
        // Updates existing features section configuration
        // POST is supported for form-data uploads with _method=PUT
        Route::put('/features-sections/{id}', [FeaturesSectionController::class, 'update'])
            ->name('api.v1.features-sections.update');
        Route::patch('/features-sections/{id}', [FeaturesSectionController::class, 'update'])
            ->name('api.v1.features-sections.update.patch');
        Route::post('/features-sections/{id}', [FeaturesSectionController::class, 'update'])
            ->name('api.v1.features-sections.update.post');

        // Delete Features Section Content (Admin Only)
        // DELETE /api/v1/features-sections/{id}
        // Deletes features section and associated images
        Route::delete('/features-sections/{id}', [FeaturesSectionController::class, 'destroy'])
            ->name('api.v1.features-sections.destroy');

        // Delete Specific Field from Features Section (Admin Only)
        // DELETE /api/v1/features-sections/{id}/field/{field}
        // Sets specific field to null and removes associated images if applicable
        Route::delete('/features-sections/{id}/field/{field}', [FeaturesSectionController::class, 'deleteField'])
            ->name('api.v1.features-sections.delete-field');

        /*
        |--------------------------------------------------------------------------
        | Analytics Section Management Routes (Admin Only)
        |--------------------------------------------------------------------------
        |
        | These routes handle analytics section content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update Analytics Section Content (Admin Only)
        // POST /api/v1/analytics-sections
        // Creates new analytics section or updates existing one
        Route::post('/analytics-sections', [AnalyticsSectionController::class, 'store'])
            ->name('api.v1.analytics-sections.store');

        // Update Analytics Section Content (Admin Only)
        // PUT/PATCH/POST /api/v1/analytics-sections/{id}
        // Updates existing analytics section configuration
        // POST is supported for form-data uploads with _method=PUT
        Route::put('/analytics-sections/{id}', [AnalyticsSectionController::class, 'update'])
            ->name('api.v1.analytics-sections.update');
        Route::patch('/analytics-sections/{id}', [AnalyticsSectionController::class, 'update'])
            ->name('api.v1.analytics-sections.update.patch');
        Route::post('/analytics-sections/{id}', [AnalyticsSectionController::class, 'update'])
            ->name('api.v1.analytics-sections.update.post');

        // Delete Analytics Section Content (Admin Only)
        // DELETE /api/v1/analytics-sections/{id}
        // Deletes analytics section and associated images
        Route::delete('/analytics-sections/{id}', [AnalyticsSectionController::class, 'destroy'])
            ->name('api.v1.analytics-sections.destroy');

        // Delete Specific Field from Analytics Section (Admin Only)
        // DELETE /api/v1/analytics-sections/{id}/field/{field}
        // Sets specific field to null and removes associated images if applicable
        Route::delete('/analytics-sections/{id}/field/{field}', [AnalyticsSectionController::class, 'deleteField'])
            ->name('api.v1.analytics-sections.delete-field');

        /*
        |--------------------------------------------------------------------------
        | Chart Section Management Routes (Admin Only)
        |--------------------------------------------------------------------------
        |
        | These routes handle chart section content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update Chart Section Content (Admin Only)
        // POST /api/v1/chart-sections
        // Creates new chart section or updates existing one
        Route::post('/chart-sections', [ChartSectionController::class, 'store'])
            ->name('api.v1.chart-sections.store');

        // Update Chart Section Content (Admin Only)
        // PUT/PATCH/POST /api/v1/chart-sections/{id}
        // Updates existing chart section configuration
        // POST is supported for form-data uploads with _method=PUT
        Route::put('/chart-sections/{id}', [ChartSectionController::class, 'update'])
            ->name('api.v1.chart-sections.update');
        Route::patch('/chart-sections/{id}', [ChartSectionController::class, 'update'])
            ->name('api.v1.chart-sections.update.patch');
        Route::post('/chart-sections/{id}', [ChartSectionController::class, 'update'])
            ->name('api.v1.chart-sections.update.post');

        // Delete Chart Section Content (Admin Only)
        // DELETE /api/v1/chart-sections/{id}
        // Deletes chart section and associated images
        Route::delete('/chart-sections/{id}', [ChartSectionController::class, 'destroy'])
            ->name('api.v1.chart-sections.destroy');

        // Delete Specific Field from Chart Section (Admin Only)
        // DELETE /api/v1/chart-sections/{id}/field/{field}
        // Sets specific field to null and removes associated images if applicable
        Route::delete('/chart-sections/{id}/field/{field}', [ChartSectionController::class, 'deleteField'])
            ->name('api.v1.chart-sections.delete-field');

        /*
        |--------------------------------------------------------------------------
        | Tab Section Management Routes (Admin Only)
        |--------------------------------------------------------------------------
        |
        | These routes handle tab section content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update Tab Section Content (Admin Only)
        // POST /api/v1/tab-sections
        // Creates new tab section or updates existing one
        Route::post('/tab-sections', [TabSectionController::class, 'store'])
            ->name('api.v1.tab-sections.store');

        // Update Tab Section Content (Admin Only)
        // PUT/PATCH/POST /api/v1/tab-sections/{id}
        // Updates existing tab section configuration
        // POST is supported for form-data uploads with _method=PUT
        Route::put('/tab-sections/{id}', [TabSectionController::class, 'update'])
            ->name('api.v1.tab-sections.update');
        Route::patch('/tab-sections/{id}', [TabSectionController::class, 'update'])
            ->name('api.v1.tab-sections.update.patch');
        Route::post('/tab-sections/{id}', [TabSectionController::class, 'update'])
            ->name('api.v1.tab-sections.update.post');

        // Delete Tab Section Content (Admin Only)
        // DELETE /api/v1/tab-sections/{id}
        // Deletes tab section and associated tabs and images
        Route::delete('/tab-sections/{id}', [TabSectionController::class, 'destroy'])
            ->name('api.v1.tab-sections.destroy');

        // Delete Specific Field from Tab Section (Admin Only)
        // DELETE /api/v1/tab-sections/{id}/field/{field}
        // Sets specific field to null and removes associated images if applicable
        Route::delete('/tab-sections/{id}/field/{field}', [TabSectionController::class, 'deleteField'])
            ->name('api.v1.tab-sections.delete-field');

        /*
        |--------------------------------------------------------------------------
        | Showcase Section Management Routes (Admin Only)
        |--------------------------------------------------------------------------
        |
        | These routes handle showcase section content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update Showcase Section Content (Admin Only)
        // POST /api/v1/showcase-sections
        // Creates new showcase section or updates existing one
        Route::post('/showcase-sections', [ShowcaseSectionController::class, 'store'])
            ->name('api.v1.showcase-sections.store');

        // Update Showcase Section Content (Admin Only)
        // PUT/PATCH/POST /api/v1/showcase-sections/{id}
        // Updates existing showcase section configuration
        // POST is supported for form-data uploads with _method=PUT
        Route::put('/showcase-sections/{id}', [ShowcaseSectionController::class, 'update'])
            ->name('api.v1.showcase-sections.update');
        Route::patch('/showcase-sections/{id}', [ShowcaseSectionController::class, 'update'])
            ->name('api.v1.showcase-sections.update.patch');
        Route::post('/showcase-sections/{id}', [ShowcaseSectionController::class, 'update'])
            ->name('api.v1.showcase-sections.update.post');

        // Delete Showcase Section Content (Admin Only)
        // DELETE /api/v1/showcase-sections/{id}
        // Deletes showcase section and associated showcase items and images
        Route::delete('/showcase-sections/{id}', [ShowcaseSectionController::class, 'destroy'])
            ->name('api.v1.showcase-sections.destroy');

        // Delete Specific Field from Showcase Section (Admin Only)
        // DELETE /api/v1/showcase-sections/{id}/field/{field}
        // Sets specific field to null and removes associated images if applicable
        Route::delete('/showcase-sections/{id}/field/{field}', [ShowcaseSectionController::class, 'deleteField'])
            ->name('api.v1.showcase-sections.delete-field');

        /*
        |--------------------------------------------------------------------------
        | About Section Management Routes (Admin Only)
        |--------------------------------------------------------------------------
        |
        | These routes handle about section content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update About Section Content (Admin Only)
        // POST /api/v1/about-section
        // Creates new about section or updates existing one
        Route::post('/about-section', [AboutSectionController::class, 'store'])
            ->name('api.v1.about-section.store');

        // Update About Section Content (Admin Only)
        // PUT/PATCH/POST /api/v1/about-section/{id}
        // Updates existing about section configuration
        Route::put('/about-section/{id}', [AboutSectionController::class, 'update'])
            ->name('api.v1.about-section.update');
        Route::patch('/about-section/{id}', [AboutSectionController::class, 'update'])
            ->name('api.v1.about-section.update.patch');
        Route::post('/about-section/{id}', [AboutSectionController::class, 'update'])
            ->name('api.v1.about-section.update.post');

        // Delete Specific Field from About Section (Admin Only)
        // DELETE /api/v1/about-section/{id}/field/{field}
        // Deletes a single field (e.g., image) from about section without deleting the entire section
        Route::delete('/about-section/{id}/field/{field}', [AboutSectionController::class, 'deleteField'])
            ->name('api.v1.about-section.delete-field');

        // Delete About Section Content (Admin Only)
        // DELETE /api/v1/about-section/{id}
        // Deletes about section and associated images
        Route::delete('/about-section/{id}', [AboutSectionController::class, 'destroy'])
            ->name('api.v1.about-section.destroy');

        /*
        |--------------------------------------------------------------------------
        | Service Section Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle service section content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update Service Section Content (Admin Only)
        // POST /api/v1/service-section
        // Creates new service section or updates existing one
        Route::post('/service-section', [ServiceSectionController::class, 'store'])
            ->name('api.v1.service-section.store');

        // Update Service Section Content (Admin Only)
        // PUT/PATCH/POST /api/v1/service-section/{id}
        // Updates existing service section configuration
        Route::put('/service-section/{id}', [ServiceSectionController::class, 'update'])
            ->name('api.v1.service-section.update');
        Route::patch('/service-section/{id}', [ServiceSectionController::class, 'update'])
            ->name('api.v1.service-section.update.patch');
        Route::post('/service-section/{id}', [ServiceSectionController::class, 'update'])
            ->name('api.v1.service-section.update.post');

        // Delete Specific Field from Service Section (Admin Only)
        // DELETE /api/v1/service-section/{id}/field/{field}
        // Deletes a single field (e.g., background_image) from service section without deleting the entire section
        Route::delete('/service-section/{id}/field/{field}', [ServiceSectionController::class, 'deleteField'])
            ->name('api.v1.service-section.delete-field');

        // Delete Service Section Content (Admin Only)
        // DELETE /api/v1/service-section/{id}
        // Deletes service section and associated images
        Route::delete('/service-section/{id}', [ServiceSectionController::class, 'destroy'])
            ->name('api.v1.service-section.destroy');

        /*
        |--------------------------------------------------------------------------
        | Service Card Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle service card content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create Service Card (Admin Only)
        // POST /api/v1/service-cards
        // Creates a new service card
        Route::post('/service-cards', [ServiceCardController::class, 'store'])
            ->name('api.v1.service-cards.store');

        // Update Service Card Content (Admin Only)
        // PUT/PATCH/POST /api/v1/service-cards/{id}
        // Updates existing service card configuration
        Route::put('/service-cards/{id}', [ServiceCardController::class, 'update'])
            ->name('api.v1.service-cards.update');
        Route::patch('/service-cards/{id}', [ServiceCardController::class, 'update'])
            ->name('api.v1.service-cards.update.patch');
        Route::post('/service-cards/{id}', [ServiceCardController::class, 'update'])
            ->name('api.v1.service-cards.update.post');

        // Delete Service Card (Admin Only)
        // DELETE /api/v1/service-cards/{id}
        // Deletes service card and associated image
        Route::delete('/service-cards/{id}', [ServiceCardController::class, 'destroy'])
            ->name('api.v1.service-cards.destroy');

        /*
        |--------------------------------------------------------------------------
        | What We Create Section Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle what we create section content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update What We Create Section Content (Admin Only)
        Route::post('/what-we-create-section', [WhatWeCreateSectionController::class, 'store'])
            ->name('api.v1.what-we-create-section.store');

        // Update What We Create Section Content (Admin Only)
        Route::put('/what-we-create-section/{id}', [WhatWeCreateSectionController::class, 'update'])
            ->name('api.v1.what-we-create-section.update');
        Route::patch('/what-we-create-section/{id}', [WhatWeCreateSectionController::class, 'update'])
            ->name('api.v1.what-we-create-section.update.patch');
        Route::post('/what-we-create-section/{id}', [WhatWeCreateSectionController::class, 'update'])
            ->name('api.v1.what-we-create-section.update.post');

        // Delete Specific Field from What We Create Section (Admin Only)
        Route::delete('/what-we-create-section/{id}/field/{field}', [WhatWeCreateSectionController::class, 'deleteField'])
            ->name('api.v1.what-we-create-section.delete-field');

        // Delete What We Create Section Content (Admin Only)
        Route::delete('/what-we-create-section/{id}', [WhatWeCreateSectionController::class, 'destroy'])
            ->name('api.v1.what-we-create-section.destroy');

        /*
        |--------------------------------------------------------------------------
        | What We Create Tab Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle what we create tab content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create What We Create Tab (Admin Only)
        Route::post('/what-we-create-tabs', [WhatWeCreateTabController::class, 'store'])
            ->name('api.v1.what-we-create-tabs.store');

        // Update What We Create Tab Content (Admin Only)
        Route::put('/what-we-create-tabs/{id}', [WhatWeCreateTabController::class, 'update'])
            ->name('api.v1.what-we-create-tabs.update');
        Route::patch('/what-we-create-tabs/{id}', [WhatWeCreateTabController::class, 'update'])
            ->name('api.v1.what-we-create-tabs.update.patch');
        Route::post('/what-we-create-tabs/{id}', [WhatWeCreateTabController::class, 'update'])
            ->name('api.v1.what-we-create-tabs.update.post');

        // Delete Specific Field from What We Create Tab (Admin Only)
        Route::delete('/what-we-create-tabs/{id}/field/{field}', [WhatWeCreateTabController::class, 'deleteField'])
            ->name('api.v1.what-we-create-tabs.delete-field');

        // Delete What We Create Tab (Admin Only)
        Route::delete('/what-we-create-tabs/{id}', [WhatWeCreateTabController::class, 'destroy'])
            ->name('api.v1.what-we-create-tabs.destroy');

        /*
        |--------------------------------------------------------------------------
        | Category Tab Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle category tab content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create Category Tab (Admin Only)
        Route::post('/category-tabs', [CategoryTabController::class, 'store'])
            ->name('api.v1.category-tabs.store');

        // Update Category Tab Content (Admin Only)
        Route::put('/category-tabs/{id}', [CategoryTabController::class, 'update'])
            ->name('api.v1.category-tabs.update');
        Route::patch('/category-tabs/{id}', [CategoryTabController::class, 'update'])
            ->name('api.v1.category-tabs.update.patch');
        Route::post('/category-tabs/{id}', [CategoryTabController::class, 'update'])
            ->name('api.v1.category-tabs.update.post');

        // Delete Category Tab (Admin Only)
        Route::delete('/category-tabs/{id}', [CategoryTabController::class, 'destroy'])
            ->name('api.v1.category-tabs.destroy');

        /*
        |--------------------------------------------------------------------------
        | Why Choose Us Section Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle why choose us section content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update Why Choose Us Section Content (Admin Only)
        Route::post('/why-choose-us-section', [WhyChooseUsSectionController::class, 'store'])
            ->name('api.v1.why-choose-us-section.store');

        // Update Why Choose Us Section Content (Admin Only)
        Route::put('/why-choose-us-section/{id}', [WhyChooseUsSectionController::class, 'update'])
            ->name('api.v1.why-choose-us-section.update');
        Route::patch('/why-choose-us-section/{id}', [WhyChooseUsSectionController::class, 'update'])
            ->name('api.v1.why-choose-us-section.update.patch');
        Route::post('/why-choose-us-section/{id}', [WhyChooseUsSectionController::class, 'update'])
            ->name('api.v1.why-choose-us-section.update.post');

        // Delete Specific Field from Why Choose Us Section (Admin Only)
        Route::delete('/why-choose-us-section/{id}/field/{field}', [WhyChooseUsSectionController::class, 'deleteField'])
            ->name('api.v1.why-choose-us-section.delete-field');

        // Delete Why Choose Us Section Content (Admin Only)
        Route::delete('/why-choose-us-section/{id}', [WhyChooseUsSectionController::class, 'destroy'])
            ->name('api.v1.why-choose-us-section.destroy');

        /*
        |--------------------------------------------------------------------------
        | Why Choose Us Tab Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle why choose us tab content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create Why Choose Us Tab (Admin Only)
        Route::post('/why-choose-us-tabs', [WhyChooseUsTabController::class, 'store'])
            ->name('api.v1.why-choose-us-tabs.store');

        // Update Why Choose Us Tab Content (Admin Only)
        Route::put('/why-choose-us-tabs/{id}', [WhyChooseUsTabController::class, 'update'])
            ->name('api.v1.why-choose-us-tabs.update');
        Route::patch('/why-choose-us-tabs/{id}', [WhyChooseUsTabController::class, 'update'])
            ->name('api.v1.why-choose-us-tabs.update.patch');
        Route::post('/why-choose-us-tabs/{id}', [WhyChooseUsTabController::class, 'update'])
            ->name('api.v1.why-choose-us-tabs.update.post');

        // Delete Why Choose Us Tab (Admin Only)
        Route::delete('/why-choose-us-tabs/{id}', [WhyChooseUsTabController::class, 'destroy'])
            ->name('api.v1.why-choose-us-tabs.destroy');

        /*
        |--------------------------------------------------------------------------
        | Our Facts Section Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle our facts section content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update Our Facts Section Content (Admin Only)
        Route::post('/our-facts-section', [OurFactsSectionController::class, 'store'])
            ->name('api.v1.our-facts-section.store');

        // Update Our Facts Section Content (Admin Only)
        Route::put('/our-facts-section/{id}', [OurFactsSectionController::class, 'update'])
            ->name('api.v1.our-facts-section.update');
        Route::patch('/our-facts-section/{id}', [OurFactsSectionController::class, 'update'])
            ->name('api.v1.our-facts-section.update.patch');
        Route::post('/our-facts-section/{id}', [OurFactsSectionController::class, 'update'])
            ->name('api.v1.our-facts-section.update.post');

        // Delete Specific Field from Our Facts Section (Admin Only)
        Route::delete('/our-facts-section/{id}/field/{field}', [OurFactsSectionController::class, 'deleteField'])
            ->name('api.v1.our-facts-section.delete-field');

        // Delete Our Facts Section Content (Admin Only)
        Route::delete('/our-facts-section/{id}', [OurFactsSectionController::class, 'destroy'])
            ->name('api.v1.our-facts-section.destroy');

        /*
        |--------------------------------------------------------------------------
        | Our Fact Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle our fact content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create Our Fact (Admin Only)
        Route::post('/our-facts', [OurFactController::class, 'store'])
            ->name('api.v1.our-facts.store');

        // Update Our Fact Content (Admin Only)
        Route::put('/our-facts/{id}', [OurFactController::class, 'update'])
            ->name('api.v1.our-facts.update');
        Route::patch('/our-facts/{id}', [OurFactController::class, 'update'])
            ->name('api.v1.our-facts.update.patch');
        Route::post('/our-facts/{id}', [OurFactController::class, 'update'])
            ->name('api.v1.our-facts.update.post');

        // Delete Our Fact (Admin Only)
        Route::delete('/our-facts/{id}', [OurFactController::class, 'destroy'])
            ->name('api.v1.our-facts.destroy');

        /*
        |--------------------------------------------------------------------------
        | Our Promise Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle our promise content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update Our Promise Content (Admin Only)
        Route::post('/our-promise', [OurPromiseController::class, 'store'])
            ->name('api.v1.our-promise.store');

        // Update Our Promise Content (Admin Only)
        Route::put('/our-promise/{id}', [OurPromiseController::class, 'update'])
            ->name('api.v1.our-promise.update');
        Route::patch('/our-promise/{id}', [OurPromiseController::class, 'update'])
            ->name('api.v1.our-promise.update.patch');
        Route::post('/our-promise/{id}', [OurPromiseController::class, 'update'])
            ->name('api.v1.our-promise.update.post');

        // Delete Our Promise Content (Admin Only)
        Route::delete('/our-promise/{id}', [OurPromiseController::class, 'destroy'])
            ->name('api.v1.our-promise.destroy');

        /*
        |--------------------------------------------------------------------------
        | Process Step Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle process step content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create Process Step (Admin Only)
        Route::post('/process-steps', [ProcessStepController::class, 'store'])
            ->name('api.v1.process-steps.store');

        // Update Process Step Content (Admin Only)
        Route::put('/process-steps/{id}', [ProcessStepController::class, 'update'])
            ->name('api.v1.process-steps.update');
        Route::patch('/process-steps/{id}', [ProcessStepController::class, 'update'])
            ->name('api.v1.process-steps.update.patch');
        Route::post('/process-steps/{id}', [ProcessStepController::class, 'update'])
            ->name('api.v1.process-steps.update.post');

        // Delete Process Step (Admin Only)
        Route::delete('/process-steps/{id}', [ProcessStepController::class, 'destroy'])
            ->name('api.v1.process-steps.destroy');

        /*
        |--------------------------------------------------------------------------
        | Reviews Section Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle reviews section content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update Reviews Section Content (Admin Only)
        Route::post('/reviews-section', [ReviewsSectionController::class, 'store'])
            ->name('api.v1.reviews-section.store');

        // Update Reviews Section Content (Admin Only)
        Route::put('/reviews-section/{id}', [ReviewsSectionController::class, 'update'])
            ->name('api.v1.reviews-section.update');
        Route::patch('/reviews-section/{id}', [ReviewsSectionController::class, 'update'])
            ->name('api.v1.reviews-section.update.patch');
        Route::post('/reviews-section/{id}', [ReviewsSectionController::class, 'update'])
            ->name('api.v1.reviews-section.update.post');

        // Delete Specific Field from Reviews Section (Admin Only)
        Route::delete('/reviews-section/{id}/field/{field}', [ReviewsSectionController::class, 'deleteField'])
            ->name('api.v1.reviews-section.delete-field');

        // Delete Reviews Section Content (Admin Only)
        Route::delete('/reviews-section/{id}', [ReviewsSectionController::class, 'destroy'])
            ->name('api.v1.reviews-section.destroy');

        /*
        |--------------------------------------------------------------------------
        | Review Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle review content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create Review (Admin Only)
        Route::post('/reviews', [ReviewController::class, 'store'])
            ->name('api.v1.reviews.store');

        // Update Review Content (Admin Only)
        Route::put('/reviews/{id}', [ReviewController::class, 'update'])
            ->name('api.v1.reviews.update');
        Route::patch('/reviews/{id}', [ReviewController::class, 'update'])
            ->name('api.v1.reviews.update.patch');
        Route::post('/reviews/{id}', [ReviewController::class, 'update'])
            ->name('api.v1.reviews.update.post');

        // Delete Review (Admin Only)
        Route::delete('/reviews/{id}', [ReviewController::class, 'destroy'])
            ->name('api.v1.reviews.destroy');

        /*
        |--------------------------------------------------------------------------
        | Portfolio Section Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle portfolio section content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update Portfolio Section Content (Admin Only)
        Route::post('/portfolio-section', [PortfolioSectionController::class, 'store'])
            ->name('api.v1.portfolio-section.store');

        // Update Portfolio Section Content (Admin Only)
        Route::put('/portfolio-section/{id}', [PortfolioSectionController::class, 'update'])
            ->name('api.v1.portfolio-section.update');
        Route::patch('/portfolio-section/{id}', [PortfolioSectionController::class, 'update'])
            ->name('api.v1.portfolio-section.update.patch');
        Route::post('/portfolio-section/{id}', [PortfolioSectionController::class, 'update'])
            ->name('api.v1.portfolio-section.update.post');

        // Delete Specific Field from Portfolio Section (Admin Only)
        Route::delete('/portfolio-section/{id}/field/{field}', [PortfolioSectionController::class, 'deleteField'])
            ->name('api.v1.portfolio-section.delete-field');

        // Delete Portfolio Section Content (Admin Only)
        Route::delete('/portfolio-section/{id}', [PortfolioSectionController::class, 'destroy'])
            ->name('api.v1.portfolio-section.destroy');

        /*
        |--------------------------------------------------------------------------
        | Portfolio Item Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle portfolio item content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create Portfolio Item (Admin Only)
        Route::post('/portfolio-items', [PortfolioItemController::class, 'store'])
            ->name('api.v1.portfolio-items.store');

        // Update Portfolio Item Content (Admin Only)
        Route::put('/portfolio-items/{id}', [PortfolioItemController::class, 'update'])
            ->name('api.v1.portfolio-items.update');
        Route::patch('/portfolio-items/{id}', [PortfolioItemController::class, 'update'])
            ->name('api.v1.portfolio-items.update.patch');
        Route::post('/portfolio-items/{id}', [PortfolioItemController::class, 'update'])
            ->name('api.v1.portfolio-items.update.post');

        // Delete Portfolio Item (Admin Only)
        Route::delete('/portfolio-items/{id}', [PortfolioItemController::class, 'destroy'])
            ->name('api.v1.portfolio-items.destroy');

        /*
        |--------------------------------------------------------------------------
        | Quote Section Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle quote section content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update Quote Section Content (Admin Only)
        Route::post('/quote-section', [QuoteSectionController::class, 'store'])
            ->name('api.v1.quote-section.store');

        // Update Quote Section Content (Admin Only)
        Route::put('/quote-section/{id}', [QuoteSectionController::class, 'update'])
            ->name('api.v1.quote-section.update');
        Route::patch('/quote-section/{id}', [QuoteSectionController::class, 'update'])
            ->name('api.v1.quote-section.update.patch');
        Route::post('/quote-section/{id}', [QuoteSectionController::class, 'update'])
            ->name('api.v1.quote-section.update.post');

        // Delete Specific Field from Quote Section (Admin Only)
        Route::delete('/quote-section/{id}/field/{field}', [QuoteSectionController::class, 'deleteField'])
            ->name('api.v1.quote-section.delete-field');

        // Delete Quote Section Content (Admin Only)
        Route::delete('/quote-section/{id}', [QuoteSectionController::class, 'destroy'])
            ->name('api.v1.quote-section.destroy');

        /*
        |--------------------------------------------------------------------------
        | Quote Form Fields Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle dynamic quote form field management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Get All Quote Form Fields (Admin Only)
        Route::get('/quote-form-fields/admin', [QuoteFormFieldController::class, 'index'])
            ->name('api.v1.quote-form-fields.index');

        // Get Page Settings (contact email) (Admin Only)
        Route::get('/quote-form-fields/page-settings', [QuoteFormFieldController::class, 'getPageSettings'])
            ->name('api.v1.quote-form-fields.page-settings.get');

        // Update Page Settings (contact email) (Admin Only)
        Route::put('/quote-form-fields/page-settings', [QuoteFormFieldController::class, 'updatePageSettings'])
            ->name('api.v1.quote-form-fields.page-settings.update');

        // Create Quote Form Field (Admin Only)
        Route::post('/quote-form-fields', [QuoteFormFieldController::class, 'store'])
            ->name('api.v1.quote-form-fields.store');

        // Upload Image for Image Choice (Admin Only)
        Route::post('/quote-form-fields/upload-image', [QuoteFormFieldController::class, 'uploadImage'])
            ->name('api.v1.quote-form-fields.upload-image');

        // Update Quote Form Field (Admin Only)
        Route::put('/quote-form-fields/{id}', [QuoteFormFieldController::class, 'update'])
            ->name('api.v1.quote-form-fields.update');

        // Reorder Quote Form Fields (Admin Only)
        Route::post('/quote-form-fields/reorder', [QuoteFormFieldController::class, 'reorder'])
            ->name('api.v1.quote-form-fields.reorder');

        // Reorder Sections (Admin Only)
        Route::post('/quote-form-fields/reorder-sections', [QuoteFormFieldController::class, 'reorderSections'])
            ->name('api.v1.quote-form-fields.reorder-sections');

        // Delete Quote Form Field (Admin Only)
        Route::delete('/quote-form-fields/{id}', [QuoteFormFieldController::class, 'destroy'])
            ->name('api.v1.quote-form-fields.destroy');

        /*
        |--------------------------------------------------------------------------
        | Hero Section Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle hero section content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update Hero Section Content (Admin Only)
        Route::post('/hero-section', [HeroSectionController::class, 'store'])
            ->name('api.v1.hero-section.store');

        // Update Hero Section Content (Admin Only)
        Route::put('/hero-section/{id}', [HeroSectionController::class, 'update'])
            ->name('api.v1.hero-section.update');
        Route::patch('/hero-section/{id}', [HeroSectionController::class, 'update'])
            ->name('api.v1.hero-section.update.patch');
        Route::post('/hero-section/{id}', [HeroSectionController::class, 'update'])
            ->name('api.v1.hero-section.update.post');

        // Delete Specific Field from Hero Section (Admin Only)
        Route::delete('/hero-section/{id}/field/{field}', [HeroSectionController::class, 'deleteField'])
            ->name('api.v1.hero-section.delete-field');

        // Delete Hero Section Content (Admin Only)
        Route::delete('/hero-section/{id}', [HeroSectionController::class, 'destroy'])
            ->name('api.v1.hero-section.destroy');

        /*
        |--------------------------------------------------------------------------
        | About Founder Section Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle about founder section content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update About Founder Section Content (Admin Only)
        Route::post('/about-founder-section', [AboutFounderSectionController::class, 'store'])
            ->name('api.v1.about-founder-section.store');

        // Update About Founder Section Content (Admin Only)
        Route::put('/about-founder-section/{id}', [AboutFounderSectionController::class, 'update'])
            ->name('api.v1.about-founder-section.update');
        Route::patch('/about-founder-section/{id}', [AboutFounderSectionController::class, 'update'])
            ->name('api.v1.about-founder-section.update.patch');
        Route::post('/about-founder-section/{id}', [AboutFounderSectionController::class, 'update'])
            ->name('api.v1.about-founder-section.update.post');

        // Delete Specific Field from About Founder Section (Admin Only)
        Route::delete('/about-founder-section/{id}/field/{field}', [AboutFounderSectionController::class, 'deleteField'])
            ->name('api.v1.about-founder-section.delete-field');

        // Delete About Founder Section Content (Admin Only)
        Route::delete('/about-founder-section/{id}', [AboutFounderSectionController::class, 'destroy'])
            ->name('api.v1.about-founder-section.destroy');

        /*
        |--------------------------------------------------------------------------
        | About Company Section Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle about company section content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update About Company Section Content (Admin Only)
        Route::post('/about-company-section', [AboutCompanySectionController::class, 'store'])
            ->name('api.v1.about-company-section.store');

        // Update About Company Section Content (Admin Only)
        Route::put('/about-company-section/{id}', [AboutCompanySectionController::class, 'update'])
            ->name('api.v1.about-company-section.update');
        Route::patch('/about-company-section/{id}', [AboutCompanySectionController::class, 'update'])
            ->name('api.v1.about-company-section.update.patch');
        Route::post('/about-company-section/{id}', [AboutCompanySectionController::class, 'update'])
            ->name('api.v1.about-company-section.update.post');

        // Delete Specific Field from About Company Section (Admin Only)
        Route::delete('/about-company-section/{id}/field/{field}', [AboutCompanySectionController::class, 'deleteField'])
            ->name('api.v1.about-company-section.delete-field');

        // Delete About Company Section Content (Admin Only)
        Route::delete('/about-company-section/{id}', [AboutCompanySectionController::class, 'destroy'])
            ->name('api.v1.about-company-section.destroy');

        /*
        |--------------------------------------------------------------------------
        | Mission and Vision Section Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle mission and vision section content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update Mission and Vision Section Content (Admin Only)
        Route::post('/mission-vision-section', [MissionVisionSectionController::class, 'store'])
            ->name('api.v1.mission-vision-section.store');

        // Update Mission and Vision Section Content (Admin Only)
        Route::put('/mission-vision-section/{id}', [MissionVisionSectionController::class, 'update'])
            ->name('api.v1.mission-vision-section.update');
        Route::patch('/mission-vision-section/{id}', [MissionVisionSectionController::class, 'update'])
            ->name('api.v1.mission-vision-section.update.patch');
        Route::post('/mission-vision-section/{id}', [MissionVisionSectionController::class, 'update'])
            ->name('api.v1.mission-vision-section.update.post');

        // Delete Specific Field from Mission and Vision Section (Admin Only)
        Route::delete('/mission-vision-section/{id}/field/{field}', [MissionVisionSectionController::class, 'deleteField'])
            ->name('api.v1.mission-vision-section.delete-field');

        // Delete Mission and Vision Section Content (Admin Only)
        Route::delete('/mission-vision-section/{id}', [MissionVisionSectionController::class, 'destroy'])
            ->name('api.v1.mission-vision-section.destroy');

        /*
        |--------------------------------------------------------------------------
        | Core Values Section Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle core values section (section title & description).
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update Core Values Section (Admin Only)
        Route::post('/core-values-section', [CoreValuesSectionController::class, 'store'])
            ->name('api.v1.core-values-section.store');

        // Update Core Values Section (Admin Only)
        Route::put('/core-values-section/{id}', [CoreValuesSectionController::class, 'update'])
            ->name('api.v1.core-values-section.update');
        Route::patch('/core-values-section/{id}', [CoreValuesSectionController::class, 'update'])
            ->name('api.v1.core-values-section.update.patch');

        /*
        |--------------------------------------------------------------------------
        | Core Value Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle core value content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create Core Value (Admin Only)
        Route::post('/core-values', [CoreValueController::class, 'store'])
            ->name('api.v1.core-values.store');

        // Update Core Value Content (Admin Only)
        Route::put('/core-values/{id}', [CoreValueController::class, 'update'])
            ->name('api.v1.core-values.update');
        Route::patch('/core-values/{id}', [CoreValueController::class, 'update'])
            ->name('api.v1.core-values.update.patch');
        Route::post('/core-values/{id}', [CoreValueController::class, 'update'])
            ->name('api.v1.core-values.update.post');

        // Delete Core Value (Admin Only)
        Route::delete('/core-values/{id}', [CoreValueController::class, 'destroy'])
            ->name('api.v1.core-values.destroy');

        /*
        |--------------------------------------------------------------------------
        | FAQ Hero Section Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle FAQ hero section content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update FAQ Hero Section Content (Admin Only)
        Route::post('/faq-hero-section', [FAQHeroSectionController::class, 'store'])
            ->name('api.v1.faq-hero-section.store');

        // Update FAQ Hero Section Content (Admin Only)
        Route::put('/faq-hero-section/{id}', [FAQHeroSectionController::class, 'update'])
            ->name('api.v1.faq-hero-section.update');
        Route::patch('/faq-hero-section/{id}', [FAQHeroSectionController::class, 'update'])
            ->name('api.v1.faq-hero-section.update.patch');
        Route::post('/faq-hero-section/{id}', [FAQHeroSectionController::class, 'update'])
            ->name('api.v1.faq-hero-section.update.post');

        // Delete FAQ Hero Section Content (Admin Only)
        Route::delete('/faq-hero-section/{id}', [FAQHeroSectionController::class, 'destroy'])
            ->name('api.v1.faq-hero-section.destroy');

        /*
        |--------------------------------------------------------------------------
        | Career Page Hero Section Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle career page hero section content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update Career Page Hero Section Content (Admin Only)
        Route::post('/career-page-hero-section', [CareerPageHeroSectionController::class, 'store'])
            ->name('api.v1.career-page-hero-section.store');

        // Update Career Page Hero Section Content (Admin Only)
        Route::put('/career-page-hero-section/{id}', [CareerPageHeroSectionController::class, 'update'])
            ->name('api.v1.career-page-hero-section.update');
        Route::patch('/career-page-hero-section/{id}', [CareerPageHeroSectionController::class, 'update'])
            ->name('api.v1.career-page-hero-section.update.patch');
        Route::post('/career-page-hero-section/{id}', [CareerPageHeroSectionController::class, 'update'])
            ->name('api.v1.career-page-hero-section.update.post');

        // Delete Specific Field from Career Page Hero Section (Admin Only)
        Route::delete('/career-page-hero-section/{id}/field/{field}', [CareerPageHeroSectionController::class, 'deleteField'])
            ->name('api.v1.career-page-hero-section.delete-field');

        // Delete Career Page Hero Section Content (Admin Only)
        Route::delete('/career-page-hero-section/{id}', [CareerPageHeroSectionController::class, 'destroy'])
            ->name('api.v1.career-page-hero-section.destroy');

        /*
        |--------------------------------------------------------------------------
        | Career Cards Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle career cards content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create Career Card (Admin Only)
        Route::post('/career-cards', [CareerCardController::class, 'store'])
            ->name('api.v1.career-cards.store');

        // Update Career Card (Admin Only)
        Route::put('/career-cards/{id}', [CareerCardController::class, 'update'])
            ->name('api.v1.career-cards.update');
        Route::patch('/career-cards/{id}', [CareerCardController::class, 'update'])
            ->name('api.v1.career-cards.update.patch');
        Route::post('/career-cards/{id}', [CareerCardController::class, 'update'])
            ->name('api.v1.career-cards.update.post');

        // Delete Specific Field from Career Card (Admin Only)
        Route::delete('/career-cards/{id}/field/{field}', [CareerCardController::class, 'deleteField'])
            ->name('api.v1.career-cards.delete-field');

        // Delete Career Card (Admin Only)
        Route::delete('/career-cards/{id}', [CareerCardController::class, 'destroy'])
            ->name('api.v1.career-cards.destroy');

        /*
        |--------------------------------------------------------------------------
        | Job Sections Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle job sections content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create Job Section (Admin Only)
        Route::post('/job-sections', [JobSectionController::class, 'store'])
            ->name('api.v1.job-sections.store');

        // Update Job Section (Admin Only)
        Route::put('/job-sections/{id}', [JobSectionController::class, 'update'])
            ->name('api.v1.job-sections.update');
        Route::patch('/job-sections/{id}', [JobSectionController::class, 'update'])
            ->name('api.v1.job-sections.update.patch');
        Route::post('/job-sections/{id}', [JobSectionController::class, 'update'])
            ->name('api.v1.job-sections.update.post');

        // Delete Specific Field from Job Section (Admin Only)
        Route::delete('/job-sections/{id}/field/{field}', [JobSectionController::class, 'deleteField'])
            ->name('api.v1.job-sections.delete-field');

        // Delete Job Section (Admin Only)
        Route::delete('/job-sections/{id}', [JobSectionController::class, 'destroy'])
            ->name('api.v1.job-sections.destroy');

        /*
        |--------------------------------------------------------------------------
        | Career CTA Sections Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle career CTA section content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create Career CTA Section (Admin Only)
        Route::post('/career-cta-sections', [CareerCtaSectionController::class, 'store'])
            ->name('api.v1.career-cta-sections.store');

        // Update Career CTA Section (Admin Only)
        Route::put('/career-cta-sections/{id}', [CareerCtaSectionController::class, 'update'])
            ->name('api.v1.career-cta-sections.update');
        Route::patch('/career-cta-sections/{id}', [CareerCtaSectionController::class, 'update'])
            ->name('api.v1.career-cta-sections.update.patch');
        Route::post('/career-cta-sections/{id}', [CareerCtaSectionController::class, 'update'])
            ->name('api.v1.career-cta-sections.update.post');

        // Delete Career CTA Section (Admin Only)
        Route::delete('/career-cta-sections/{id}', [CareerCtaSectionController::class, 'destroy'])
            ->name('api.v1.career-cta-sections.destroy');

        /*
        |--------------------------------------------------------------------------
        | FAQ Sections Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle FAQ sections content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create FAQ Section (Admin Only)
        Route::post('/faq-sections', [FaqSectionController::class, 'store'])
            ->name('api.v1.faq-sections.store');

        // Update FAQ Section (Admin Only)
        Route::put('/faq-sections/{id}', [FaqSectionController::class, 'update'])
            ->name('api.v1.faq-sections.update');
        Route::patch('/faq-sections/{id}', [FaqSectionController::class, 'update'])
            ->name('api.v1.faq-sections.update.patch');
        Route::post('/faq-sections/{id}', [FaqSectionController::class, 'update'])
            ->name('api.v1.faq-sections.update.post');

        // Delete Specific Field from FAQ Section (Admin Only)
        Route::delete('/faq-sections/{id}/field/{field}', [FaqSectionController::class, 'deleteField'])
            ->name('api.v1.faq-sections.delete-field');

        // Delete FAQ Section (Admin Only)
        Route::delete('/faq-sections/{id}', [FaqSectionController::class, 'destroy'])
            ->name('api.v1.faq-sections.destroy');

        /*
        |--------------------------------------------------------------------------
        | Support Sections Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle support sections content management.
        | Accessible by super_admin and editor roles.
        | Note: GET routes are public and defined above.
        |
        */

        // Create Support Section (Admin Only)
        Route::post('/support-sections', [SupportSectionController::class, 'store'])
            ->name('api.v1.support-sections.store');

        // Update Support Section (Admin Only)
        Route::put('/support-sections/{id}', [SupportSectionController::class, 'update'])
            ->name('api.v1.support-sections.update');
        Route::patch('/support-sections/{id}', [SupportSectionController::class, 'update'])
            ->name('api.v1.support-sections.update.patch');
        Route::post('/support-sections/{id}', [SupportSectionController::class, 'update'])
            ->name('api.v1.support-sections.update.post');

        // Delete Specific Field from Support Section (Admin Only)
        Route::delete('/support-sections/{id}/field/{field}', [SupportSectionController::class, 'deleteField'])
            ->name('api.v1.support-sections.delete-field');

        // Delete Support Section (Admin Only)
        Route::delete('/support-sections/{id}', [SupportSectionController::class, 'destroy'])
            ->name('api.v1.support-sections.destroy');

        /*
        |--------------------------------------------------------------------------
        | Career Procedure Management Routes
        |--------------------------------------------------------------------------
        */

        // Create Career Procedure (Admin Only)
        Route::post('/career-procedures', [CareerProcedureController::class, 'store'])
            ->name('api.v1.career-procedures.store');

        // Update Career Procedure (Admin Only)
        Route::put('/career-procedures/{id}', [CareerProcedureController::class, 'update'])
            ->name('api.v1.career-procedures.update');
        Route::patch('/career-procedures/{id}', [CareerProcedureController::class, 'update'])
            ->name('api.v1.career-procedures.update.patch');
        Route::post('/career-procedures/{id}', [CareerProcedureController::class, 'update'])
            ->name('api.v1.career-procedures.update.post');

        // Delete Specific Field from Career Procedure (Admin Only)
        Route::delete('/career-procedures/{id}/field/{field}', [CareerProcedureController::class, 'deleteField'])
            ->name('api.v1.career-procedures.delete-field');

        // Delete Career Procedure (Admin Only)
        Route::delete('/career-procedures/{id}', [CareerProcedureController::class, 'destroy'])
            ->name('api.v1.career-procedures.destroy');

        /*
        |--------------------------------------------------------------------------
        | Contact Form Submissions Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle contact form submissions management.
        | Public submission endpoint and admin management endpoints.
        |
        */

        // Get Contact Form Submissions (Admin Only)
        Route::get('/contact-form-submissions', [ContactFormSubmissionController::class, 'index'])
            ->name('api.v1.contact-form-submissions.index');

        // Get Contact Form Statistics (Admin Only)
        Route::get('/contact-form-submissions/stats', [ContactFormSubmissionController::class, 'getStats'])
            ->name('api.v1.contact-form-submissions.stats');

        // Get Specific Contact Form Submission (Admin Only)
        Route::get('/contact-form-submissions/{id}', [ContactFormSubmissionController::class, 'show'])
            ->name('api.v1.contact-form-submissions.show');

        // Mark Contact Form Submission as Read (Admin Only)
        Route::post('/contact-form-submissions/{id}/mark-read', [ContactFormSubmissionController::class, 'markAsRead'])
            ->name('api.v1.contact-form-submissions.mark-read');

        // Mark Contact Form Submission as Unread (Admin Only)
        Route::post('/contact-form-submissions/{id}/mark-unread', [ContactFormSubmissionController::class, 'markAsUnread'])
            ->name('api.v1.contact-form-submissions.mark-unread');

        // Delete Contact Form Submission (Admin Only)
        Route::delete('/contact-form-submissions/{id}', [ContactFormSubmissionController::class, 'destroy'])
            ->name('api.v1.contact-form-submissions.destroy');

        /*
        |--------------------------------------------------------------------------
        | Career Support Form Submissions Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle career support form submissions management.
        | Public submission endpoint and admin management endpoints.
        |
        */

        // Get Career Support Form Submissions (Admin Only)
        Route::get('/career-support-form-submissions', [CareerSupportFormSubmissionController::class, 'index'])
            ->name('api.v1.career-support-form-submissions.index');

        // Get Career Support Form Statistics (Admin Only)
        Route::get('/career-support-form-submissions/stats', [CareerSupportFormSubmissionController::class, 'getStats'])
            ->name('api.v1.career-support-form-submissions.stats');

        // Get Specific Career Support Form Submission (Admin Only)
        Route::get('/career-support-form-submissions/{id}', [CareerSupportFormSubmissionController::class, 'show'])
            ->name('api.v1.career-support-form-submissions.show');

        // Mark Career Support Form Submission as Read (Admin Only)
        Route::post('/career-support-form-submissions/{id}/mark-read', [CareerSupportFormSubmissionController::class, 'markAsRead'])
            ->name('api.v1.career-support-form-submissions.mark-read');

        // Mark Career Support Form Submission as Unread (Admin Only)
        Route::post('/career-support-form-submissions/{id}/mark-unread', [CareerSupportFormSubmissionController::class, 'markAsUnread'])
            ->name('api.v1.career-support-form-submissions.mark-unread');

        // Delete Career Support Form Submission (Admin Only)
        Route::delete('/career-support-form-submissions/{id}', [CareerSupportFormSubmissionController::class, 'destroy'])
            ->name('api.v1.career-support-form-submissions.destroy');

        /*
        |--------------------------------------------------------------------------
        | Quote Form Submissions Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle quote form submissions management.
        | Public submission endpoint and admin management endpoints.
        |
        */

        // Get Quote Form Submissions (Admin Only)
        Route::get('/quote-form-submissions', [QuoteFormSubmissionController::class, 'index'])
            ->name('api.v1.quote-form-submissions.index');

        // Get Quote Form Statistics (Admin Only)
        Route::get('/quote-form-submissions/stats', [QuoteFormSubmissionController::class, 'getStats'])
            ->name('api.v1.quote-form-submissions.stats');

        // Get Specific Quote Form Submission (Admin Only)
        Route::get('/quote-form-submissions/{id}', [QuoteFormSubmissionController::class, 'show'])
            ->name('api.v1.quote-form-submissions.show');

        // Mark Quote Form Submission as Read (Admin Only)
        Route::post('/quote-form-submissions/{id}/mark-read', [QuoteFormSubmissionController::class, 'markAsRead'])
            ->name('api.v1.quote-form-submissions.mark-read');

        // Mark Quote Form Submission as Unread (Admin Only)
        Route::post('/quote-form-submissions/{id}/mark-unread', [QuoteFormSubmissionController::class, 'markAsUnread'])
            ->name('api.v1.quote-form-submissions.mark-unread');

        // Delete Quote Form Submission (Admin Only)
        Route::delete('/quote-form-submissions/{id}', [QuoteFormSubmissionController::class, 'destroy'])
            ->name('api.v1.quote-form-submissions.destroy');

        /*
        |--------------------------------------------------------------------------
        | Schedule Form Submissions Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle schedule form submissions management.
        | Admin management endpoints for viewing schedule/consultation requests.
        |
        */

        // Get Schedule Form Submissions (Admin Only)
        Route::get('/schedule-form-submissions', [ScheduleFormSubmissionController::class, 'index'])
            ->name('api.v1.schedule-form-submissions.index');

        // Get Schedule Form Statistics (Admin Only)
        Route::get('/schedule-form-submissions/stats', [ScheduleFormSubmissionController::class, 'getStats'])
            ->name('api.v1.schedule-form-submissions.stats');

        // Get Specific Schedule Form Submission (Admin Only)
        Route::get('/schedule-form-submissions/{id}', [ScheduleFormSubmissionController::class, 'show'])
            ->name('api.v1.schedule-form-submissions.show');

        // Mark Schedule Form Submission as Read (Admin Only)
        Route::post('/schedule-form-submissions/{id}/mark-read', [ScheduleFormSubmissionController::class, 'markAsRead'])
            ->name('api.v1.schedule-form-submissions.mark-read');

        // Mark Schedule Form Submission as Unread (Admin Only)
        Route::post('/schedule-form-submissions/{id}/mark-unread', [ScheduleFormSubmissionController::class, 'markAsUnread'])
            ->name('api.v1.schedule-form-submissions.mark-unread');

        // Delete Schedule Form Submission (Admin Only)
        Route::delete('/schedule-form-submissions/{id}', [ScheduleFormSubmissionController::class, 'destroy'])
            ->name('api.v1.schedule-form-submissions.destroy');

        /*
        |--------------------------------------------------------------------------
        | Team Members Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle team members management.
        | Only admins can manage team members.
        | Note: GET routes are public and defined above.
        |
        */

        // Create Team Member (Admin Only)
        Route::post('/team-members', [TeamMemberController::class, 'store'])
            ->name('api.v1.team-members.store');

        // Update Team Member (Admin Only)
        Route::put('/team-members/{id}', [TeamMemberController::class, 'update'])
            ->name('api.v1.team-members.update');
        Route::patch('/team-members/{id}', [TeamMemberController::class, 'update'])
            ->name('api.v1.team-members.update.patch');
        Route::post('/team-members/{id}', [TeamMemberController::class, 'update'])
            ->name('api.v1.team-members.update.post');

        // Delete Specific Field from Team Member (Admin Only)
        Route::delete('/team-members/{id}/field/{field}', [TeamMemberController::class, 'deleteField'])
            ->name('api.v1.team-members.delete-field');

        // Delete Team Member (Admin Only)
        Route::delete('/team-members/{id}', [TeamMemberController::class, 'destroy'])
            ->name('api.v1.team-members.destroy');

        /*
        |--------------------------------------------------------------------------
        | Contact Page Hero Section Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle the contact page hero section management.
        | Only admins can manage these sections.
        | Note: GET routes are public and defined above.
        |
        */

        // Create Contact Page Hero Section (Admin Only)
        Route::post('/contact-page-hero-sections', [ContactPageHeroSectionController::class, 'store'])
            ->name('api.v1.contact-page-hero-sections.store');

        // Update Contact Page Hero Section (Admin Only)
        Route::put('/contact-page-hero-sections/{id}', [ContactPageHeroSectionController::class, 'update'])
            ->name('api.v1.contact-page-hero-sections.update');

        // Update Contact Page Hero Section via POST (Admin Only)
        Route::post('/contact-page-hero-sections/{id}/update', [ContactPageHeroSectionController::class, 'update'])
            ->name('api.v1.contact-page-hero-sections.update.post');

        // Delete Contact Page Hero Section (Admin Only)
        Route::delete('/contact-page-hero-sections/{id}', [ContactPageHeroSectionController::class, 'destroy'])
            ->name('api.v1.contact-page-hero-sections.destroy');

        /*
        |--------------------------------------------------------------------------
        | Contact Page Cards Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle the contact page cards management.
        | Only admins can manage these cards.
        | Note: GET routes are public and defined above.
        |
        */

        // Create Contact Page Card (Admin Only)
        Route::post('/contact-page-cards', [ContactCardController::class, 'store'])
            ->name('api.v1.contact-page-cards.store');

        // Update Contact Page Card (Admin Only)
        Route::put('/contact-page-cards/{id}', [ContactCardController::class, 'update'])
            ->name('api.v1.contact-page-cards.update');

        // Update Contact Page Card via POST (Admin Only)
        Route::post('/contact-page-cards/{id}/update', [ContactCardController::class, 'update'])
            ->name('api.v1.contact-page-cards.update.post');

        // Delete Contact Page Card (Admin Only)
        Route::delete('/contact-page-cards/{id}', [ContactCardController::class, 'destroy'])
            ->name('api.v1.contact-page-cards.destroy');

        /*
        |--------------------------------------------------------------------------
        | Hours of Operation Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle the hours of operation management.
        | Only admins can manage these hours.
        | Note: GET routes are public and defined above.
        |
        */

        // Create Hours of Operation (Admin Only)
        Route::post('/hours-of-operation', [HoursOfOperationController::class, 'store'])
            ->name('api.v1.hours-of-operation.store');

        // Update Hours of Operation (Admin Only)
        Route::put('/hours-of-operation/{id}', [HoursOfOperationController::class, 'update'])
            ->name('api.v1.hours-of-operation.update');

        // Update Hours of Operation via POST (Admin Only)
        Route::post('/hours-of-operation/{id}/update', [HoursOfOperationController::class, 'update'])
            ->name('api.v1.hours-of-operation.update.post');

        // Delete Hours of Operation (Admin Only)
        Route::delete('/hours-of-operation/{id}', [HoursOfOperationController::class, 'destroy'])
            ->name('api.v1.hours-of-operation.destroy');

        /*
        |--------------------------------------------------------------------------
        | Social Media Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle the social media section and links management.
        | Only admins can manage these.
        | Note: GET routes are public and defined above.
        |
        */

        // Create or Update Social Media Section (Admin Only)
        Route::post('/social-media/section', [SocialLinkController::class, 'storeSection'])
            ->name('api.v1.social-media.section.store');

        // Create Social Link (Admin Only)
        Route::post('/social-links', [SocialLinkController::class, 'store'])
            ->name('api.v1.social-links.store');

        // Update Social Link (Admin Only)
        Route::put('/social-links/{id}', [SocialLinkController::class, 'update'])
            ->name('api.v1.social-links.update');

        // Update Social Link via POST (Admin Only)
        Route::post('/social-links/{id}/update', [SocialLinkController::class, 'update'])
            ->name('api.v1.social-links.update.post');

        // Delete Social Link (Admin Only)
        Route::delete('/social-links/{id}', [SocialLinkController::class, 'destroy'])
            ->name('api.v1.social-links.destroy');

        /*
        |--------------------------------------------------------------------------
        | Footer Management Routes
        |--------------------------------------------------------------------------
        |
        | Save full footer content. Accessible by super_admin and editor roles.
        |
        */

        // Save Footer (Admin Only)
        Route::post('/footer', [FooterController::class, 'store'])
            ->name('api.v1.footer.store');
        Route::put('/footer', [FooterController::class, 'store'])
            ->name('api.v1.footer.store.put');

        // Upload payment method icon image (Admin Only)
        Route::post('/footer/payment-icons/upload', [FooterController::class, 'uploadPaymentIcon'])
            ->name('api.v1.footer.payment-icons.upload');

        // Delete single footer link (Admin Only)
        Route::delete('/footer/links/{id}', [FooterController::class, 'destroyLink'])
            ->name('api.v1.footer.links.destroy');

        // Delete single footer payment method (Admin Only)
        Route::delete('/footer/payment-methods/{id}', [FooterController::class, 'destroyPaymentMethod'])
            ->name('api.v1.footer.payment-methods.destroy');

        // Update footer by ID (Admin Only)
        Route::put('/footer/{id}', [FooterController::class, 'update'])
            ->name('api.v1.footer.update');
        Route::patch('/footer/{id}', [FooterController::class, 'update'])
            ->name('api.v1.footer.update.patch');

        // Delete single field from footer content (Admin Only)
        Route::delete('/footer/{id}/field/{field}', [FooterController::class, 'deleteField'])
            ->name('api.v1.footer.delete-field');

        // Delete footer (Admin Only)
        Route::delete('/footer/{id}', [FooterController::class, 'destroy'])
            ->name('api.v1.footer.destroy');

        /*
        |--------------------------------------------------------------------------
        | Site Settings Management Routes
        |--------------------------------------------------------------------------
        */

        // Save Site Settings (Admin Only)
        Route::post('/site-settings', [SiteSettingController::class, 'store'])
            ->name('api.v1.site-settings.store');
        Route::put('/site-settings', [SiteSettingController::class, 'store'])
            ->name('api.v1.site-settings.store.put');

        // Delete single header link (Admin Only)
        Route::delete('/site-settings/links/{id}', [SiteSettingController::class, 'destroyLink'])
            ->name('api.v1.site-settings.links.destroy');

        // Update site settings by ID (Admin Only)
        Route::put('/site-settings/{id}', [SiteSettingController::class, 'update'])
            ->name('api.v1.site-settings.update');
        Route::patch('/site-settings/{id}', [SiteSettingController::class, 'update'])
            ->name('api.v1.site-settings.update.patch');

        // Delete single field from site settings (Admin Only)
        Route::delete('/site-settings/{id}/field/{field}', [SiteSettingController::class, 'deleteField'])
            ->name('api.v1.site-settings.delete-field');

        // Delete site settings (Admin Only)
        Route::delete('/site-settings/{id}', [SiteSettingController::class, 'destroy'])
            ->name('api.v1.site-settings.destroy');

        // Notification settings routes (Admin Only)
        Route::get('/sms-settings', [\App\Http\Controllers\Api\Admin\sitesettings\NotificationSettingsController::class, 'getSmsSettings'])
            ->name('api.v1.sms-settings.index');
        Route::post('/sms-settings', [\App\Http\Controllers\Api\Admin\sitesettings\NotificationSettingsController::class, 'saveSmsSettings'])
            ->name('api.v1.sms-settings.store');
        Route::post('/sms-settings/test', [\App\Http\Controllers\Api\Admin\sitesettings\NotificationSettingsController::class, 'testSms'])
            ->name('api.v1.sms-settings.test');
        Route::get('/push-notification-settings', [\App\Http\Controllers\Api\Admin\sitesettings\NotificationSettingsController::class, 'getPushSettings'])
            ->name('api.v1.push-notification-settings.index');
        Route::post('/push-notification-settings', [\App\Http\Controllers\Api\Admin\sitesettings\NotificationSettingsController::class, 'savePushSettings'])
            ->name('api.v1.push-notification-settings.store');

        // Shipping Zones & Rates routes (Admin Only)
        Route::apiResource('/shipping-zones', \App\Http\Controllers\Api\Admin\ShippingZoneController::class);
        Route::apiResource('/shipping-rates', \App\Http\Controllers\Api\Admin\ShippingRateController::class);

        /*
        |--------------------------------------------------------------------------
        | FAQ Intro Paragraph Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle FAQ intro paragraph content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update FAQ Intro Paragraph Content (Admin Only)
        Route::post('/faq-intro-paragraph', [FAQIntroParagraphController::class, 'store'])
            ->name('api.v1.faq-intro-paragraph.store');

        // Update FAQ Intro Paragraph Content (Admin Only)
        Route::put('/faq-intro-paragraph/{id}', [FAQIntroParagraphController::class, 'update'])
            ->name('api.v1.faq-intro-paragraph.update');
        Route::patch('/faq-intro-paragraph/{id}', [FAQIntroParagraphController::class, 'update'])
            ->name('api.v1.faq-intro-paragraph.update.patch');
        Route::post('/faq-intro-paragraph/{id}', [FAQIntroParagraphController::class, 'update'])
            ->name('api.v1.faq-intro-paragraph.update.post');

        // Delete FAQ Intro Paragraph Content (Admin Only)
        Route::delete('/faq-intro-paragraph/{id}', [FAQIntroParagraphController::class, 'destroy'])
            ->name('api.v1.faq-intro-paragraph.destroy');

        /*
        |--------------------------------------------------------------------------
        | FAQ Category Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle FAQ category content management.
        | Accessible by super_admin and editor roles.
        |
        */

        // Create FAQ Category (Admin Only)
        Route::post('/faq-categories', [FAQCategoryController::class, 'store'])
            ->name('api.v1.faq-categories.store');

        // Update FAQ Category Content (Admin Only)
        Route::put('/faq-categories/{id}', [FAQCategoryController::class, 'update'])
            ->name('api.v1.faq-categories.update');
        Route::patch('/faq-categories/{id}', [FAQCategoryController::class, 'update'])
            ->name('api.v1.faq-categories.update.patch');
        Route::post('/faq-categories/{id}', [FAQCategoryController::class, 'update'])
            ->name('api.v1.faq-categories.update.post');

        // Delete FAQ Category (Admin Only)
        Route::delete('/faq-categories/{id}', [FAQCategoryController::class, 'destroy'])
            ->name('api.v1.faq-categories.destroy');

        /*
        |--------------------------------------------------------------------------
        | FAQ Item Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle FAQ item content management.
        | Accessible by super_admin and editor roles.
        | Note: FAQ categories must be created first before creating FAQ items.
        |
        */

        // Create FAQ Item (Admin Only)
        Route::post('/faq-items', [FAQItemController::class, 'store'])
            ->name('api.v1.faq-items.store');

        // Update FAQ Item Content (Admin Only)
        Route::put('/faq-items/{id}', [FAQItemController::class, 'update'])
            ->name('api.v1.faq-items.update');
        Route::patch('/faq-items/{id}', [FAQItemController::class, 'update'])
            ->name('api.v1.faq-items.update.patch');
        Route::post('/faq-items/{id}', [FAQItemController::class, 'update'])
            ->name('api.v1.faq-items.update.post');

        // Delete FAQ Item (Admin Only)
        Route::delete('/faq-items/{id}', [FAQItemController::class, 'destroy'])
            ->name('api.v1.faq-items.destroy');

        /*
        |--------------------------------------------------------------------------
        | FAQ Ask Question Section Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle the ask question form section (heading & description).
        | Accessible by super_admin and editor roles.
        |
        */

        // Create or Update FAQ Ask Question Section (Admin Only)
        Route::post('/faq-ask-question-section', [FaqAskQuestionSectionController::class, 'store'])
            ->name('api.v1.faq-ask-question-section.store');

        // Update FAQ Ask Question Section (Admin Only)
        Route::put('/faq-ask-question-section/{id}', [FaqAskQuestionSectionController::class, 'update'])
            ->name('api.v1.faq-ask-question-section.update');
        Route::patch('/faq-ask-question-section/{id}', [FaqAskQuestionSectionController::class, 'update'])
            ->name('api.v1.faq-ask-question-section.update.patch');

        /*
        |--------------------------------------------------------------------------
        | User Submitted Questions Management Routes
        |--------------------------------------------------------------------------
        |
        | These routes handle user submitted questions management.
        | Accessible by super_admin and editor roles.
        | Note: Users can submit questions via public POST endpoint (no auth required).
        |
        */

        // Update User Submitted Question Status (Admin Only)
        Route::put('/user-submitted-questions/{id}', [UserSubmittedQuestionController::class, 'update'])
            ->name('api.v1.user-submitted-questions.update');
        Route::patch('/user-submitted-questions/{id}', [UserSubmittedQuestionController::class, 'update'])
            ->name('api.v1.user-submitted-questions.update.patch');
        Route::post('/user-submitted-questions/{id}', [UserSubmittedQuestionController::class, 'update'])
            ->name('api.v1.user-submitted-questions.update.post');

        // Delete User Submitted Question (Admin Only)
        Route::delete('/user-submitted-questions/{id}', [UserSubmittedQuestionController::class, 'destroy'])
            ->name('api.v1.user-submitted-questions.destroy');

        // Contact Page Hero Section Routes
        Route::apiResource(
            'contact-page-hero-section',
            ContactPageHeroSectionController::class
        );

    }); // End of auth:sanctum middleware group

    /*
    |--------------------------------------------------------------------------
    | Additional Authenticated Admin Routes
    |--------------------------------------------------------------------------
    |
    | These routes were previously declared outside the authenticated group,
    | which left them publicly accessible. Keep them protected here so the
    | frontend can safely wire against them.
    |
    */
    Route::middleware('central.auth')->group(function () {

        // Page SEO Settings (Admin Only)
        Route::post('/page-seo-settings/{pageSlug}', [PageSeoSettingController::class, 'upsert'])
            ->name('api.v1.page-seo-settings.upsert');

        // Product Page Hero Section (Admin Only)
        Route::post('/product-page-hero-section', [ProductPageHeroSectionController::class, 'store'])
            ->name('api.v1.product-page-hero-section.store');

        // Product Tabs Management (Admin Only)
        Route::post('/product-tabs', [ProductTabController::class, 'store'])
            ->name('api.v1.product-tabs.store');
        Route::put('/product-tabs/{id}', [ProductTabController::class, 'update'])
            ->name('api.v1.product-tabs.update');
        Route::delete('/product-tabs/{id}', [ProductTabController::class, 'destroy'])
            ->name('api.v1.product-tabs.destroy');

        // Product Items Management (Admin Only)
        Route::post('/product-items', [ProductItemController::class, 'store'])
            ->name('api.v1.product-items.store');
        Route::put('/product-items/{id}', [ProductItemController::class, 'update'])
            ->name('api.v1.product-items.update');
        Route::delete('/product-items/{id}', [ProductItemController::class, 'destroy'])
            ->name('api.v1.product-items.destroy');

        // Product Page Slides Management (Admin Only)
        Route::post('/product-page-slide', [ProductPageSlideController::class, 'store'])
            ->name('api.v1.product-page-slide.store');
        Route::put('/product-page-slide/{id}', [ProductPageSlideController::class, 'update'])
            ->name('api.v1.product-page-slide.update');
        Route::post('/product-page-slide/{id}', [ProductPageSlideController::class, 'update'])
            ->name('api.v1.product-page-slide.update.post');
        Route::delete('/product-page-slide/{id}', [ProductPageSlideController::class, 'destroy'])
            ->name('api.v1.product-page-slide.destroy');
        Route::delete('/product-page-slide/{id}/field/{field}', [ProductPageSlideController::class, 'deleteField'])
            ->name('api.v1.product-page-slide.delete-field');

        // Service How It Works Section (Admin Only)
        Route::post('/service-how-it-works-section', [ServiceHowItWorksSectionController::class, 'store'])
            ->name('api.v1.service-how-it-works-section.store');

        // Categories Management (Admin Only)
        Route::apiResource('categories', CategoryController::class);

        // Products Management (Admin Only)
        Route::apiResource('products', ProductController::class);
        Route::apiResource('product-colors', ProductColorController::class);
        Route::apiResource('product-labels', ProductLabelController::class);
        Route::apiResource('brands', ProductBrandController::class);
        Route::apiResource('product-reports', ProductReportController::class)->except(['store']);
        Route::get('/products/{product}/detail-data', [ProductDetailDataController::class, 'show'])
            ->name('api.v1.products.detail-data.show');
        Route::put('/products/{product}/detail-data', [ProductDetailDataController::class, 'update'])
            ->name('api.v1.products.detail-data.update');

        // Policy Sections Management (Admin Only)
        Route::get('/policy-sections/admin', [PolicySectionController::class, 'adminIndex'])
            ->name('api.v1.policy-sections.admin-index');
        Route::post('/policy-sections', [PolicySectionController::class, 'store'])
            ->name('api.v1.policy-sections.store');
        Route::put('/policy-sections/{id}', [PolicySectionController::class, 'update'])
            ->name('api.v1.policy-sections.update');
        Route::post('/policy-sections/{id}', [PolicySectionController::class, 'update'])
            ->name('api.v1.policy-sections.update.post');
        Route::delete('/policy-sections/{id}', [PolicySectionController::class, 'destroy'])
            ->name('api.v1.policy-sections.destroy');

        // Section Labels Management (Admin Only)
        Route::post('/section-labels', [SectionLabelController::class, 'store'])
            ->name('api.v1.section-labels.store');


    });

}); // End of v1 prefix group

require base_path('routes/ecommerce.php');

// Admin Returns & Refund Approval API Routes (Direct alias)
Route::prefix('admin/returns')->middleware(['central.auth:optional'])->group(function () {
    Route::get('/stats', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'stats']);
    Route::get('/', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'index']);
    Route::get('/{return}', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'show']);
    Route::put('/{return}/status', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'updateStatus']);
    Route::post('/{return}/approve', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'approve']);
    Route::post('/{return}/decline', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'decline']);
    Route::post('/{return}/request-info', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'requestInfo']);
    Route::put('/{return}/note', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'updateNote']);
});

Route::prefix('admin/refunds')->middleware(['central.auth:optional'])->group(function () {
    Route::get('/stats', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'stats']);
    Route::get('/settings', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'getSettings']);
    Route::post('/settings', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'saveSettings']);
    Route::get('/', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'index']);
    Route::get('/{return}', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'show']);
    Route::put('/{return}/status', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'updateStatus']);
    Route::post('/{return}/approve', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'approve']);
    Route::post('/{return}/decline', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'decline']);
    Route::post('/{return}/request-info', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'requestInfo']);
    Route::put('/{return}/note', [\App\Http\Controllers\Api\Admin\AdminReturnController::class, 'updateNote']);
});


