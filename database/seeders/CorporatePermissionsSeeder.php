<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use App\Models\User;

class CorporatePermissionsSeeder extends Seeder
{
    /**
     * Run the corporate permissions and roles database seeder.
     * Version 5.0 - Corporate Permission Structure (25 Modules)
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // 25 Modules and their corporate permissions
        $modules = [
            [
                'module_id' => 'user-management',
                'module_name' => 'User Management',
                'permissions' => [
                    ['id' => 'view-users', 'name' => 'View Users', 'desc' => 'View staff/admin user records and account status.'],
                    ['id' => 'add-edit-users', 'name' => 'Add / Edit Users', 'desc' => 'Create or invite new admin/staff users and edit profile, department, contact details, and account information.'],
                    ['id' => 'activate-suspend-users', 'name' => 'Activate / Suspend Users', 'desc' => 'Activate, deactivate, suspend, or reactivate staff access.'],
                    ['id' => 'delete-users', 'name' => 'Delete Users', 'desc' => 'Permanently remove eligible user accounts.'],
                    ['id' => 'reset-password-security', 'name' => 'Reset Password / Security', 'desc' => 'Trigger password reset, unlock an account, or reset authentication methods.'],
                    ['id' => 'view-roles', 'name' => 'View Roles', 'desc' => 'View available roles and their assigned permissions.'],
                    ['id' => 'add-edit-roles', 'name' => 'Add / Edit Roles', 'desc' => 'Create new custom roles, rename existing roles, and change their permission assignments.'],
                    ['id' => 'delete-roles', 'name' => 'Delete Roles', 'desc' => 'Delete an unused custom role.'],
                    ['id' => 'assign-remove-roles', 'name' => 'Assign / Remove Roles', 'desc' => 'Assign or remove roles from staff users.'],
                    ['id' => 'manage-permission-matrix', 'name' => 'Manage Permission Matrix', 'desc' => 'Enable or disable permissions for roles.'],
                    ['id' => 'view-staff-activity-log', 'name' => 'View Staff Activity Log', 'desc' => 'View staff sign-ins and administrative activity history.'],
                ],
            ],
            [
                'module_id' => 'customer-management',
                'module_name' => 'Customer Management',
                'permissions' => [
                    ['id' => 'view-customers', 'name' => 'View Customers', 'desc' => 'View customer profiles, account status, verification status, and related account information.'],
                    ['id' => 'manage-customers', 'name' => 'Manage Customers', 'desc' => 'Create and edit customer accounts, addresses, internal notes, and account status.'],
                    ['id' => 'manage-customer-verification', 'name' => 'Manage Customer Verification', 'desc' => 'Review submitted verification information and update verification status.'],
                    ['id' => 'delete-customers', 'name' => 'Delete Customers', 'desc' => 'Delete or anonymize eligible customer records according to policy.'],
                ],
            ],
            [
                'module_id' => 'business-management',
                'module_name' => 'Business Management',
                'permissions' => [
                    ['id' => 'view-business-accounts', 'name' => 'View Business Accounts', 'desc' => 'View business profiles, contacts, verification, account status, and commercial details.'],
                    ['id' => 'manage-business-accounts', 'name' => 'Manage Business Accounts', 'desc' => 'Create and edit business accounts, contacts, pricing, tax status, and account terms.'],
                    ['id' => 'manage-business-verification', 'name' => 'Manage Business Verification', 'desc' => 'Review business verification submissions and approve or decline eligible accounts.'],
                    ['id' => 'delete-close-business-accounts', 'name' => 'Delete / Close Business Accounts', 'desc' => 'Close or delete eligible business accounts according to policy.'],
                ],
            ],
            [
                'module_id' => 'products-management',
                'module_name' => 'Products Management',
                'permissions' => [
                    ['id' => 'view-products', 'name' => 'View Products', 'desc' => 'View product catalog, variants, pricing, inventory, reviews, questions, and reported-product information.'],
                    ['id' => 'manage-products', 'name' => 'Manage Products', 'desc' => 'Create and edit products, variants, categories, brands, pricing, inventory, media, SEO, and customization settings.'],
                    ['id' => 'publish-unpublish-products', 'name' => 'Publish / Unpublish Products', 'desc' => 'Control whether eligible products are visible on the storefront.'],
                    ['id' => 'manage-product-reviews-questions', 'name' => 'Manage Product Reviews & Questions', 'desc' => 'Moderate product reviews, customer questions, and reported-product records.'],
                    ['id' => 'delete-products', 'name' => 'Delete Products', 'desc' => 'Archive or permanently delete eligible products.'],
                ],
            ],
            [
                'module_id' => 'orders-management',
                'module_name' => 'Orders Management',
                'permissions' => [
                    ['id' => 'view-orders', 'name' => 'View Orders', 'desc' => 'View orders, proofs, verification, disputes, returns, refunds, replacements, fulfillment, and shipping information.'],
                    ['id' => 'manage-orders', 'name' => 'Manage Orders', 'desc' => 'Create/edit eligible orders, update status, assign staff, and manage fulfillment, pickup, courier, and tracking details.'],
                    ['id' => 'manage-order-proofs-verification', 'name' => 'Manage Order Proofs & Verification', 'desc' => 'Handle proof workflows and review order-verification submissions.'],
                    ['id' => 'manage-returns-replacements', 'name' => 'Manage Returns & Replacements', 'desc' => 'Review and process eligible return and replacement requests.'],
                    ['id' => 'manage-refunds', 'name' => 'Manage Refunds', 'desc' => 'Review and process authorized refund requests.'],
                    ['id' => 'manage-order-disputes', 'name' => 'Manage Order Disputes', 'desc' => 'Review, respond to, and resolve eligible order disputes.'],
                    ['id' => 'cancel-delete-orders', 'name' => 'Cancel / Delete Orders', 'desc' => 'Cancel eligible orders and delete records only where business rules permit.'],
                ],
            ],
            [
                'module_id' => 'quotation-management',
                'module_name' => 'Quotation Management',
                'permissions' => [
                    ['id' => 'view-quotations', 'name' => 'View Quotations', 'desc' => 'View quotation requests, quotation products, customer requirements, pricing, and status.'],
                    ['id' => 'manage-quotations', 'name' => 'Manage Quotations', 'desc' => 'Create, edit, assign, price, revise, and update quotation records.'],
                    ['id' => 'send-quotations', 'name' => 'Send Quotations', 'desc' => 'Deliver completed quotations to customers and record delivery status.'],
                    ['id' => 'delete-quotations', 'name' => 'Delete Quotations', 'desc' => 'Archive or delete eligible quotation records.'],
                ],
            ],
            [
                'module_id' => 'contracts-proposals',
                'module_name' => 'Contracts & Proposals Management',
                'permissions' => [
                    ['id' => 'view-contracts-proposals', 'name' => 'View Contracts & Proposals', 'desc' => 'View contracts, proposals, customer details, terms, values, and status.'],
                    ['id' => 'manage-contracts-proposals', 'name' => 'Manage Contracts & Proposals', 'desc' => 'Create/edit documents, templates, line items, pricing, ownership, revisions, and expiration.'],
                    ['id' => 'approve-contracts-proposals', 'name' => 'Approve Contracts & Proposals', 'desc' => 'Perform required internal approval before customer delivery.'],
                    ['id' => 'send-manage-signatures', 'name' => 'Send & Manage Signatures', 'desc' => 'Send documents to customers and manage supported e-signature status.'],
                    ['id' => 'delete-contracts-proposals', 'name' => 'Delete Contracts & Proposals', 'desc' => 'Archive or delete eligible documents.'],
                ],
            ],
            [
                'module_id' => 'support-management',
                'module_name' => 'Support Management',
                'permissions' => [
                    ['id' => 'view-support', 'name' => 'View Support', 'desc' => 'View permitted support tickets, messages, live-chat conversations, and dispute-center records.'],
                    ['id' => 'manage-support-tickets', 'name' => 'Manage Support Tickets', 'desc' => 'Create, edit, assign, reply to, prioritize, close, reopen, and manage internal notes or attachments for support tickets.'],
                    ['id' => 'manage-messages', 'name' => 'Manage Messages', 'desc' => 'Read, reply to, assign, close, and manage customer message conversations.'],
                    ['id' => 'manage-live-chat', 'name' => 'Manage Live Chat', 'desc' => 'Handle live-chat conversations, assignments, status, and permitted chat actions.'],
                    ['id' => 'manage-dispute-center', 'name' => 'Manage Dispute Center', 'desc' => 'Review, assign, respond to, update, and resolve dispute-center cases.'],
                    ['id' => 'delete-support-records', 'name' => 'Delete Support Records', 'desc' => 'Delete eligible support tickets, conversations, chats, or dispute records where policy permits.'],
                ],
            ],
            [
                'module_id' => 'marketing-management',
                'module_name' => 'Marketing Management',
                'permissions' => [
                    ['id' => 'view-marketing', 'name' => 'View Marketing', 'desc' => 'View SMS, email, and push-notification campaigns, coupons, deals, and campaign status.'],
                    ['id' => 'manage-sms-campaigns', 'name' => 'Manage SMS Campaigns', 'desc' => 'Create, edit, schedule, audience-target, and manage SMS campaigns.'],
                    ['id' => 'manage-email-campaigns', 'name' => 'Manage Email Campaigns', 'desc' => 'Create, edit, schedule, audience-target, and manage email campaigns.'],
                    ['id' => 'manage-push-campaigns', 'name' => 'Manage Push Notification Campaigns', 'desc' => 'Create, edit, schedule, audience-target, and manage push-notification campaigns.'],
                    ['id' => 'manage-coupons', 'name' => 'Manage Coupons', 'desc' => 'Create and edit coupon rules, values, eligibility, limits, and validity periods.'],
                    ['id' => 'manage-deals', 'name' => 'Manage Deals', 'desc' => 'Create and edit promotional deals, eligibility, pricing rules, and validity periods.'],
                    ['id' => 'publish-unpublish-marketing', 'name' => 'Publish / Unpublish Marketing', 'desc' => 'Activate, pause, publish, unpublish, or send eligible campaigns, coupons, and deals.'],
                    ['id' => 'delete-marketing-content', 'name' => 'Delete Marketing Content', 'desc' => 'Delete eligible campaigns, coupons, or deals.'],
                ],
            ],
            [
                'module_id' => 'affiliates-management',
                'module_name' => 'Affiliates Management',
                'permissions' => [
                    ['id' => 'view-affiliates', 'name' => 'View Affiliates', 'desc' => 'View affiliate applications, affiliate accounts, commissions, referral activity, and payouts.'],
                    ['id' => 'manage-affiliates', 'name' => 'Manage Affiliates', 'desc' => 'Review applications; create/edit affiliate accounts; manage status, referral details, and commission settings.'],
                    ['id' => 'manage-commissions', 'name' => 'Manage Commissions', 'desc' => 'Review and adjust eligible commission records with an audit reason.'],
                    ['id' => 'manage-payouts', 'name' => 'Manage Payouts', 'desc' => 'Review and process authorized affiliate payouts.'],
                    ['id' => 'delete-affiliates', 'name' => 'Delete Affiliates', 'desc' => 'Delete eligible affiliate records where policy permits.'],
                ],
            ],
            [
                'module_id' => 'gift-cards-management',
                'module_name' => 'Gift Cards Management',
                'permissions' => [
                    ['id' => 'view-gift-cards', 'name' => 'View Gift Cards', 'desc' => 'View gift-card list, pending orders, balances, status, sender/recipient information, and ledger transactions.'],
                    ['id' => 'manage-gift-cards', 'name' => 'Manage Gift Cards', 'desc' => 'Issue digital or physical gift cards and edit eligible gift-card details.'],
                    ['id' => 'manage-gift-card-balance', 'name' => 'Manage Gift Card Balance', 'desc' => 'Make authorized balance adjustments with an audit reason.'],
                    ['id' => 'manage-gift-card-status', 'name' => 'Manage Gift Card Status', 'desc' => 'Activate, deactivate, void, or resend eligible gift cards.'],
                    ['id' => 'delete-gift-card-records', 'name' => 'Delete Gift Card Records', 'desc' => 'Delete eligible non-financial gift-card records where policy permits; ledger history should remain protected.'],
                ],
            ],
            [
                'module_id' => 'membership-management',
                'module_name' => 'Membership Management',
                'permissions' => [
                    ['id' => 'view-memberships', 'name' => 'View Memberships', 'desc' => 'View membership plans, customer memberships, business memberships, settings, and membership transactions.'],
                    ['id' => 'manage-membership-plans', 'name' => 'Manage Membership Plans', 'desc' => 'Create/edit plans, pricing, benefits, eligibility, and plan availability.'],
                    ['id' => 'manage-customer-memberships', 'name' => 'Manage Customer Memberships', 'desc' => 'Enroll customers and manage eligible upgrades, downgrades, cancellations, renewal dates, and status.'],
                    ['id' => 'manage-business-memberships', 'name' => 'Manage Business Memberships', 'desc' => 'Enroll business accounts and manage eligible plan changes, cancellations, renewal dates, and status.'],
                    ['id' => 'manage-membership-settings', 'name' => 'Manage Membership Settings', 'desc' => 'Configure membership-wide rules and administrative settings.'],
                    ['id' => 'view-membership-transactions', 'name' => 'View Membership Transactions', 'desc' => 'View membership charges, renewals, adjustments, and transaction history.'],
                    ['id' => 'delete-membership-plans', 'name' => 'Delete Membership Plans', 'desc' => 'Delete or archive eligible unused membership plans; transaction history remains protected.'],
                ],
            ],
            [
                'module_id' => 'loyalty-management',
                'module_name' => 'Loyalty Management',
                'permissions' => [
                    ['id' => 'view-loyalty', 'name' => 'View Loyalty', 'desc' => 'View customer loyalty balances, activity, and transactions.'],
                    ['id' => 'manage-loyalty', 'name' => 'Manage Loyalty', 'desc' => 'Configure earning, redemption, expiration, and program settings.'],
                    ['id' => 'adjust-loyalty-points', 'name' => 'Adjust Loyalty Points', 'desc' => 'Add or deduct points with amount, reason, staff attribution, and reference.'],
                    ['id' => 'delete-loyalty-records', 'name' => 'Delete Loyalty Records', 'desc' => 'Delete eligible non-transactional loyalty records where policy permits; transaction history remains protected.'],
                ],
            ],
            [
                'module_id' => 'voucher-management',
                'module_name' => 'Voucher Management',
                'permissions' => [
                    ['id' => 'view-vouchers', 'name' => 'View Vouchers', 'desc' => 'View voucher values, balances, status, customer information, and transaction history.'],
                    ['id' => 'manage-vouchers', 'name' => 'Manage Vouchers', 'desc' => 'Issue vouchers; edit eligible details; manage balance, status, and expiration.'],
                    ['id' => 'void-vouchers', 'name' => 'Void Vouchers', 'desc' => 'Permanently void eligible vouchers with an audit trail.'],
                    ['id' => 'delete-voucher-records', 'name' => 'Delete Voucher Records', 'desc' => 'Delete eligible non-financial voucher records where policy permits; transaction history remains protected.'],
                ],
            ],
            [
                'module_id' => 'financing-management',
                'module_name' => 'Financing Management',
                'permissions' => [
                    ['id' => 'view-financing', 'name' => 'View Financing', 'desc' => 'View financing applications, submitted documents, status, terms, and payment information.'],
                    ['id' => 'manage-financing-applications', 'name' => 'Manage Financing Applications', 'desc' => 'Review documents and update eligible application information and workflow status.'],
                    ['id' => 'approve-decline-financing', 'name' => 'Approve / Decline Financing', 'desc' => 'Approve or decline eligible financing applications.'],
                    ['id' => 'manage-financing-programs', 'name' => 'Manage Financing Programs', 'desc' => 'Maintain financing products, limits, terms, and installment rules.'],
                    ['id' => 'manage-financing-payments', 'name' => 'Manage Financing Payments', 'desc' => 'Record authorized financing payments and adjustments.'],
                    ['id' => 'delete-financing-records', 'name' => 'Delete Financing Records', 'desc' => 'Delete eligible non-financial records only where policy and retention rules permit.'],
                ],
            ],
            [
                'module_id' => 'accounting-management',
                'module_name' => 'Accounting Management',
                'permissions' => [
                    ['id' => 'view-accounting', 'name' => 'View Accounting', 'desc' => 'View income, expenses, chart of accounts, bank accounts, transaction ledger, wallet transactions, reconciliations, and financial summaries.'],
                    ['id' => 'manage-income', 'name' => 'Manage Income', 'desc' => 'Add and edit eligible income records, classifications, references, and supporting details.'],
                    ['id' => 'manage-expenses', 'name' => 'Manage Expenses', 'desc' => 'Add and edit eligible expense records, classifications, references, and supporting details.'],
                    ['id' => 'manage-chart-of-accounts', 'name' => 'Manage Chart of Accounts', 'desc' => 'Add, edit, activate, deactivate, and organize accounts and accounting categories.'],
                    ['id' => 'manage-bank-accounts', 'name' => 'Manage Bank Accounts', 'desc' => 'Add and maintain approved bank/account records and related administrative details.'],
                    ['id' => 'manage-transactions-ledger', 'name' => 'Manage Transactions Ledger', 'desc' => 'Maintain, classify, and reconcile eligible ledger transactions according to accounting controls.'],
                    ['id' => 'manage-wallet-transactions', 'name' => 'Manage Wallet Transactions', 'desc' => 'Manage authorized wallet entries and adjustments with appropriate audit history.'],
                    ['id' => 'approve-financial-adjustments', 'name' => 'Approve Financial Adjustments', 'desc' => 'Approve authorized corrections, credits, fees, refunds, or manual financial adjustments.'],
                    ['id' => 'manage-reconciliation-period-close', 'name' => 'Manage Reconciliation & Period Close', 'desc' => 'Reconcile financial activity and lock/finalize accounting periods where supported.'],
                    ['id' => 'delete-accounting-records', 'name' => 'Delete Accounting Records', 'desc' => 'Delete eligible draft or non-posted accounting records only; posted financial history remains protected.'],
                ],
            ],
            [
                'module_id' => 'vendors-management',
                'module_name' => 'Vendors Management',
                'permissions' => [
                    ['id' => 'view-vendors', 'name' => 'View Vendors', 'desc' => 'View vendor profiles, contacts, status, payment terms, products, pricing, bills, and related records.'],
                    ['id' => 'manage-vendors', 'name' => 'Manage Vendors', 'desc' => 'Add and edit vendors, contacts, terms, associated products/pricing, status, and vendor documents.'],
                    ['id' => 'delete-vendors', 'name' => 'Delete Vendors', 'desc' => 'Delete eligible vendor records where no protected transaction dependency exists.'],
                    ['id' => 'view-bills', 'name' => 'View Bills', 'desc' => 'View vendor bills, amounts, due dates, references, attachments, and payment status.'],
                    ['id' => 'manage-bills', 'name' => 'Manage Bills', 'desc' => 'Add and edit vendor bills, due dates, references, attachments, coding, and eligible bill details.'],
                    ['id' => 'approve-bills', 'name' => 'Approve Bills', 'desc' => 'Approve or decline vendor bills before payment when approval is required.'],
                    ['id' => 'pay-bills', 'name' => 'Pay Bills', 'desc' => 'Record or process authorized payment of approved vendor bills and update payment status.'],
                    ['id' => 'manage-vendor-credits', 'name' => 'Manage Vendor Credits', 'desc' => 'Record and apply eligible vendor credits, adjustments, or credit memos.'],
                    ['id' => 'delete-bills', 'name' => 'Delete Bills', 'desc' => 'Delete eligible draft or unposted vendor bills; paid or posted bill history remains protected.'],
                ],
            ],
            [
                'module_id' => 'asset-management',
                'module_name' => 'Asset Management',
                'permissions' => [
                    ['id' => 'view-assets', 'name' => 'View Assets', 'desc' => 'View equipment and asset records, status, location, assignment, and maintenance history.'],
                    ['id' => 'manage-assets', 'name' => 'Manage Assets', 'desc' => 'Create/edit assets; assign/unassign; transfer locations; manage condition, maintenance, and documents.'],
                    ['id' => 'retire-assets', 'name' => 'Retire Assets', 'desc' => 'Mark eligible assets retired, disposed, lost, or otherwise removed from service.'],
                    ['id' => 'delete-assets', 'name' => 'Delete Assets', 'desc' => 'Delete eligible asset records where policy permits.'],
                ],
            ],
            [
                'module_id' => 'file-management',
                'module_name' => 'File Management',
                'permissions' => [
                    ['id' => 'view-files', 'name' => 'View Files', 'desc' => 'View permitted files, folders, versions, and storage information in the central File Manager.'],
                    ['id' => 'manage-files', 'name' => 'Manage Files', 'desc' => 'Upload, download, create folders, rename, move, copy, organize, and restore permitted files.'],
                    ['id' => 'manage-file-access', 'name' => 'Manage File Access', 'desc' => 'Share files and manage permitted file/folder access.'],
                    ['id' => 'delete-files', 'name' => 'Delete Files', 'desc' => 'Move eligible files or folders to trash.'],
                    ['id' => 'permanently-delete-files', 'name' => 'Permanently Delete Files', 'desc' => 'Permanently remove eligible trashed files; this is a protected permission.'],
                ],
            ],
            [
                'module_id' => 'hr-management',
                'module_name' => 'HR Management',
                'permissions' => [
                    ['id' => 'view-hr', 'name' => 'View HR', 'desc' => 'View permitted employee profiles, employment information, departments, schedules, attendance, leave, documents, and performance information.'],
                    ['id' => 'manage-employees', 'name' => 'Manage Employees', 'desc' => 'Add and edit employee profiles, employment details, department, position, job title, status, onboarding, and offboarding information.'],
                    ['id' => 'manage-departments-positions', 'name' => 'Manage Departments & Positions', 'desc' => 'Create and maintain departments, positions, job titles, and organizational assignments.'],
                    ['id' => 'manage-attendance-time', 'name' => 'Manage Attendance & Time', 'desc' => 'Manage employee schedules, attendance, time records, and related corrections.'],
                    ['id' => 'manage-time-off', 'name' => 'Manage Time Off', 'desc' => 'Manage employee leave and time-off requests, balances, and supporting records.'],
                    ['id' => 'approve-time-off', 'name' => 'Approve Time Off', 'desc' => 'Approve or decline eligible employee leave and time-off requests.'],
                    ['id' => 'manage-payroll-records', 'name' => 'Manage Payroll Records', 'desc' => 'Maintain permitted compensation and payroll-related employee records; this does not grant Accounting permissions.'],
                    ['id' => 'manage-employee-documents', 'name' => 'Manage Employee Documents', 'desc' => 'Maintain permitted employee documents, acknowledgements, and HR records.'],
                    ['id' => 'manage-performance-disciplinary-records', 'name' => 'Manage Performance & Disciplinary Records', 'desc' => 'Maintain permitted performance reviews, warnings, disciplinary records, and related HR documentation.'],
                    ['id' => 'delete-employee-records', 'name' => 'Delete Employee Records', 'desc' => 'Delete eligible HR records only where retention and employment rules permit.'],
                ],
            ],
            [
                'module_id' => 'donations-management',
                'module_name' => 'Donations Management',
                'permissions' => [
                    ['id' => 'view-donations', 'name' => 'View Donations', 'desc' => 'View donation transactions, participating organizations, campaigns, and disbursement status.'],
                    ['id' => 'manage-organizations', 'name' => 'Manage Organizations', 'desc' => 'Create/edit participating organizations and related details.'],
                    ['id' => 'manage-donation-settings-campaigns', 'name' => 'Manage Donation Settings & Campaigns', 'desc' => 'Configure checkout donation options, campaigns, and applicable rules.'],
                    ['id' => 'publish-unpublish-organizations', 'name' => 'Publish / Unpublish Organizations', 'desc' => 'Control whether eligible organizations or campaigns are available to customers.'],
                    ['id' => 'manage-donation-disbursements', 'name' => 'Manage Donation Disbursements', 'desc' => 'Track and update authorized donation disbursement status.'],
                    ['id' => 'delete-donation-content', 'name' => 'Delete Donation Content', 'desc' => 'Delete eligible organizations or campaigns where no protected transaction dependency exists.'],
                ],
            ],
            [
                'module_id' => 'knowledge-base-management',
                'module_name' => 'Knowledge Base Management',
                'permissions' => [
                    ['id' => 'view-knowledge-base', 'name' => 'View Knowledge Base', 'desc' => 'View articles, drafts, categories, visibility, and publication status.'],
                    ['id' => 'manage-knowledge-base', 'name' => 'Manage Knowledge Base', 'desc' => 'Create/edit articles, categories, attachments, visibility, and supported content settings.'],
                    ['id' => 'publish-unpublish-articles', 'name' => 'Publish / Unpublish Articles', 'desc' => 'Control article publication status.'],
                    ['id' => 'delete-articles', 'name' => 'Delete Articles', 'desc' => 'Archive or delete eligible knowledge-base articles.'],
                ],
            ],
            [
                'module_id' => 'blog-management',
                'module_name' => 'Blog Management',
                'permissions' => [
                    ['id' => 'view-blog', 'name' => 'View Blog', 'desc' => 'View blog drafts, scheduled posts, published content, categories, and authors.'],
                    ['id' => 'manage-blog', 'name' => 'Manage Blog', 'desc' => 'Create/edit posts, categories/tags, media, SEO, authors, and schedules.'],
                    ['id' => 'publish-unpublish-posts', 'name' => 'Publish / Unpublish Posts', 'desc' => 'Publish, unpublish, or schedule eligible blog content.'],
                    ['id' => 'delete-blog-posts', 'name' => 'Delete Blog Posts', 'desc' => 'Archive or delete eligible blog posts.'],
                ],
            ],
            [
                'module_id' => 'workspace-management',
                'module_name' => 'Workspace Management',
                'permissions' => [
                    ['id' => 'access-workspace', 'name' => 'Access Workspace', 'desc' => 'Access permitted Workspace tools: Notes, To-Do List, File Manager, Calendar, and Internal Chat.'],
                    ['id' => 'manage-notes', 'name' => 'Manage Notes', 'desc' => 'Create, view, edit, organize, and share permitted notes.'],
                    ['id' => 'manage-to-do-list', 'name' => 'Manage To-Do List', 'desc' => 'Create, view, edit, assign, complete, and manage permitted to-do items.'],
                    ['id' => 'manage-calendar', 'name' => 'Manage Calendar', 'desc' => 'Create, view, edit, reschedule, and manage permitted calendar events.'],
                    ['id' => 'use-file-manager', 'name' => 'Use File Manager', 'desc' => 'Access and perform file actions allowed by the separate File Management permissions.'],
                    ['id' => 'use-internal-chat', 'name' => 'Use Internal Chat', 'desc' => 'Access permitted conversations, send messages, and manage own messages within policy.'],
                    ['id' => 'manage-internal-chat', 'name' => 'Manage Internal Chat', 'desc' => 'Create/manage permitted channels or groups, membership, and administrative chat actions.'],
                    ['id' => 'delete-workspace-content', 'name' => 'Delete Workspace Content', 'desc' => 'Delete eligible notes, to-do items, calendar events, and permitted chat content; file deletion follows File Management permissions.'],
                ],
            ],
            [
                'module_id' => 'settings',
                'module_name' => 'Settings',
                'permissions' => [
                    ['id' => 'view-settings', 'name' => 'View Settings', 'desc' => 'View system and store configuration.'],
                    ['id' => 'manage-general-settings', 'name' => 'Manage General Settings', 'desc' => 'Maintain business information, locations, and general site configuration.'],
                    ['id' => 'manage-payment-settings', 'name' => 'Manage Payment Settings', 'desc' => 'Configure approved payment gateways and related payment settings.'],
                    ['id' => 'manage-shipping-settings', 'name' => 'Manage Shipping Settings', 'desc' => 'Configure shipping methods, zones, rates, delivery, and pickup settings.'],
                    ['id' => 'manage-marketing-settings', 'name' => 'Manage Marketing Settings', 'desc' => 'Configure SMS, email, and push-notification settings used by marketing and system communications.'],
                    ['id' => 'manage-module-settings', 'name' => 'Manage Module Settings', 'desc' => 'Maintain applicable charity/donation, affiliate, membership, gift-card, loyalty, and popup settings.'],
                    ['id' => 'manage-integrations-api-access', 'name' => 'Manage Integrations & API Access', 'desc' => 'Configure approved integrations, API credentials, and webhooks; treat API access as protected.'],
                    ['id' => 'manage-security-access-settings', 'name' => 'Manage Security & Access Settings', 'desc' => 'Configure authentication, sessions, security, and administrative access controls.'],
                    ['id' => 'manage-policies-features', 'name' => 'Manage Policies & Features', 'desc' => 'Maintain legal/policy content and supported feature settings.'],
                ],
            ],
        ];

        $allPermissionNames = [];

        // 1. Seed all permissions
        foreach ($modules as $mod) {
            foreach ($mod['permissions'] as $p) {
                $permissionName = $p['id'];
                $allPermissionNames[] = $permissionName;

                $perm = Permission::firstOrNew(['name' => $permissionName, 'guard_name' => 'web']);
                $perm->module_id = $mod['module_id'];
                $perm->module_name = $mod['module_name'];
                $perm->display_name = $p['name'];
                $perm->description = $p['desc'];
                $perm->save();
            }
        }

        // 2. Define standard enterprise roles
        $rolesData = [
            [
                'name' => 'super_admin',
                'description' => 'Full access to all modules and system settings.',
                'status' => true,
                'color' => 'pink',
                'all' => true,
            ],
            [
                'name' => 'admin',
                'description' => 'Full operational access to manage the platform.',
                'status' => true,
                'color' => 'blue',
                'modules' => [
                    'user-management', 'customer-management', 'business-management', 'products-management',
                    'orders-management', 'quotation-management', 'contracts-proposals', 'support-management',
                    'marketing-management', 'affiliates-management', 'gift-cards-management', 'membership-management',
                    'loyalty-management', 'voucher-management', 'financing-management', 'accounting-management',
                    'vendors-management', 'asset-management', 'file-management', 'donations-management',
                    'knowledge-base-management', 'blog-management', 'workspace-management', 'settings',
                ],
            ],
            [
                'name' => 'manager',
                'description' => 'Manage daily operations within assigned modules.',
                'status' => true,
                'color' => 'green',
                'modules' => [
                    'customer-management', 'business-management', 'products-management',
                    'orders-management', 'quotation-management', 'support-management',
                    'affiliates-management', 'gift-cards-management', 'membership-management',
                    'loyalty-management', 'voucher-management', 'asset-management',
                    'file-management', 'workspace-management',
                ],
            ],
            [
                'name' => 'editor',
                'description' => 'Create and manage content, products and marketing materials.',
                'status' => true,
                'color' => 'orange',
                'modules' => [
                    'products-management', 'marketing-management', 'knowledge-base-management',
                    'blog-management', 'file-management', 'workspace-management',
                ],
            ],
            [
                'name' => 'staff',
                'description' => 'Limited access for daily tasks and assigned areas.',
                'status' => true,
                'color' => 'purple',
                'modules' => [
                    'orders-management', 'support-management', 'workspace-management',
                ],
            ],
            [
                'name' => 'viewer',
                'description' => 'Read-only access to selected modules.',
                'status' => false,
                'color' => 'slate',
                'modules' => [],
            ],
        ];

        foreach ($rolesData as $r) {
            $role = Role::firstOrNew(['name' => $r['name'], 'guard_name' => 'web']);
            $role->description = $r['description'];
            $role->status = $r['status'];
            $role->color = $r['color'];
            $role->save();

            if (!empty($r['all'])) {
                $role->syncPermissions($allPermissionNames);
            } elseif (!empty($r['modules'])) {
                $rolePerms = [];
                foreach ($modules as $mod) {
                    if (in_array($mod['module_id'], $r['modules'])) {
                        foreach ($mod['permissions'] as $p) {
                            $rolePerms[] = $p['id'];
                        }
                    }
                }
                $role->syncPermissions($rolePerms);
            } elseif ($r['name'] === 'viewer') {
                // Read-only view permissions
                $viewPerms = [];
                foreach ($modules as $mod) {
                    foreach ($mod['permissions'] as $p) {
                        if (str_starts_with($p['id'], 'view-') || str_starts_with($p['id'], 'access-')) {
                            $viewPerms[] = $p['id'];
                        }
                    }
                }
                $role->syncPermissions($viewPerms);
            }
        }

        // 3. Ensure first admin user has super_admin
        $adminUser = User::where('email', 'admin@mecarvi.com')->first();
        if ($adminUser) {
            $adminUser->staff_id = 'STF-0001';
            $adminUser->department = 'Engineering';
            $adminUser->job_title = 'Lead Systems Architect';
            $adminUser->status = 'active';
            $adminUser->two_factor_enabled = true;
            $adminUser->save();
            $adminUser->syncRoles(['super_admin', 'admin']);
        }

        // 4. Seed demo staff users if needed to match the mockup cards
        $staffMembers = [
            [
                'name' => 'Subrina Vazquez',
                'email' => 'subrina.v@mecarvi.com',
                'staff_id' => 'STF-0012',
                'department' => 'Operations',
                'job_title' => 'Operations Director',
                'role' => 'super_admin',
                'status' => 'active',
                'phone' => '+1 (555) 234-5678',
                'two_factor_enabled' => true,
            ],
            [
                'name' => 'John Doe',
                'email' => 'john.doe@mecarvi.com',
                'staff_id' => 'STF-0015',
                'department' => 'Product',
                'job_title' => 'Product Manager',
                'role' => 'admin',
                'status' => 'active',
                'phone' => '+1 (555) 345-6789',
                'two_factor_enabled' => true,
            ],
            [
                'name' => 'Sarah Mitchell',
                'email' => 'sarah.m@mecarvi.com',
                'staff_id' => 'STF-0018',
                'department' => 'Operations',
                'job_title' => 'Store Administrator',
                'role' => 'manager',
                'status' => 'active',
                'phone' => '+1 (555) 456-7890',
                'two_factor_enabled' => true,
            ],
            [
                'name' => 'Robert James',
                'email' => 'robert.j@mecarvi.com',
                'staff_id' => 'STF-0021',
                'department' => 'Customer Support',
                'job_title' => 'Support Lead',
                'role' => 'editor',
                'status' => 'active',
                'phone' => '+1 (555) 567-8901',
                'two_factor_enabled' => false,
            ],
            [
                'name' => 'Emily Wilson',
                'email' => 'emily.w@mecarvi.com',
                'staff_id' => 'STF-0024',
                'department' => 'Marketing',
                'job_title' => 'Marketing Specialist',
                'role' => 'staff',
                'status' => 'active',
                'phone' => '+1 (555) 678-9012',
                'two_factor_enabled' => true,
            ],
            [
                'name' => 'Daniel Kim',
                'email' => 'daniel.kim@mecarvi.com',
                'staff_id' => 'STF-0028',
                'department' => 'Warehouse',
                'job_title' => 'Fulfillment Supervisor',
                'role' => 'staff',
                'status' => 'active',
                'phone' => '+1 (555) 789-0123',
                'two_factor_enabled' => false,
            ],
            [
                'name' => 'Natalie Roberts',
                'email' => 'natalie.r@mecarvi.com',
                'staff_id' => 'STF-0031',
                'department' => 'Finance',
                'job_title' => 'Senior Accountant',
                'role' => 'manager',
                'status' => 'active',
                'phone' => '+1 (555) 890-1234',
                'two_factor_enabled' => true,
            ],
            [
                'name' => 'Thomas Scott',
                'email' => 'thomas.s@mecarvi.com',
                'staff_id' => 'STF-0035',
                'department' => 'Security',
                'job_title' => 'Compliance Analyst',
                'role' => 'viewer',
                'status' => 'inactive',
                'phone' => '+1 (555) 901-2345',
                'two_factor_enabled' => false,
            ],
            [
                'name' => 'Jessica Taylor',
                'email' => 'jessica.t@mecarvi.com',
                'staff_id' => 'STF-0039',
                'department' => 'Product',
                'job_title' => 'Catalog Specialist',
                'role' => 'editor',
                'status' => 'pending',
                'phone' => '+1 (555) 012-3456',
                'two_factor_enabled' => false,
            ],
            [
                'name' => 'David Martinez',
                'email' => 'david.m@mecarvi.com',
                'staff_id' => 'STF-0042',
                'department' => 'Operations',
                'job_title' => 'Order Specialist',
                'role' => 'staff',
                'status' => 'pending',
                'phone' => '+1 (555) 123-4567',
                'two_factor_enabled' => false,
            ],
            [
                'name' => 'Amanda White',
                'email' => 'amanda.w@mecarvi.com',
                'staff_id' => 'STF-0047',
                'department' => 'Legal',
                'job_title' => 'Legal Counsel',
                'role' => 'manager',
                'status' => 'pending',
                'phone' => '+1 (555) 234-5679',
                'two_factor_enabled' => false,
            ],
            [
                'name' => 'James Anderson',
                'email' => 'james.a@mecarvi.com',
                'staff_id' => 'STF-0050',
                'department' => 'Customer Support',
                'job_title' => 'Support Representative',
                'role' => 'staff',
                'status' => 'active',
                'phone' => '+1 (555) 345-6780',
                'two_factor_enabled' => true,
            ],
        ];

        foreach ($staffMembers as $member) {
            $user = User::firstOrNew(['email' => $member['email']]);
            $user->name = $member['name'];
            if (!$user->exists) {
                $user->password = Hash::make('Password123!');
            }
            $user->staff_id = $member['staff_id'];
            $user->department = $member['department'];
            $user->job_title = $member['job_title'];
            $user->role = $member['role'];
            $user->status = $member['status'];
            $user->phone = $member['phone'];
            $user->two_factor_enabled = $member['two_factor_enabled'];
            $user->save();

            $user->syncRoles([$member['role']]);
        }

        $this->command->info('Corporate permissions, 25 modules, roles, and staff members seeded successfully.');
    }
}
