<?php

namespace App\Services;

use App\Models\EcommerceDispute;
use App\Models\EcommerceOrder;
use App\Models\EcommerceOrderProof;
use App\Models\EcommerceOrderVerification;
use App\Models\EmailNotificationLog;
use App\Models\EmailNotificationSetting;
use App\Models\EmailTemplate;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EmailNotificationService
{
    public const EVENTS = [
        'approved_qoute' => [
            'label' => 'Approved Quote',
            'category' => 'sales',
            'subject' => 'Approved Qoute',
            'heading' => 'Your Quote Has Been Approved',
            'body_text' => "Hi {{customer_name}},\n\nGreat news! Your quote request {{quote_number}} has been approved.\n\nTotal Amount: {{total_amount}}",
            'variables' => ['customer_name', 'customer_email', 'quote_number', 'total_amount', 'site_name'],
        ],
        'bank_credit_supplier' => [
            'label' => 'Bank Credit Supplier',
            'category' => 'financial',
            'subject' => 'Bank Credit Supplier',
            'heading' => 'Bank Credit Supplier Advice',
            'body_text' => "Hi {{supplier_name}},\n\nA bank credit of {{amount}} has been processed under reference {{reference_number}}.",
            'variables' => ['supplier_name', 'amount', 'reference_number', 'site_name'],
        ],
        'customer_membership_salary_change_pending_approval' => [
            'label' => 'Membership Salary Change Pending Approval',
            'category' => 'membership',
            'subject' => 'Pending Approval',
            'heading' => 'Membership Change Pending Approval',
            'body_text' => "Hi {{customer_name}},\n\nYour membership salary change request for {{membership_plan}} is pending approval.",
            'variables' => ['customer_name', 'customer_email', 'membership_plan', 'status', 'site_name'],
        ],
        'customer_credit_verification' => [
            'label' => 'Customer Credit Verification',
            'category' => 'security',
            'subject' => 'Verification Required',
            'heading' => 'Credit Verification Required',
            'body_text' => "Hi {{customer_name}},\n\nPlease enter the verification code {{verification_code}} to complete your credit verification.",
            'variables' => ['customer_name', 'customer_email', 'verification_code', 'site_name'],
        ],
        'customer_credit_requested' => [
            'label' => 'Customer Credit Requested',
            'category' => 'financial',
            'subject' => 'Credit Verification Requested',
            'heading' => 'Credit Request Submitted',
            'body_text' => "Hi {{customer_name}},\n\nYour credit request for {{amount}} has been received and is being processed.",
            'variables' => ['customer_name', 'customer_email', 'amount', 'site_name'],
        ],
        'customer_referral_commission' => [
            'label' => 'Customer Referral Commission',
            'category' => 'rewards',
            'subject' => "Congrats! You've earned commission for referral",
            'heading' => 'Referral Commission Earned',
            'body_text' => "Hi {{customer_name}},\n\nCongratulations! You have earned {{commission_amount}} in referral commission using code {{referral_code}}.",
            'variables' => ['customer_name', 'customer_email', 'commission_amount', 'referral_code', 'site_name'],
        ],
        'customer_add_balance' => [
            'label' => 'Customer Add Balance',
            'category' => 'wallet',
            'subject' => 'Congrats! We have added balance to your wallet',
            'heading' => 'Wallet Balance Added',
            'body_text' => "Hi {{customer_name}},\n\nWe have credited {{amount}} to your wallet. Your new balance is {{new_balance}}.",
            'variables' => ['customer_name', 'customer_email', 'amount', 'new_balance', 'site_name'],
        ],
        'customer_sub_balance' => [
            'label' => 'Customer Subtract Balance',
            'category' => 'wallet',
            'subject' => 'Congrats! We have deducted balance from your wallet',
            'heading' => 'Wallet Balance Adjusted',
            'body_text' => "Hi {{customer_name}},\n\nAn amount of {{amount}} has been deducted from your wallet. Your updated balance is {{new_balance}}.",
            'variables' => ['customer_name', 'customer_email', 'amount', 'new_balance', 'site_name'],
        ],
        'protection_plan_admin_reject' => [
            'label' => 'Protection Plan Admin Reject',
            'category' => 'protection',
            'subject' => 'Protection Plan Admin Reject',
            'heading' => 'Protection Plan Request Declined',
            'body_text' => "Hi {{customer_name}},\n\nYour request for {{plan_name}} was not approved. Reason: {{reason}}.",
            'variables' => ['customer_name', 'customer_email', 'plan_name', 'reason', 'site_name'],
        ],
        'protection_plan_admin_accept' => [
            'label' => 'Protection Plan Admin Accept',
            'category' => 'protection',
            'subject' => 'Protection Plan Admin Accept',
            'heading' => 'Protection Plan Approved',
            'body_text' => "Hi {{customer_name}},\n\nYour protection plan {{plan_name}} has been accepted and activated.",
            'variables' => ['customer_name', 'customer_email', 'plan_name', 'site_name'],
        ],
        'protection_plan_claim_amount' => [
            'label' => 'Protection Plan Claim Amount',
            'category' => 'protection',
            'subject' => 'Approved Amount Claimed For Protection Plan',
            'heading' => 'Protection Plan Claim Amount Approved',
            'body_text' => "Hi {{customer_name}},\n\nYour claim {{claim_id}} has been approved for the amount of {{amount}}.",
            'variables' => ['customer_name', 'customer_email', 'claim_id', 'amount', 'site_name'],
        ],
        'protection_plan_claim_approved' => [
            'label' => 'Protection Plan Claim Approved',
            'category' => 'protection',
            'subject' => 'Protection Plan Claim Has Been Approved',
            'heading' => 'Claim Approved',
            'body_text' => "Hi {{customer_name}},\n\nYour protection plan claim {{claim_id}} has been approved.",
            'variables' => ['customer_name', 'customer_email', 'claim_id', 'site_name'],
        ],
        'protection_plan_claim_submitted' => [
            'label' => 'Protection Plan Claim Submitted',
            'category' => 'protection',
            'subject' => 'Protection Plan Claim Submitted',
            'heading' => 'Claim Received',
            'body_text' => "Hi {{customer_name}},\n\nWe received your protection plan claim {{claim_id}}. Our team is reviewing the details.",
            'variables' => ['customer_name', 'customer_email', 'claim_id', 'site_name'],
        ],
        'customer_loan_disburse' => [
            'label' => 'Customer Loan Disburse',
            'category' => 'financial',
            'subject' => 'How About You Consult In Tool Successfully',
            'heading' => 'Loan Disbursed Successfully',
            'body_text' => "Hi {{customer_name}},\n\nYour loan disbursement {{disbursement_id}} of {{amount}} has been completed successfully.",
            'variables' => ['customer_name', 'customer_email', 'amount', 'disbursement_id', 'site_name'],
        ],
        'customer_artisan_commission_withdraw_approved' => [
            'label' => 'Artisan Commission Withdraw Approved',
            'category' => 'financial',
            'subject' => 'How About You Consult In Tool Approved',
            'heading' => 'Withdrawal Approved',
            'body_text' => "Hi {{artisan_name}},\n\nYour commission withdrawal request for {{amount}} has been approved.",
            'variables' => ['artisan_name', 'amount', 'site_name'],
        ],
        'customer_artisan_withdraw_request_cancelled' => [
            'label' => 'Artisan Withdraw Request Cancelled',
            'category' => 'financial',
            'subject' => 'Artisan Request Cancelled',
            'heading' => 'Withdrawal Request Cancelled',
            'body_text' => "Hi {{artisan_name}},\n\nYour withdrawal request {{request_id}} has been cancelled. Reason: {{reason}}.",
            'variables' => ['artisan_name', 'request_id', 'reason', 'site_name'],
        ],
        'customer_artisan_withdraw_request' => [
            'label' => 'Artisan Withdraw Request',
            'category' => 'financial',
            'subject' => 'Artisan Request',
            'heading' => 'Withdrawal Request Received',
            'body_text' => "Hi {{artisan_name}},\n\nYour withdrawal request {{request_id}} for {{amount}} has been received and is under review.",
            'variables' => ['artisan_name', 'request_id', 'amount', 'site_name'],
        ],
        'message_sent' => [
            'label' => 'Message Sent',
            'category' => 'messaging',
            'subject' => 'New Message Sent',
            'heading' => 'Message Delivered',
            'body_text' => "Hi {{recipient_name}},\n\nYou sent a message to {{sender_name}}: \"{{message_preview}}\".",
            'variables' => ['recipient_name', 'sender_name', 'message_preview', 'site_name'],
        ],
        'message_from_customer' => [
            'label' => 'Message From Customer',
            'category' => 'messaging',
            'subject' => "You've Received New Message From Customer",
            'heading' => 'New Customer Message',
            'body_text' => "You received a new message from {{customer_name}}:\n\n\"{{message_preview}}\"",
            'variables' => ['customer_name', 'message_preview', 'site_name'],
        ],
        'customer_cancellation' => [
            'label' => 'Notice Of Order Cancellation',
            'category' => 'orders',
            'subject' => 'Notice Of Order Cancellation',
            'heading' => 'Order Cancelled',
            'body_text' => "Hi {{customer_name}},\n\nYour order {{order_number}} has been cancelled. Reason: {{reason}}",
            'variables' => ['customer_name', 'customer_email', 'order_number', 'reason', 'site_name'],
        ],
        'customer_product_question' => [
            'label' => 'Customer Product Question Answered',
            'category' => 'support',
            'subject' => 'Your Question Has Been Answered',
            'heading' => 'Product Question Answered',
            'body_text' => "Hi {{customer_name}},\n\nYour question about {{product_name}} has been answered:\n\nQ: {{question}}\nA: {{answer}}",
            'variables' => ['customer_name', 'product_name', 'question', 'answer', 'site_name'],
        ],
        'customer_product_question_reply' => [
            'label' => 'Customer Product Question Reply',
            'category' => 'support',
            'subject' => 'Customer Question Reply',
            'heading' => 'Reply to Product Question',
            'body_text' => "Hi {{customer_name}},\n\nThere is a reply to the question on {{product_name}}:\n\n\"{{reply}}\"",
            'variables' => ['customer_name', 'product_name', 'question', 'reply', 'site_name'],
        ],
        'customer_qoute_request' => [
            'label' => 'Customer Quote Request',
            'category' => 'sales',
            'subject' => 'Notice! There Is A New Quote Request',
            'heading' => 'New Quote Request Received',
            'body_text' => "Notice: A new quote request {{quote_number}} has been submitted by {{customer_name}}.",
            'variables' => ['customer_name', 'customer_email', 'quote_number', 'site_name'],
        ],
        'customer_pay_out' => [
            'label' => 'Customer Payout Approved',
            'category' => 'financial',
            'subject' => 'Great news! Payout Request Approved',
            'heading' => 'Payout Approved',
            'body_text' => "Hi {{customer_name}},\n\nGreat news! Your payout request {{payout_id}} for {{amount}} has been approved.",
            'variables' => ['customer_name', 'customer_email', 'payout_id', 'amount', 'site_name'],
        ],
        'customer_refund' => [
            'label' => 'Customer Refund Approved',
            'category' => 'financial',
            'subject' => 'Great news! Your Refund Is Approved',
            'heading' => 'Refund Approved',
            'body_text' => "Hi {{customer_name}},\n\nYour refund request for order {{order_number}} in the amount of {{amount}} has been approved.",
            'variables' => ['customer_name', 'customer_email', 'order_number', 'amount', 'site_name'],
        ],
        'customer_due_soon' => [
            'label' => 'Customer Account Due Soon',
            'category' => 'billing',
            'subject' => 'Notice! Your Account Due Date Notice',
            'heading' => 'Payment Due Soon',
            'body_text' => "Hi {{customer_name}},\n\nThis is a friendly reminder that your account payment of {{amount_due}} is due on {{due_date}}.",
            'variables' => ['customer_name', 'due_date', 'amount_due', 'site_name'],
        ],
        'customer_membership_expire' => [
            'label' => 'Customer Membership Expiring Soon',
            'category' => 'membership',
            'subject' => 'Notice! Your Membership Expired Soon',
            'heading' => 'Membership Expiring Soon',
            'body_text' => "Hi {{customer_name}},\n\nYour {{membership_plan}} membership will expire on {{expiry_date}}. Please renew to retain your benefits.",
            'variables' => ['customer_name', 'membership_plan', 'expiry_date', 'site_name'],
        ],
        'loyalty_point_redemption' => [
            'label' => 'Loyalty Point Redemption',
            'category' => 'rewards',
            'subject' => 'Loyalty Points Redeemed Successfully',
            'heading' => 'Loyalty Points Redeemed',
            'body_text' => "Hi {{customer_name}},\n\nYou have successfully redeemed {{points_redeemed}} points for {{reward_description}}.",
            'variables' => ['customer_name', 'points_redeemed', 'reward_description', 'site_name'],
        ],
        'referral_product_commission' => [
            'label' => 'Referral Product Commission',
            'category' => 'rewards',
            'subject' => "Thank You! You've Earned Commission",
            'heading' => 'Commission Earned',
            'body_text' => "Hi {{customer_name}},\n\nThank you! You earned {{commission_amount}} in commission on {{product_name}}.",
            'variables' => ['customer_name', 'product_name', 'commission_amount', 'site_name'],
        ],
        'change_email_confirmation' => [
            'label' => 'Change Email Confirmation',
            'category' => 'system',
            'subject' => "You've Successfully Changed Your Email",
            'heading' => 'Email Changed Successfully',
            'body_text' => "Hi {{customer_name}},\n\nYour account email address has been updated to {{new_email}}.",
            'variables' => ['customer_name', 'new_email', 'site_name'],
        ],
        'change_password_confirmation' => [
            'label' => 'Change Password Confirmation',
            'category' => 'system',
            'subject' => "You've Successfully Changed Your Password",
            'heading' => 'Password Changed Successfully',
            'body_text' => "Hi {{customer_name}},\n\nYour account password was updated successfully. If you did not perform this action, please contact support immediately.",
            'variables' => ['customer_name', 'site_name'],
        ],
        'customer_order_cancellation' => [
            'label' => 'Customer Order Cancellation',
            'category' => 'orders',
            'subject' => 'Order Cancelled',
            'heading' => 'Order Cancelled',
            'body_text' => "Hi {{customer_name}},\n\nYour order {{order_number}} has been cancelled.",
            'variables' => ['customer_name', 'order_number', 'reason', 'site_name'],
        ],
        'forgot_password' => [
            'label' => 'Forgot Password',
            'category' => 'system',
            'subject' => 'Reset Your Password',
            'heading' => 'Password Reset Request',
            'body_text' => "Hi {{customer_name}},\n\nWe received a password reset request. Click below to set a new password.",
            'button_text' => 'Reset Password',
            'button_url' => '{{reset_link}}',
            'variables' => ['customer_name', 'customer_email', 'reset_link', 'expiry_minutes', 'site_name'],
        ],
        'order_delivered' => [
            'label' => 'Order Delivered',
            'category' => 'orders',
            'subject' => 'Great News! Your Order Has Been Delivered',
            'heading' => 'Order Delivered',
            'body_text' => "Hi {{customer_name}},\n\nGreat news! Your order {{order_number}} has been delivered successfully.",
            'variables' => ['customer_name', 'customer_email', 'order_number', 'order_total', 'site_name'],
        ],
        'order_delayed' => [
            'label' => 'Order Delayed',
            'category' => 'orders',
            'subject' => 'Your Order Is Being Delayed',
            'heading' => 'Order Shipment Delay',
            'body_text' => "Hi {{customer_name}},\n\nWe are sorry to inform you that your order {{order_number}} is experiencing a slight delay. Reason: {{delay_reason}}",
            'variables' => ['customer_name', 'customer_email', 'order_number', 'delay_reason', 'site_name'],
        ],
        'order_declined' => [
            'label' => 'Order Declined',
            'category' => 'orders',
            'subject' => "We're Sorry, Your Order Has Been Declined",
            'heading' => 'Order Declined',
            'body_text' => "Hi {{customer_name}},\n\nWe regret to inform you that your order {{order_number}} was declined. Reason: {{reason}}",
            'variables' => ['customer_name', 'customer_email', 'order_number', 'reason', 'site_name'],
        ],
        'order_verification' => [
            'label' => 'Order Verification',
            'category' => 'orders',
            'subject' => 'Your Order Is Pending Verification',
            'heading' => 'Order Pending Verification',
            'body_text' => "Hi {{customer_name}},\n\nYour order {{order_number}} is currently pending identity or payment verification.",
            'variables' => ['customer_name', 'customer_email', 'order_number', 'site_name'],
        ],
        'order_processing' => [
            'label' => 'Order Processing',
            'category' => 'orders',
            'subject' => 'Great News! Your Order Is Processing',
            'heading' => 'Order Is Processing',
            'body_text' => "Hi {{customer_name}},\n\nYour order {{order_number}} is currently being processed by our production team.",
            'variables' => ['customer_name', 'customer_email', 'order_number', 'site_name'],
        ],
        'order_out_for_delivery' => [
            'label' => 'Order Out For Delivery',
            'category' => 'orders',
            'subject' => 'Your Order Is Out For Delivery',
            'heading' => 'Out For Delivery',
            'body_text' => "Hi {{customer_name}},\n\nYour order {{order_number}} is out for delivery and will arrive soon!",
            'button_text' => 'Track Package',
            'button_url' => '{{tracking_url}}',
            'variables' => ['customer_name', 'customer_email', 'order_number', 'tracking_url', 'site_name'],
        ],
        'order_refunded' => [
            'label' => 'Order Refunded',
            'category' => 'orders',
            'subject' => 'Your Order Has Been Refunded Successfully',
            'heading' => 'Order Refund Processed',
            'body_text' => "Hi {{customer_name}},\n\nYour order {{order_number}} has been refunded in the amount of {{amount}}.",
            'variables' => ['customer_name', 'customer_email', 'order_number', 'amount', 'site_name'],
        ],
        'order_confirmed' => [
            'label' => 'Order Confirmed',
            'category' => 'orders',
            'subject' => 'Your Order Has Been Confirmed Successfully',
            'heading' => 'Order Confirmed',
            'body_text' => "Hi {{customer_name}},\n\nYour order {{order_number}} has been confirmed.",
            'variables' => ['customer_name', 'customer_email', 'order_number', 'site_name'],
        ],
        'order_placed' => [
            'label' => 'Order Placed',
            'category' => 'orders',
            'subject' => "We've Got Your Order!",
            'heading' => 'We Received Your Order',
            'body_text' => "Hi {{customer_name}},\n\nThank you for your order {{order_number}}. Total: {{order_total}}",
            'variables' => ['customer_name', 'customer_email', 'order_number', 'order_total', 'site_name'],
        ],
        'order_shipped' => [
            'label' => 'Order Shipped',
            'category' => 'orders',
            'subject' => 'Great News! Your Order Is On Its Way',
            'heading' => 'Order Shipped',
            'body_text' => "Hi {{customer_name}},\n\nYour order {{order_number}} has shipped! Tracking number: {{tracking_number}}",
            'button_text' => 'Track Order',
            'button_url' => '{{tracking_url}}',
            'variables' => ['customer_name', 'customer_email', 'order_number', 'tracking_number', 'tracking_url', 'site_name'],
        ],
        'customer_registration_bonus' => [
            'label' => 'Customer Registration Bonus',
            'category' => 'rewards',
            'subject' => 'Welcome! Registration Bonus Has Been Credited',
            'heading' => 'Registration Bonus Credited',
            'body_text' => "Hi {{customer_name}},\n\nWelcome to {{site_name}}! A bonus of {{bonus_amount}} has been added to your account.",
            'variables' => ['customer_name', 'bonus_amount', 'site_name'],
        ],
        'customer_membership_subscription' => [
            'label' => 'Customer Membership Subscription',
            'category' => 'membership',
            'subject' => 'Designed To Customer Club Member',
            'heading' => 'Welcome to Club Membership',
            'body_text' => "Hi {{customer_name}},\n\nCongratulations! You are now subscribed to {{membership_plan}}.",
            'variables' => ['customer_name', 'membership_plan', 'site_name'],
        ],
        'customer_membership_subscription_renew' => [
            'label' => 'Customer Membership Subscription Renewed',
            'category' => 'membership',
            'subject' => 'Subscription Renewed',
            'heading' => 'Membership Renewed',
            'body_text' => "Hi {{customer_name}},\n\nYour {{membership_plan}} subscription was successfully renewed. Next billing date: {{next_billing_date}}.",
            'variables' => ['customer_name', 'membership_plan', 'next_billing_date', 'site_name'],
        ],
        'wallet_deposit' => [
            'label' => 'Wallet Deposit',
            'category' => 'wallet',
            'subject' => 'Balance Added To Your Account',
            'heading' => 'Account Balance Added',
            'body_text' => "Hi {{customer_name}},\n\nA deposit of {{amount}} was added to your account. Current balance: {{new_balance}}.",
            'variables' => ['customer_name', 'amount', 'new_balance', 'site_name'],
        ],
        'customer_tier_upgradation' => [
            'label' => 'Customer Tier Upgradation',
            'category' => 'membership',
            'subject' => 'Welcome To Royal Customer',
            'heading' => 'Tier Upgraded',
            'body_text' => "Hi {{customer_name}},\n\nCongratulations! You have upgraded to {{new_tier}} status.",
            'variables' => ['customer_name', 'new_tier', 'site_name'],
        ],
        'new_order' => [
            'label' => 'New Order Alert',
            'category' => 'orders',
            'subject' => 'Notice! New Order Placed Successfully',
            'heading' => 'New Order Received',
            'body_text' => "Notice: New order {{order_number}} of {{order_total}} placed by {{customer_name}}.",
            'variables' => ['customer_name', 'order_number', 'order_total', 'site_name'],
        ],
        'user_registered' => [
            'label' => 'User Registration Welcome',
            'category' => 'system',
            'subject' => 'Welcome to {{site_name}}!',
            'heading' => 'Welcome {{customer_name}}',
            'body_text' => "Hi {{customer_name}},\n\nThank you for signing up at {{site_name}}! We are thrilled to have you.",
            'variables' => ['customer_name', 'customer_email', 'site_name'],
        ],
        'quote_submitted' => [
            'label' => 'Quote Request Submitted',
            'category' => 'sales',
            'subject' => 'Quote Request Received',
            'heading' => 'Quote Request Received',
            'body_text' => "Hi {{customer_name}},\n\nThank you for submitting your quote request {{quote_number}}. Our team will review it and get back to you shortly.",
            'variables' => ['customer_name', 'customer_email', 'quote_number', 'site_name'],
        ],
        'pin_verification' => [
            'label' => 'Security PIN Verification',
            'category' => 'security',
            'subject' => 'Your Security PIN Code',
            'heading' => 'Security PIN Code',
            'body_text' => "Hi {{customer_name}},\n\nYour security PIN code is {{pin_code}}. This code will expire in {{expiry_minutes}} minutes.",
            'variables' => ['customer_name', 'customer_email', 'pin_code', 'expiry_minutes', 'site_name'],
        ],
        'gift_card_issued' => [
            'label' => 'Gift Card Issued',
            'category' => 'rewards',
            'subject' => 'Your Gift Card is Ready!',
            'heading' => 'Gift Card Issued',
            'body_text' => "Hi {{customer_name}},\n\nYou have been issued a gift card with code {{gift_card_code}} valued at {{gift_card_balance}}.",
            'variables' => ['customer_name', 'customer_email', 'gift_card_code', 'gift_card_balance', 'site_name'],
        ],
        'order_cancelled' => [
            'label' => 'Order Cancelled',
            'category' => 'orders',
            'subject' => 'Order Cancellation Notice',
            'heading' => 'Order Cancelled',
            'body_text' => "Hi {{customer_name}},\n\nYour order {{order_number}} has been cancelled.",
            'variables' => ['customer_name', 'customer_email', 'order_number', 'reason', 'site_name'],
        ],
        'order_status_changed' => [
            'label' => 'Order Status Updated',
            'category' => 'orders',
            'subject' => 'Update on Your Order {{order_number}}',
            'heading' => 'Order Status Update',
            'body_text' => "Hi {{customer_name}},\n\nThe status of your order {{order_number}} has been updated to {{order_status}}.",
            'variables' => ['customer_name', 'customer_email', 'order_number', 'order_status', 'site_name'],
        ],
        'order_proof_ready' => [
            'label' => 'Order Proof Ready For Review',
            'category' => 'orders',
            'subject' => 'Your Design Proof is Ready for Review – Order #{{order_number}}',
            'heading' => 'Design Proof Ready For Your Approval',
            'body_text' => "Hi {{customer_name}},\n\nGreat news! Our design team has prepared the digital proof for your order {{order_number}} ({{proof_title}}, {{proof_version}}).\n\nPlease review the proof carefully to ensure all details, spellings, colors, and placements meet your expectations before we start production.\n\nResponse Due: {{response_due_date}}",
            'button_text' => 'Review & Approve Proof',
            'button_url' => '{{proof_url}}',
            'variables' => ['customer_name', 'customer_email', 'order_number', 'proof_title', 'proof_version', 'response_due_date', 'proof_url', 'site_name'],
        ],
        'order_proof_approved' => [
            'label' => 'Order Proof Approved',
            'category' => 'orders',
            'subject' => 'Proof Approved – Order #{{order_number}} Sent to Production',
            'heading' => 'Proof Approved! Production Underway',
            'body_text' => "Hi {{customer_name}},\n\nThank you for approving the design proof for order {{order_number}} ({{proof_title}}). Your order has been scheduled for production.\n\nOur team is now crafting your items with care. We will notify you as soon as your order is completed and on its way.",
            'button_text' => 'View Order Status',
            'button_url' => '{{order_url}}',
            'variables' => ['customer_name', 'customer_email', 'order_number', 'proof_title', 'order_url', 'site_name'],
        ],
        'order_proof_revision_requested' => [
            'label' => 'Order Proof Revision Requested',
            'category' => 'orders',
            'subject' => 'Revision Request Received – Order #{{order_number}}',
            'heading' => 'We Received Your Proof Revision Request',
            'body_text' => "Hi {{customer_name}},\n\nWe have received your requested revisions for order {{order_number}} ({{proof_title}}):\n\n\"{{revision_notes}}\"\n\nOur embroidery digitizing and design team is already working on these modifications. We will upload an updated proof for your review shortly.",
            'button_text' => 'View Proof Details',
            'button_url' => '{{proof_url}}',
            'variables' => ['customer_name', 'customer_email', 'order_number', 'proof_title', 'revision_notes', 'proof_url', 'site_name'],
        ],
        'order_proof_rejected' => [
            'label' => 'Order Proof Rejected',
            'category' => 'orders',
            'subject' => 'Order Proof Declined – Order #{{order_number}}',
            'heading' => 'Order Proof Declined',
            'body_text' => "Hi {{customer_name}},\n\nThe design proof for order {{order_number}} ({{proof_title}}) has been marked as rejected. Reason: {{rejection_reason}}.\n\nOur customer support and design specialists will reach out to discuss how to best adjust the design to match your requirements.",
            'button_text' => 'View Order Proof',
            'button_url' => '{{proof_url}}',
            'variables' => ['customer_name', 'customer_email', 'order_number', 'proof_title', 'rejection_reason', 'proof_url', 'site_name'],
        ],
        'order_proof_comment_added' => [
            'label' => 'New Comment on Order Proof',
            'category' => 'orders',
            'subject' => 'New Message Regarding Proof for Order #{{order_number}}',
            'heading' => 'New Comment on Your Proof',
            'body_text' => "Hi {{customer_name}},\n\nA new message was posted regarding the proof for order {{order_number}} by {{commenter_name}}:\n\n\"{{comment_text}}\"",
            'button_text' => 'Reply to Message',
            'button_url' => '{{proof_url}}',
            'variables' => ['customer_name', 'customer_email', 'order_number', 'commenter_name', 'comment_text', 'proof_url', 'site_name'],
        ],
        'order_verification_required' => [
            'label' => 'Order Verification Required',
            'category' => 'orders',
            'subject' => 'Action Required: Please Verify Your Order #{{order_number}}',
            'heading' => 'Order Verification Required',
            'body_text' => "Hi {{customer_name}},\n\nTo safeguard your account and ensure authorized payment, we require additional verification for order {{order_number}}.\n\nReason: {{verification_reason}}\nRequired Documents: {{required_documents}}\nDeadline: {{deadline_date}}\n\nPlease submit the requested documents through your secure customer portal before the deadline to prevent delay or automatic cancellation.",
            'button_text' => 'Complete Verification',
            'button_url' => '{{verification_url}}',
            'variables' => ['customer_name', 'customer_email', 'order_number', 'verification_reason', 'required_documents', 'deadline_date', 'verification_url', 'site_name'],
        ],
        'order_verification_submitted' => [
            'label' => 'Verification Documents Submitted',
            'category' => 'orders',
            'subject' => 'Documents Received – Order #{{order_number}} Under Review',
            'heading' => 'Verification Documents Received',
            'body_text' => "Hi {{customer_name}},\n\nThank you for submitting your verification documents for order {{order_number}}.\n\nOur security team is actively reviewing the provided documentation. Verification is typically completed within 1 business day. We will notify you immediately once the review is finalized.",
            'button_text' => 'Check Verification Status',
            'button_url' => '{{verification_url}}',
            'variables' => ['customer_name', 'customer_email', 'order_number', 'verification_url', 'site_name'],
        ],
        'order_verification_approved' => [
            'label' => 'Order Verification Approved',
            'category' => 'orders',
            'subject' => 'Verification Approved – Order #{{order_number}} Cleared',
            'heading' => 'Order Verification Approved',
            'body_text' => "Hi {{customer_name}},\n\nGreat news! Your verification documents for order {{order_number}} have been successfully reviewed and approved.\n\nYour order is now fully cleared and has resumed normal processing and production.",
            'button_text' => 'View Order Status',
            'button_url' => '{{order_url}}',
            'variables' => ['customer_name', 'customer_email', 'order_number', 'order_url', 'site_name'],
        ],
        'order_verification_declined' => [
            'label' => 'Order Verification Declined',
            'category' => 'orders',
            'subject' => 'Verification Unsuccessful – Order #{{order_number}}',
            'heading' => 'Order Verification Declined',
            'body_text' => "Hi {{customer_name}},\n\nWe were unable to verify your order {{order_number}}. Reason: {{decline_reason}}.\n\nAs a result, your order cannot be processed. Any authorizations will be released or refunded according to our standard policies. If you have questions or believe this was an error, please contact our support team.",
            'button_text' => 'Contact Support',
            'button_url' => '{{support_url}}',
            'variables' => ['customer_name', 'customer_email', 'order_number', 'decline_reason', 'support_url', 'site_name'],
        ],
        'order_verification_more_info' => [
            'label' => 'Additional Verification Info Needed',
            'category' => 'orders',
            'subject' => 'Action Needed: Additional Documents for Order #{{order_number}}',
            'heading' => 'Additional Information Needed',
            'body_text' => "Hi {{customer_name}},\n\nAfter reviewing your submitted documents for order {{order_number}}, our team requires additional details or clearer images:\n\n\"{{notes}}\"\nRequired Documents: {{required_documents}}\n\nPlease upload the requested items as soon as possible so we can proceed with your order.",
            'button_text' => 'Upload Additional Documents',
            'button_url' => '{{verification_url}}',
            'variables' => ['customer_name', 'customer_email', 'order_number', 'notes', 'required_documents', 'verification_url', 'site_name'],
        ],
        'order_in_production' => [
            'label' => 'Order In Production',
            'category' => 'orders',
            'subject' => 'Production Started – Order #{{order_number}} is Being Made',
            'heading' => 'Your Order is in Production',
            'body_text' => "Hi {{customer_name}},\n\nExciting news! Your order {{order_number}} has officially entered production. Our artisans and precision embroidery machines are crafting your custom items.\n\nEstimated completion: {{estimated_delivery}}.",
            'button_text' => 'Track Order Progress',
            'button_url' => '{{order_url}}',
            'variables' => ['customer_name', 'customer_email', 'order_number', 'estimated_delivery', 'order_url', 'site_name'],
        ],
        'order_ready_for_pickup' => [
            'label' => 'Order Ready For Pickup',
            'category' => 'orders',
            'subject' => 'Ready for Pickup! Order #{{order_number}}',
            'heading' => 'Your Order is Ready for Pickup',
            'body_text' => "Hi {{customer_name}},\n\nYour order {{order_number}} is packaged and ready for collection!\n\nPickup Location: {{pickup_location_name}}\nAddress: {{pickup_address}}\nHours: {{pickup_hours}}\n\nPlease bring your order confirmation number or a valid photo ID when picking up.",
            'button_text' => 'View Pickup Directions',
            'button_url' => '{{order_url}}',
            'variables' => ['customer_name', 'customer_email', 'order_number', 'pickup_location_name', 'pickup_address', 'pickup_hours', 'order_url', 'site_name'],
        ],
        'order_on_hold' => [
            'label' => 'Order Temporarily On Hold',
            'category' => 'orders',
            'subject' => 'Notice: Order #{{order_number}} is Temporarily On Hold',
            'heading' => 'Order Temporarily On Hold',
            'body_text' => "Hi {{customer_name}},\n\nYour order {{order_number}} has been temporarily placed on hold.\n\nReason: {{hold_reason}}\n\nPlease review your order or contact our customer support team to resolve this issue as soon as possible.",
            'button_text' => 'Review Order Details',
            'button_url' => '{{order_url}}',
            'variables' => ['customer_name', 'customer_email', 'order_number', 'hold_reason', 'order_url', 'site_name'],
        ],
        'order_cancellation_requested' => [
            'label' => 'Order Cancellation Requested',
            'category' => 'orders',
            'subject' => 'Cancellation Request Received – Order #{{order_number}}',
            'heading' => 'Cancellation Request Received',
            'body_text' => "Hi {{customer_name}},\n\nWe received your request to cancel order {{order_number}}.\n\nReason: {{cancellation_reason}}\n\nOur fulfillment team is checking if your order can still be cancelled before production or shipping begins. We will notify you with the outcome shortly.",
            'button_text' => 'View Order',
            'button_url' => '{{order_url}}',
            'variables' => ['customer_name', 'customer_email', 'order_number', 'cancellation_reason', 'order_url', 'site_name'],
        ],
        'order_cancellation_rejected' => [
            'label' => 'Order Cancellation Request Declined',
            'category' => 'orders',
            'subject' => 'Unable to Cancel Order #{{order_number}}',
            'heading' => 'Cancellation Request Declined',
            'body_text' => "Hi {{customer_name}},\n\nWe were unable to cancel order {{order_number}} because it has already progressed past the cancellation window into active production or shipment.\n\nReason: {{reason}}\n\nYour order will proceed towards delivery as scheduled. Please check our return policy if you wish to return eligible items once received.",
            'button_text' => 'Track Your Package',
            'button_url' => '{{order_url}}',
            'variables' => ['customer_name', 'customer_email', 'order_number', 'reason', 'order_url', 'site_name'],
        ],
        'customer_refund_more_info' => [
            'label' => 'Customer Refund More Info Needed',
            'category' => 'financial',
            'subject' => 'Additional Information Needed for Refund – Order #{{order_number}}',
            'heading' => 'Information Needed for Refund',
            'body_text' => "Hi {{customer_name}},\n\nRegarding your refund request for order {{order_number}}, we need additional information:\n\n\"{{notes}}\"\n\nPlease reply with the requested information so our finance team can proceed.",
            'button_text' => 'View Refund Status',
            'button_url' => '{{refund_url}}',
            'variables' => ['customer_name', 'customer_email', 'order_number', 'notes', 'refund_url', 'site_name'],
        ],
        'dispute_opened' => [
            'label' => 'Dispute Case Opened',
            'category' => 'support',
            'subject' => 'Dispute Received – Case #{{dispute_number}} (Order #{{order_number}})',
            'heading' => 'Dispute Case Received',
            'body_text' => "Hi {{customer_name}},\n\nWe have received your dispute case {{dispute_number}} regarding order {{order_number}}.\n\nIssue Type: {{dispute_type}}\nDescription: {{description}}\n\nA dedicated resolution specialist has been assigned to investigate the matter. We will keep you updated as we review the case.",
            'button_text' => 'View Dispute Status',
            'button_url' => '{{dispute_url}}',
            'variables' => ['customer_name', 'customer_email', 'dispute_number', 'order_number', 'dispute_type', 'description', 'dispute_url', 'site_name'],
        ],
        'dispute_under_review' => [
            'label' => 'Dispute Under Review',
            'category' => 'support',
            'subject' => 'Dispute Update: Case #{{dispute_number}} Under Review',
            'heading' => 'Dispute Investigation In Progress',
            'body_text' => "Hi {{customer_name}},\n\nYour dispute case {{dispute_number}} for order {{order_number}} is currently under formal review by our dispute management team.\n\nWe are actively investigating the order history, communication logs, and evidence provided. We aim to reach a resolution within 2-3 business days.",
            'button_text' => 'Check Dispute Timeline',
            'button_url' => '{{dispute_url}}',
            'variables' => ['customer_name', 'customer_email', 'dispute_number', 'order_number', 'dispute_url', 'site_name'],
        ],
        'dispute_awaiting_response' => [
            'label' => 'Dispute Awaiting Customer Response',
            'category' => 'support',
            'subject' => 'Action Required: Response Needed on Dispute #{{dispute_number}}',
            'heading' => 'Response Needed for Dispute',
            'body_text' => "Hi {{customer_name}},\n\nOur team needs additional information from you regarding dispute {{dispute_number}} (Order {{order_number}}):\n\n\"{{notes}}\"\n\nPlease submit your reply through your customer portal so we can proceed toward a fair resolution.",
            'button_text' => 'Respond to Dispute',
            'button_url' => '{{dispute_url}}',
            'variables' => ['customer_name', 'customer_email', 'dispute_number', 'order_number', 'notes', 'dispute_url', 'site_name'],
        ],
        'dispute_resolved' => [
            'label' => 'Dispute Resolved',
            'category' => 'support',
            'subject' => 'Dispute Resolved – Case #{{dispute_number}} (Order #{{order_number}})',
            'heading' => 'Dispute Resolved',
            'body_text' => "Hi {{customer_name}},\n\nYour dispute case {{dispute_number}} regarding order {{order_number}} has been resolved.\n\nResolution Summary: {{resolution_notes}}\n\nThank you for working with us to reach a solution. Please review the full case details in your portal.",
            'button_text' => 'View Resolution',
            'button_url' => '{{dispute_url}}',
            'variables' => ['customer_name', 'customer_email', 'dispute_number', 'order_number', 'resolution_notes', 'dispute_url', 'site_name'],
        ],
        'dispute_closed' => [
            'label' => 'Dispute Case Closed',
            'category' => 'support',
            'subject' => 'Dispute Closed – Case #{{dispute_number}}',
            'heading' => 'Dispute Case Closed',
            'body_text' => "Hi {{customer_name}},\n\nThis is to notify you that dispute case {{dispute_number}} for order {{order_number}} has been closed.\n\nIf you have any further questions or require additional assistance, please reach out to our support team.",
            'button_text' => 'View Case Record',
            'button_url' => '{{dispute_url}}',
            'variables' => ['customer_name', 'customer_email', 'dispute_number', 'order_number', 'dispute_url', 'site_name'],
        ],
    ];

    public function ensureDefaultTemplates(): void
    {
        foreach (self::EVENTS as $eventKey => $definition) {
            EmailTemplate::firstOrCreate(
                ['event_key' => $eventKey],
                [
                    'name' => $definition['label'],
                    'slug' => $eventKey,
                    'subject' => $definition['subject'],
                    'category' => $definition['category'],
                    'heading' => $definition['heading'],
                    'body_text' => $definition['body_text'],
                    'button_text' => $definition['button_text'] ?? null,
                    'button_url' => $definition['button_url'] ?? null,
                    'footer_text' => 'Mecarvi Embroidery',
                    'status' => 'published',
                    'variables' => $definition['variables'],
                    'send_to_customer' => true,
                    'send_to_admin' => false,
                    'admin_recipients' => [],
                ]
            );
        }
    }

    public function setting(): EmailNotificationSetting
    {
        $setting = EmailNotificationSetting::firstOrCreate([], [
            'is_enabled' => true,
            'mailer' => 'smtp',
            'smtp_host' => env('MAIL_HOST'),
            'smtp_port' => env('MAIL_PORT', 587),
            'smtp_username' => env('MAIL_USERNAME'),
            'smtp_encryption' => env('MAIL_ENCRYPTION', 'tls'),
            'from_name' => 'Mecarvi Embroidery',
            'from_email' => config('mail.from.address', 'noreply@mecarvi.com'),
        ]);

        if (empty($setting->from_name) || strtolower($setting->from_name) === 'laravel') {
            $setting->from_name = 'Mecarvi Embroidery';
            $setting->save();
        }

        return $setting;
    }

    public function sendEvent(string $eventKey, array $data, ?string $customerEmail = null): array
    {
        $this->ensureDefaultTemplates();

        if (! $customerEmail) {
            $customerEmail = $data['customer_email'] ?? $data['email'] ?? $data['contact_email'] ?? $data['recipient_email'] ?? $data['supplier_email'] ?? null;
        }

        $category = $this->getEventNotificationCategory($eventKey);
        $setting = $this->setting();
        $template = EmailTemplate::where('event_key', $eventKey)->first();
        $results = [];

        // Trigger SMS notification if a phone number is provided
        if (isset($data['customer_phone']) && $data['customer_phone']) {
            $this->sendSmsNotification($eventKey, $data, $data['customer_phone']);
        }

        if (! $setting->is_enabled) {
            return [$this->logSkipped($eventKey, $template, $customerEmail ?: 'unknown', 'Email sending is disabled.', $data)];
        }

        if (! $template || $template->status !== 'published') {
            return [$this->logSkipped($eventKey, $template, $customerEmail ?: 'unknown', 'No active template for this event.', $data)];
        }

        if ($template->send_to_customer && $customerEmail) {
            if (! $this->isNotificationEnabledForUser($customerEmail, $category, 'email')) {
                $results[] = $this->logSkipped(
                    $eventKey,
                    $template,
                    $customerEmail,
                    "Notification skipped: customer has disabled email notifications for this category ({$category}).",
                    $data
                );
            } else {
                $results[] = $this->sendTo($eventKey, $template, $customerEmail, 'customer', $data);
            }
        }

        if ($template->send_to_admin) {
            foreach ($this->adminRecipients($template, $setting) as $adminEmail) {
                $results[] = $this->sendTo($eventKey, $template, $adminEmail, 'admin', $data);
            }
        }

        return $results;
    }

    public function sendTest(string $recipientEmail, array $override = []): EmailNotificationLog
    {
        $this->ensureDefaultTemplates();

        $template = EmailTemplate::where('event_key', $override['event_key'] ?? 'order_placed')->first();
        $data = array_merge([
            'customer_name' => 'Test Customer',
            'customer_email' => $recipientEmail,
            'order_number' => 'ORD-TEST-001',
            'order_total' => '$125.00',
            'order_status' => 'pending',
            'site_name' => config('app.name', 'Mecarvi Embroidery'),
            'tracking_number' => 'TEST123456',
            'tracking_url' => url('/'),
            'amount' => '$100.00',
            'amount_due' => '$100.00',
            'due_date' => date('Y-m-d', strtotime('+7 days')),
            'expiry_date' => date('Y-m-d', strtotime('+30 days')),
            'expiry_minutes' => '30',
            'new_balance' => '$250.00',
            'quote_number' => 'QUO-TEST-001',
            'pin_code' => '123456',
            'verification_code' => '123456',
            'gift_card_code' => 'GIFT-TEST-1234',
            'gift_card_balance' => '$100.00',
            'membership_plan' => 'Gold VIP Membership',
            'new_tier' => 'Royal Platinum Tier',
            'reason' => 'Scheduled account update',
            'supplier_name' => 'Test Supplier',
            'artisan_name' => 'Test Artisan',
            'reference_number' => 'REF-998877',
            'claim_id' => 'CLM-001',
            'disbursement_id' => 'DISB-001',
            'payout_id' => 'PAY-001',
            'request_id' => 'REQ-001',
            'product_name' => 'Custom Embroidered Jacket',
            'question' => 'Is this machine washable?',
            'answer' => 'Yes, machine wash on gentle cycle.',
            'reply' => 'Thank you for your response!',
            'message_preview' => 'Hello, I have a question regarding my order.',
            'sender_name' => 'Mecarvi Support',
            'recipient_name' => 'Test Customer',
            'commission_amount' => '$25.00',
            'referral_code' => 'REF123',
            'bonus_amount' => '$10.00',
            'points_redeemed' => '500',
            'reward_description' => '$5 Discount Voucher',
            'next_billing_date' => date('Y-m-d', strtotime('+30 days')),
            'new_email' => $recipientEmail,
            // Order Proof Variables
            'proof_title' => 'Left Chest Logo Proof',
            'proof_version' => 'Version 2',
            'proof_type' => 'Embroidery Digital Mockup',
            'response_due_date' => date('M j, Y', strtotime('+3 days')),
            'proof_url' => url('/order-proof?order_number=ORD-TEST-001'),
            'rejection_reason' => 'Thread colors do not match brand guidelines.',
            'revision_notes' => 'Please decrease logo width to 3.5 inches and use Pantone 286C blue.',
            'commenter_name' => 'Senior Digitizer',
            'comment_text' => 'We updated the stitch density on the text outline for better clarity.',
            // Order Verification Variables
            'verification_reason' => 'Payment address and cardholder identity confirmation.',
            'required_documents' => 'Government-Issued Photo ID, Payment Card (Front & Back)',
            'deadline_date' => date('M j, Y • 05:00 PM', strtotime('+3 days')),
            'verification_url' => url('/order-verification?order_number=ORD-TEST-001'),
            'decline_reason' => 'Document image unreadable or mismatched name.',
            'notes' => 'Please re-upload a higher resolution photo of your ID.',
            // Production & Pickup Variables
            'pickup_location_name' => 'Mecarvi Studio & Showroom',
            'pickup_address' => '233 Stray Way Circle, Suite B, McDonough, GA 30253',
            'pickup_hours' => 'Mon-Fri 9:00 AM - 6:00 PM',
            'hold_reason' => 'Awaiting confirmation on embroidery placement dimensions.',
            'cancellation_reason' => 'Customer requested cancellation prior to production.',
            'order_url' => url('/orders/ORD-TEST-001'),
            'support_url' => url('/support-tickets'),
            'refund_url' => url('/refunds'),
            // Dispute Variables
            'dispute_number' => 'DSP-20260910-123456',
            'dispute_type' => 'Item Stitching Defect',
            'dispute_status' => 'Under Review',
            'description' => 'The logo threading has loose ends on the collar.',
            'resolution_notes' => 'A free replacement has been scheduled for priority production.',
            'dispute_url' => url('/order-disputes?dispute_number=DSP-20260910-123456'),
        ], $override['data'] ?? []);

        return $this->sendTo($template?->event_key ?: 'test_email', $template, $recipientEmail, 'test', $data);
    }

    public function sendOrderEvent(string $eventKey, EcommerceOrder $order, array $extra = []): array
    {
        $order->loadMissing(['items', 'pickupLocation']);
        $payload = array_merge($this->orderData($order), $extra);

        return $this->sendEvent($eventKey, $payload, $order->customer_email);
    }

    public function sendProofEvent(string $eventKey, EcommerceOrderProof $proof, array $extra = []): array
    {
        $proof->loadMissing(['order.items', 'order.user']);
        $order = $proof->order;
        $customerEmail = $order?->customer_email ?: $order?->user?->email;
        $customerName = $order?->customer_name ?: ($order?->user?->name ?: 'Customer');
        $orderNumber = $order?->order_number ?: ('ORD-' . $proof->order_id);
        $meta = is_array($proof->metadata) ? $proof->metadata : [];

        $data = array_merge([
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'customer_phone' => $order?->customer_phone,
            'order_number' => $orderNumber,
            'proof_title' => $proof->title ?: 'Design Proof',
            'proof_version' => $meta['version'] ?? ($meta['proof_version'] ?? 'Version 1'),
            'proof_type' => $proof->proof_type ?: 'Embroidery Digital Proof',
            'response_due_date' => $proof->expires_at ? $proof->expires_at->format('M j, Y') : (now()->addDays(3)->format('M j, Y')),
            'rejection_reason' => $proof->rejection_reason ?: ($extra['rejection_reason'] ?? 'Design revisions requested'),
            'revision_notes' => $proof->rejection_reason ?: ($extra['revision_notes'] ?? ($extra['reason'] ?? 'Revisions requested')),
            'commenter_name' => $extra['commenter_name'] ?? 'Mecarvi Design Team',
            'comment_text' => $extra['comment_text'] ?? ($meta['message_to_customer'] ?? ''),
            'proof_url' => url('/order-proof?order_number=' . urlencode($orderNumber)),
            'order_url' => url('/orders/' . urlencode($orderNumber)),
            'site_name' => config('app.name', 'Mecarvi Embroidery'),
        ], $extra);

        return $this->sendEvent($eventKey, $data, $customerEmail);
    }

    public function sendVerificationEvent(string $eventKey, EcommerceOrderVerification $verification, array $extra = []): array
    {
        $verification->loadMissing(['order.items', 'user']);
        $order = $verification->order;
        $user = $verification->user;
        $customerEmail = $order?->customer_email ?: $user?->email;
        $customerName = $order?->customer_name ?: ($user?->name ?: 'Customer');
        $orderNumber = $verification->order_number ?: ($order?->order_number ?? 'Order');

        $reqDocs = $verification->required_documents;
        if (is_array($reqDocs)) {
            $reqDocsStr = implode(', ', $reqDocs);
        } else {
            $reqDocsStr = (string) ($reqDocs ?: 'Government-issued ID or Payment Card verification');
        }

        $deadlineStr = $verification->deadline_at ? $verification->deadline_at->format('M j, Y • h:i A') : (now()->addDays(3)->format('M j, Y'));

        $data = array_merge([
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'customer_phone' => $order?->customer_phone,
            'order_number' => $orderNumber,
            'verification_reason' => $verification->flag_reason ?: ($verification->reason_text ?: 'Identity and payment security verification'),
            'required_documents' => $reqDocsStr,
            'deadline_date' => $deadlineStr,
            'decline_reason' => $verification->decline_reason ?: ($extra['decline_reason'] ?? 'Unable to verify supporting documents'),
            'notes' => $extra['notes'] ?? ($verification->reason_text ?: 'Please provide the requested documents to clear your order.'),
            'verification_url' => url('/order-verification?order_number=' . urlencode($orderNumber)),
            'order_url' => url('/orders/' . urlencode($orderNumber)),
            'support_url' => url('/support-tickets'),
            'site_name' => config('app.name', 'Mecarvi Embroidery'),
        ], $extra);

        return $this->sendEvent($eventKey, $data, $customerEmail);
    }

    public function sendDisputeEvent(string $eventKey, EcommerceDispute $dispute, array $extra = []): array
    {
        $dispute->loadMissing(['order.items', 'user']);
        $order = $dispute->order;
        $user = $dispute->user;
        $customerEmail = $dispute->email ?: ($order?->customer_email ?: $user?->email);
        $customerName = $dispute->customer_name ?: ($order?->customer_name ?: ($user?->name ?: 'Customer'));
        $orderNumber = $dispute->order_number ?: ($order?->order_number ?? 'N/A');

        $data = array_merge([
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'customer_phone' => $dispute->phone ?: $order?->customer_phone,
            'order_number' => $orderNumber,
            'dispute_number' => $dispute->dispute_number,
            'dispute_type' => $dispute->type ?: 'Order Dispute',
            'dispute_status' => $dispute->status ?: 'Open',
            'description' => $dispute->description ?: 'Dispute details submitted.',
            'notes' => $extra['notes'] ?? ($extra['message'] ?? 'Please provide additional clarification.'),
            'resolution_notes' => $extra['resolution_notes'] ?? ($extra['notes'] ?? 'The dispute has been reviewed and resolved.'),
            'dispute_url' => url('/order-disputes?dispute_number=' . urlencode($dispute->dispute_number)),
            'order_url' => url('/orders/' . urlencode($orderNumber)),
            'site_name' => config('app.name', 'Mecarvi Embroidery'),
        ], $extra);

        return $this->sendEvent($eventKey, $data, $customerEmail);
    }

    public function orderData(EcommerceOrder $order): array
    {
        $amountStr = '$' . number_format((float) $order->total_amount, 2);
        $pickup = $order->pickupLocation;
        $meta = is_array($order->metadata) ? $order->metadata : [];

        return [
            'customer_name' => $order->customer_name ?: 'Customer',
            'customer_email' => $order->customer_email,
            'customer_phone' => $order->customer_phone,
            'order_number' => $order->order_number,
            'amount' => $amountStr,
            'order_total' => $amountStr,
            'order_status' => Str::headline((string) $order->status),
            'tracking_number' => $order->tracking_number ?: '',
            'tracking_url' => $order->tracking_url ?: '',
            'reason' => $order->notes ?: 'Administrative status update',
            'delay_reason' => $order->notes ?: 'Scheduled processing update',
            'estimated_delivery' => optional($order->estimated_delivery_at)->format('M j, Y') ?: '',
            'pickup_location_name' => $pickup?->name ?: 'Mecarvi Embroidery Store & Pickup',
            'pickup_address' => $pickup?->address ?: '233 Stray Way Circle, Suite B, McDonough, GA 30253',
            'pickup_hours' => $pickup?->hours ?: 'Mon-Fri 9:00 AM - 6:00 PM',
            'hold_reason' => $meta['hold_reason'] ?? ($order->notes ?: 'Order is pending artwork or specification review'),
            'cancellation_reason' => $meta['cancellation_reason'] ?? ($order->notes ?: 'Order cancelled by customer or administrator'),
            'order_url' => url('/orders/' . urlencode($order->order_number)),
            'support_url' => url('/support-tickets'),
            'site_name' => config('app.name', 'Mecarvi Embroidery'),
        ];
    }

    private function sendTo(string $eventKey, ?EmailTemplate $template, string $recipientEmail, string $recipientType, array $data): EmailNotificationLog
    {
        $subject = $this->replaceVariables($template?->subject ?: $data['subject'] ?? 'Mecarvi Embroidery', $data);

        $log = EmailNotificationLog::create([
            'event_key' => $eventKey,
            'email_template_id' => $template?->id,
            'recipient_email' => $recipientEmail,
            'recipient_type' => $recipientType,
            'subject' => $subject,
            'status' => 'pending',
            'payload' => $data,
        ]);

        try {
            $setting = $this->setting();
            $this->applyMailConfig($setting);

            $fromName = ($setting->from_name && strtolower($setting->from_name) !== 'laravel')
                ? $setting->from_name
                : 'Mecarvi Embroidery';
            $fromEmail = $setting->from_email ?: config('mail.from.address', 'noreply@mecarvi.com');

            Mail::send([], [], function ($message) use ($recipientEmail, $subject, $fromEmail, $fromName, $template, $data) {
                $message->to($recipientEmail)
                    ->from($fromEmail, $fromName)
                    ->subject($subject);

                $html = $this->renderHtml($template, $data, $message);
                $message->html($html);
            });

            $log->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            Log::error('Email notification failed: ' . $e->getMessage(), [
                'event_key' => $eventKey,
                'recipient' => $recipientEmail,
            ]);
        }

        return $log->fresh();
    }

    private function logSkipped(string $eventKey, ?EmailTemplate $template, string $recipientEmail, string $reason, array $data): EmailNotificationLog
    {
        return EmailNotificationLog::create([
            'event_key' => $eventKey,
            'email_template_id' => $template?->id,
            'recipient_email' => $recipientEmail,
            'recipient_type' => 'system',
            'subject' => $template?->subject,
            'status' => 'skipped',
            'error_message' => $reason,
            'payload' => $data,
        ]);
    }

    private function adminRecipients(EmailTemplate $template, EmailNotificationSetting $setting): array
    {
        $recipients = collect($template->admin_recipients ?? [])
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->values()
            ->all();

        if (empty($recipients) && filter_var($setting->reply_to_email, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = $setting->reply_to_email;
        }

        if (empty($recipients) && filter_var($setting->from_email, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = $setting->from_email;
        }

        return array_values(array_unique($recipients));
    }

    private function applyMailConfig(EmailNotificationSetting $setting): void
    {
        try {
            Mail::purge('smtp');
        } catch (\Throwable) {}

        $host = $setting->smtp_host ?: env('MAIL_HOST', '127.0.0.1');
        $port = $setting->smtp_port ?: env('MAIL_PORT', 587);
        $encryption = $setting->smtp_encryption ?: env('MAIL_ENCRYPTION', null);
        $username = $setting->smtp_username ?: env('MAIL_USERNAME');
        $password = $setting->getDecryptedSmtpPassword() ?: env('MAIL_PASSWORD');

        $fromName = ($setting->from_name && strtolower($setting->from_name) !== 'laravel')
            ? $setting->from_name
            : 'Mecarvi Embroidery';
        $fromEmail = $setting->from_email ?: env('MAIL_FROM_ADDRESS', config('mail.from.address', 'noreply@mecarvi.com'));

        Config::set('mail.default', 'smtp');
        Config::set('mail.mailers.smtp.host', $host);
        Config::set('mail.mailers.smtp.port', $port);
        Config::set('mail.mailers.smtp.encryption', $encryption);
        Config::set('mail.mailers.smtp.username', $username);
        Config::set('mail.mailers.smtp.password', $password);
        Config::set('mail.from.address', $fromEmail);
        Config::set('mail.from.name', $fromName);
    }

    private function renderHtml(?EmailTemplate $template, array $data, ?object $message = null): string
    {
        $heading = $this->replaceVariables($template?->heading ?: $template?->name ?: $data['heading'] ?? 'Mecarvi Embroidery', $data);
        $body = $this->textToHtml($this->replaceVariables($template?->body_text ?: $template?->body_html ?: $data['body_text'] ?? $data['body'] ?? '', $data));
        $buttonText = trim($this->replaceVariables($template?->button_text ?: $data['button_text'] ?? '', $data));
        $buttonUrl = trim($this->replaceVariables($template?->button_url ?: $data['button_url'] ?? '', $data));
        $footerText = $this->replaceVariables($template?->footer_text ?: $data['footer_text'] ?? 'Mecarvi Embroidery', $data);
        $imageUrl = trim($this->replaceVariables($template?->image_url ?: $data['image_url'] ?? '', $data));
        $logoUrl = trim($this->replaceVariables($template?->logo_url ?: $data['logo_url'] ?? '', $data));
        $logoPosition = $template?->logo_position ?: $data['logo_position'] ?? 'left';
        $button = '';
        $imageHtml = '';
        $headerHtml = '<div style="font-size:16px;font-weight:800;color:#111827;">Mecarvi Embroidery</div>';

        $resolveImageSrc = function (string $url) use ($message): string {
            $url = trim($url);
            if ($url === '') {
                return '';
            }

            $path = null;
            if (str_contains($url, '/storage/')) {
                $path = Str::after($url, '/storage/');
            } elseif (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
                $path = ltrim($url, '/');
            }

            if ($path) {
                $localFilePath = storage_path('app/public/' . $path);
                if (!file_exists($localFilePath)) {
                    $localFilePath = public_path('storage/' . $path);
                }
                if (!file_exists($localFilePath)) {
                    $localFilePath = public_path($path);
                }
                if (file_exists($localFilePath) && $message && method_exists($message, 'embed')) {
                    return $message->embed($localFilePath);
                }
            }

            if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
                return $url;
            }

            return asset(ltrim($url, '/'));
        };

        $imageUrl = $resolveImageSrc($imageUrl);
        $logoUrl = $resolveImageSrc($logoUrl);

        if ($buttonText !== '' && $buttonUrl !== '') {
            $safeUrl = e($buttonUrl);
            $button = "<p style=\"margin:28px 0;\"><a href=\"{$safeUrl}\" style=\"display:inline-block;background:#111827;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:8px;font-weight:700;\">".e($buttonText)."</a></p>";
        }

        if ($imageUrl !== '') {
            $safeImageUrl = e($imageUrl);
            $imageHtml = "<div style=\"margin-bottom:24px;text-align:center;\"><img src=\"{$safeImageUrl}\" alt=\"Notification Image\" style=\"max-width:100%;height:auto;border-radius:8px;display:block;margin:0 auto;\" /></div>";
        }

        if ($logoUrl !== '' && $logoPosition !== 'hidden') {
            $safeLogoUrl = e($logoUrl);
            $textAlign = in_array($logoPosition, ['left', 'center', 'right']) ? $logoPosition : 'left';
            $marginStyle = 'margin:0;';
            if ($textAlign === 'center') {
                $marginStyle = 'margin:0 auto;';
            } elseif ($textAlign === 'right') {
                $marginStyle = 'margin:0 0 0 auto;';
            }
            $headerHtml = "<div style=\"text-align:{$textAlign};\">"
                . "<img src=\"{$safeLogoUrl}\" alt=\"Logo\" style=\"max-height:48px; width:auto; display:block; {$marginStyle}\" />"
                . "</div>";
        }

        return '<!doctype html><html><body style="margin:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">'
            . '<div style="display:none;max-height:0;overflow:hidden;">'.e($template?->preview_text ?: $data['preview_text'] ?? '').'</div>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;padding:32px 16px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">'
            . '<tr><td style="padding:24px 32px;border-bottom:1px solid #e5e7eb;">' . $headerHtml . '</td></tr>'
            . '<tr><td style="padding:32px;">'.$imageHtml.'<h1 style="margin:0 0 18px;font-size:24px;line-height:1.25;color:#111827;">'.e($heading).'</h1>'
            . '<div style="font-size:15px;line-height:1.7;color:#374151;">'.$body.'</div>'.$button.'</td></tr>'
            . '<tr><td style="padding:22px 32px;background:#f9fafb;border-top:1px solid #e5e7eb;font-size:12px;line-height:1.5;color:#6b7280;">'.e($footerText).'</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    private function textToHtml(string $text): string
    {
        $paragraphs = preg_split("/\R{2,}/", trim($text));

        return collect($paragraphs ?: [])
            ->filter(fn ($paragraph) => trim($paragraph) !== '')
            ->map(fn ($paragraph) => '<p style="margin:0 0 16px;">' . nl2br(trim($paragraph)) . '</p>')
            ->implode('');
    }

    public function replaceVariables(string $content, array $data): string
    {
        foreach ($data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $val = $value === null ? '' : (string) $value;
            $content = str_replace('{{' . $key . '}}', $val, $content);
            $content = str_replace('{{ ' . $key . ' }}', $val, $content);
        }

        // Clean up remaining unreplaced curly-brace variables so raw placeholders aren't displayed to recipients
        return preg_replace('/\{\{\s*[a-zA-Z0-9_]+\s*\}\}/', '', $content);
    }

    public function getEventNotificationCategory(string $eventKey): string
    {
        return match ($eventKey) {
            'order_placed',
            'order_confirmed',
            'order_processing',
            'order_in_production',
            'order_ready_for_pickup',
            'order_on_hold',
            'order_shipped',
            'order_out_for_delivery',
            'order_delivered',
            'order_delayed',
            'order_declined',
            'order_cancelled',
            'order_cancellation_requested',
            'order_cancellation_rejected',
            'order_refunded',
            'order_status_changed',
            'customer_cancellation',
            'customer_order_cancellation',
            'order_proof_ready',
            'order_proof_approved',
            'order_proof_revision_requested',
            'order_proof_rejected',
            'order_proof_comment_added',
            'order_verification',
            'order_verification_required',
            'order_verification_submitted',
            'order_verification_approved',
            'order_verification_declined',
            'order_verification_more_info',
            'quote_submitted',
            'customer_qoute_request',
            'approved_qoute' => 'orders_updates',

            'customer_due_soon',
            'customer_refund',
            'customer_refund_more_info',
            'customer_pay_out',
            'customer_credit_requested',
            'customer_credit_verification',
            'customer_loan_disburse',
            'bank_credit_supplier',
            'customer_artisan_commission_withdraw_approved',
            'customer_artisan_withdraw_request_cancelled',
            'customer_artisan_withdraw_request' => 'payments_billing',

            'change_password_confirmation',
            'change_email_confirmation',
            'pin_verification',
            'user_registered',
            'customer_registration_bonus' => 'account_security',

            'gift_card_issued',
            'gift_card_redeemed',
            'gift_card_expired',
            'gift_card_balance_update' => 'gift_cards',

            'customer_membership_subscription',
            'customer_membership_subscription_renew',
            'customer_membership_expire',
            'customer_tier_upgradation',
            'customer_membership_salary_change_pending_approval' => 'memberships',

            'loyalty_point_redemption',
            'customer_add_balance',
            'customer_sub_balance',
            'wallet_deposit' => 'loyalty_rewards',

            'promotions',
            'newsletter',
            'marketing_campaign' => 'marketing_promotions',

            'customer_referral_commission',
            'referral_product_commission' => 'affiliate_program',

            'dispute_opened',
            'dispute_under_review',
            'dispute_awaiting_response',
            'dispute_resolved',
            'dispute_closed',
            'message_sent',
            'message_from_customer',
            'customer_product_question',
            'customer_product_question_reply' => 'support_tickets',

            'system_maintenance',
            'service_update',
            'protection_plan_admin_reject',
            'protection_plan_admin_accept',
            'protection_plan_claim_amount',
            'protection_plan_claim_approved',
            'protection_plan_claim_submitted' => 'system_alerts',

            default => 'orders_updates',
        };
    }

    public function isNotificationEnabledForUser(?string $identifier, string $category, string $channel = 'email'): bool
    {
        if (! $identifier) {
            return true;
        }

        $user = null;
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $user = \App\Models\User::whereRaw('LOWER(email) = ?', [strtolower(trim($identifier))])->first();
        } else {
            $cleanPhone = preg_replace('/[^\d+]/', '', $identifier);
            $user = \App\Models\User::where('phone', $identifier)
                ->orWhere('phone', $cleanPhone)
                ->first();
        }

        $defaults = [
            'orders_updates' => ['email' => true, 'sms' => true, 'push' => true],
            'payments_billing' => ['email' => true, 'sms' => true, 'push' => false],
            'account_security' => ['email' => true, 'sms' => true, 'push' => true],
            'gift_cards' => ['email' => true, 'sms' => false, 'push' => true],
            'memberships' => ['email' => true, 'sms' => false, 'push' => true],
            'loyalty_rewards' => ['email' => true, 'sms' => false, 'push' => true],
            'marketing_promotions' => ['email' => true, 'sms' => false, 'push' => true],
            'affiliate_program' => ['email' => true, 'sms' => false, 'push' => true],
            'support_tickets' => ['email' => true, 'sms' => true, 'push' => true],
            'system_alerts' => ['email' => true, 'sms' => false, 'push' => true],
        ];

        if (! $user || empty($user->notification_preferences)) {
            return $defaults[$category][$channel] ?? true;
        }

        $prefs = $user->notification_preferences;

        // Check structured category preferences
        if (isset($prefs['categories'][$category][$channel])) {
            return (bool) $prefs['categories'][$category][$channel];
        }

        // Legacy flat keys fallback
        if ($channel === 'email') {
            if ($category === 'orders_updates' && isset($prefs['emailOrderUpdates'])) return (bool) $prefs['emailOrderUpdates'];
            if ($category === 'marketing_promotions' && isset($prefs['emailPromotions'])) return (bool) $prefs['emailPromotions'];
            if ($category === 'marketing_promotions' && isset($prefs['emailNewsletter'])) return (bool) $prefs['emailNewsletter'];
            if ($category === 'account_security' && isset($prefs['emailAccountActivity'])) return (bool) $prefs['emailAccountActivity'];
        } elseif ($channel === 'sms') {
            if ($category === 'orders_updates' && isset($prefs['smsOrderUpdates'])) return (bool) $prefs['smsOrderUpdates'];
            if ($category === 'marketing_promotions' && isset($prefs['smsPromotions'])) return (bool) $prefs['smsPromotions'];
            if ($category === 'account_security' && isset($prefs['smsSecurityAlerts'])) return (bool) $prefs['smsSecurityAlerts'];
        } elseif ($channel === 'push') {
            if ($category === 'orders_updates' && isset($prefs['pushOrderUpdates'])) return (bool) $prefs['pushOrderUpdates'];
            if ($category === 'payments_billing' && isset($prefs['pushPayments'])) return (bool) $prefs['pushPayments'];
        }

        return $defaults[$category][$channel] ?? true;
    }

    private function sendSmsNotification(string $eventKey, array $data, ?string $phone): void
    {
        if (!$phone) {
            return;
        }

        $category = $this->getEventNotificationCategory($eventKey);
        $customerEmail = $data['customer_email'] ?? $data['email'] ?? null;
        $identifier = $customerEmail ?: $phone;

        if (! $this->isNotificationEnabledForUser($identifier, $category, 'sms')) {
            \Illuminate\Support\Facades\Log::info("SMS notification skipped: customer has disabled SMS notifications for {$category}.", [
                'event_key' => $eventKey,
                'phone' => $phone,
            ]);
            return;
        }

        $message = match ($eventKey) {
            'order_placed' => "Thank you for your order, " . ($data['customer_name'] ?? 'Customer') . "! Your order #" . ($data['order_number'] ?? '') . " of " . ($data['order_total'] ?? '') . " has been placed successfully.",
            'order_shipped' => "Hi " . ($data['customer_name'] ?? 'Customer') . ", your order #" . ($data['order_number'] ?? '') . " has been shipped!" . (($data['tracking_number'] ?? '') !== '' ? " Tracking: " . $data['tracking_number'] : ""),
            'order_delivered' => "Hi " . ($data['customer_name'] ?? 'Customer') . ", your order #" . ($data['order_number'] ?? '') . " has been delivered successfully!",
            'order_cancelled' => "Hi " . ($data['customer_name'] ?? 'Customer') . ", your order #" . ($data['order_number'] ?? '') . " has been cancelled.",
            'order_proof_ready' => "Hi " . ($data['customer_name'] ?? 'Customer') . ", the design proof for order #" . ($data['order_number'] ?? '') . " is ready for your review: " . ($data['proof_url'] ?? ''),
            'order_verification_required' => "Action Required: Please complete verification for order #" . ($data['order_number'] ?? '') . " before " . ($data['deadline_date'] ?? 'deadline') . ": " . ($data['verification_url'] ?? ''),
            'dispute_opened' => "Dispute case #" . ($data['dispute_number'] ?? '') . " for order #" . ($data['order_number'] ?? '') . " has been received and is under review.",
            'dispute_resolved' => "Good news! Dispute #" . ($data['dispute_number'] ?? '') . " for order #" . ($data['order_number'] ?? '') . " has been resolved.",
            default => null,
        };

        if ($message) {
            try {
                $smsService = app(\App\Services\SmsService::class);
                $smsService->sendSms($phone, $message);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error("Failed to send notification SMS: " . $e->getMessage());
            }
        }
    }
}
