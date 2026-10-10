<?php

namespace Database\Seeders;

use App\Models\FormPageSetting;
use App\Models\QuoteFormField;
use Illuminate\Database\Seeder;

class QuoteFormFieldSeeder extends Seeder
{
    public function run(): void
    {
        $allForms = [
            // ═══════════════════════════════════════════════════════════════════
            // 1. QUOTE REQUEST (Corporate Intake Form)
            // ═══════════════════════════════════════════════════════════════════
            'quote' => [
                'setting' => [
                    'form_title' => 'Corporate Intake & Quote Request',
                    'form_description' => 'Complete the multi-step form below to request a detailed custom embroidery & manufacturing proposal.',
                    'submit_button_text' => 'Submit Quote Request',
                    'contact_email' => 'info@mecarvi.com',
                    'section_order' => ['Company', 'Scope', 'Signage & Apparel', 'Operations', 'Timeline', 'Financial', 'Support', 'Authorization'],
                ],
                'fields' => [
                    // Section 1: Company
                    ['section' => 'Company', 'label' => 'Legal Company Name *', 'name' => 'legalCompanyName', 'type' => 'text', 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 2],
                    ['section' => 'Company', 'label' => 'Type of Organization', 'name' => 'organizationType', 'type' => 'select', 'options' => ['Franchise Operator', 'Multi-Location Retail', 'Commercial Developer', 'Property Owner', 'General Contractor', 'Corporate Brand', 'Government', 'Other'], 'sort_order' => 2, 'grid_cols' => 2],
                    ['section' => 'Company', 'label' => 'Full Name *', 'name' => 'contactFullName', 'type' => 'text', 'is_required' => true, 'sort_order' => 3, 'grid_cols' => 2],
                    ['section' => 'Company', 'label' => 'Title *', 'name' => 'contactTitle', 'type' => 'text', 'is_required' => true, 'sort_order' => 4, 'grid_cols' => 2],
                    ['section' => 'Company', 'label' => 'Business Email *', 'name' => 'contactBusinessEmail', 'type' => 'email', 'is_required' => true, 'sort_order' => 5, 'grid_cols' => 2],
                    ['section' => 'Company', 'label' => 'Direct Phone *', 'name' => 'contactDirectPhone', 'type' => 'tel', 'is_required' => true, 'sort_order' => 6, 'grid_cols' => 2],
                    ['section' => 'Company', 'label' => 'Primary Communication Preference', 'name' => 'communicationPreference', 'type' => 'radio', 'options' => ['Email', 'Phone', 'Teams / Zoom'], 'sort_order' => 7, 'grid_cols' => 1],

                    // Section 2: Scope
                    ['section' => 'Scope', 'label' => 'Project Type *', 'name' => 'projectType', 'type' => 'radio', 'options' => ['Custom Embroidery', 'Corporate Apparel Rollout', 'Uniform Program', 'Patches & Badges', 'Screen Printing & Heat Transfer', 'Full Brand Merchandising'], 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Scope', 'label' => 'Estimated Total Units *', 'name' => 'totalLocations', 'type' => 'number', 'is_required' => true, 'sort_order' => 2, 'grid_cols' => 1],
                    ['section' => 'Scope', 'label' => 'Upload Spec / Artwork (AI, PDF, DST, PNG)', 'name' => 'locationListFile', 'type' => 'file', 'sort_order' => 3, 'grid_cols' => 1],
                    ['section' => 'Scope', 'label' => 'Geographic Coverage *', 'name' => 'geographicCoverage', 'type' => 'checkbox', 'options' => ['Local Delivery', 'Multi-State / Regional', 'Nationwide', 'International'], 'is_required' => true, 'sort_order' => 4, 'grid_cols' => 1],

                    // Section 3: Signage & Apparel
                    ['section' => 'Signage & Apparel', 'label' => 'Garment & Product Categories Required', 'name' => 'signCategories', 'type' => 'checkbox', 'options' => ['Caps & Headwear', 'Polos & Dress Shirts', 'Jackets & Outerwear', 'Hoodies & Sweatshirts', 'T-Shirts', 'Bags & Totes', 'Patches & Emblems', 'Workwear / Hi-Vis', 'Other'], 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Signage & Apparel', 'label' => 'Stitch Count / Decoration Complexity', 'name' => 'illuminationStandard', 'type' => 'radio', 'options' => ['Standard Left Chest / Cap', 'High Stitch Count / Full Back', '3D Puff Embroidery', 'Mixed Media (Embroidery + Print)', 'Undetermined'], 'sort_order' => 2, 'grid_cols' => 1],
                    ['section' => 'Signage & Apparel', 'label' => 'Scope of Service', 'name' => 'scopeOfWork', 'type' => 'radio', 'options' => ['Garment Supply + Embroidery (Turnkey)', 'Embroidery on Customer-Supplied Goods (CMT)', 'Digitizing & Sample Prototyping Only', 'Ongoing Fulfillment Program'], 'sort_order' => 3, 'grid_cols' => 1],
                    ['section' => 'Signage & Apparel', 'label' => 'Brand Documentation', 'name' => 'brandDocumentationStatus', 'type' => 'radio', 'options' => ['Vector Files Ready (.ai / .eps / .pdf)', 'Digitized Files Ready (.dst / .pes / .emb)', 'Raster Image Only (.jpg / .png)', 'Need Design / Redraw Support'], 'sort_order' => 4, 'grid_cols' => 1],
                    ['section' => 'Signage & Apparel', 'label' => 'Upload Additional Brand Files', 'name' => 'brandDocuments', 'type' => 'file', 'sort_order' => 5, 'grid_cols' => 1],

                    // Section 4: Operations
                    ['section' => 'Operations', 'label' => 'Packaging & Tagging Requirements', 'name' => 'permittingResponsibility', 'type' => 'radio', 'options' => ['Bulk Packed in Cartons', 'Individual Polybagging', 'Custom Hang Tags & SKU Barcoding', 'Retail Ready Display Packaging'], 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Operations', 'label' => 'Shipping & Distribution Strategy', 'name' => 'installationCoordination', 'type' => 'radio', 'options' => ['Single Central Delivery Address', 'Drop-Ship to Multiple Locations', 'Store Pickup at Mecarvi Facility', 'Phased Batch Shipments'], 'sort_order' => 2, 'grid_cols' => 1],

                    // Section 5: Timeline
                    ['section' => 'Timeline', 'label' => 'Target Delivery Deadline', 'name' => 'targetTimeline', 'type' => 'select', 'options' => ['Rush Order (Under 7 Days)', 'Standard Production (10-14 Days)', '30-45 Days', 'Planning / Budgeting Phase'], 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Timeline', 'label' => 'Reorder Frequency', 'name' => 'deploymentStrategy', 'type' => 'radio', 'options' => ['One-Time Order', 'Monthly Recurring', 'Quarterly Bulk Restock', 'On-Demand Portal Orders'], 'sort_order' => 2, 'grid_cols' => 1],

                    // Section 6: Financial
                    ['section' => 'Financial', 'label' => 'Estimated Program Budget', 'name' => 'estimatedProgramBudget', 'type' => 'radio', 'options' => ['Under $2,500', '$2,501-$5,000', '$5,001-$10,000', '$10,001-$25,000', '$25,001-$50,000', '$50,000+', 'Not Sure'], 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Financial', 'label' => 'Procurement Method', 'name' => 'procurementMethod', 'type' => 'radio', 'options' => ['Corporate Credit Card', 'Company PO / Net 30 Terms', 'ACH / Bank Wire', 'Financing Plan Required'], 'sort_order' => 2, 'grid_cols' => 1],

                    // Section 7: Support
                    ['section' => 'Support', 'label' => 'Project Manager Contact', 'name' => 'stakeholderProjectManager', 'type' => 'text', 'sort_order' => 1, 'grid_cols' => 2],
                    ['section' => 'Support', 'label' => 'Finance / Invoicing Contact', 'name' => 'stakeholderFacilitiesContact', 'type' => 'text', 'sort_order' => 2, 'grid_cols' => 2],
                    ['section' => 'Support', 'label' => 'Special Instructions or Notes', 'name' => 'specialComplianceRequirements', 'type' => 'textarea', 'sort_order' => 3, 'grid_cols' => 1],

                    // Section 8: Authorization
                    ['section' => 'Authorization', 'label' => 'I confirm I have authority to request this quote on behalf of the company.', 'name' => 'confirmAuthority', 'type' => 'checkbox', 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Authorization', 'label' => 'I agree to be contacted regarding this quote submission.', 'name' => 'agreeToContact', 'type' => 'checkbox', 'is_required' => true, 'sort_order' => 2, 'grid_cols' => 1],
                ],
            ],

            // ═══════════════════════════════════════════════════════════════════
            // 2. VENDOR APPLICATION
            // ═══════════════════════════════════════════════════════════════════
            'vendor-applications' => [
                'setting' => [
                    'form_title' => 'Vendor & Supplier Application',
                    'form_description' => 'Partner with Mecarvi Technologies. Apply to become an approved supplier, blank apparel provider, or manufacturing partner.',
                    'submit_button_text' => 'Submit Vendor Application',
                    'contact_email' => 'vendors@mecarvi.com',
                    'section_order' => ['Company Information', 'Product & Manufacturing Capabilities', 'Compliance & Quality Standards', 'Commercial Terms & Agreement'],
                ],
                'fields' => [
                    // Section 1: Company Information
                    ['section' => 'Company Information', 'label' => 'Company / Supplier Legal Name *', 'name' => 'legalCompanyName', 'type' => 'text', 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 2],
                    ['section' => 'Company Information', 'label' => 'Business Structure', 'name' => 'businessStructure', 'type' => 'select', 'options' => ['Corporation', 'LLC', 'Partnership', 'Sole Proprietorship', 'Manufacturer Direct'], 'sort_order' => 2, 'grid_cols' => 2],
                    ['section' => 'Company Information', 'label' => 'Primary Contact Full Name *', 'name' => 'contactFullName', 'type' => 'text', 'is_required' => true, 'sort_order' => 3, 'grid_cols' => 2],
                    ['section' => 'Company Information', 'label' => 'Contact Title / Position *', 'name' => 'contactTitle', 'type' => 'text', 'is_required' => true, 'sort_order' => 4, 'grid_cols' => 2],
                    ['section' => 'Company Information', 'label' => 'Business Email Address *', 'name' => 'contactBusinessEmail', 'type' => 'email', 'is_required' => true, 'sort_order' => 5, 'grid_cols' => 2],
                    ['section' => 'Company Information', 'label' => 'Direct Phone Number *', 'name' => 'contactDirectPhone', 'type' => 'tel', 'is_required' => true, 'sort_order' => 6, 'grid_cols' => 2],
                    ['section' => 'Company Information', 'label' => 'Company Website URL', 'name' => 'websiteUrl', 'type' => 'text', 'placeholder' => 'https://example.com', 'sort_order' => 7, 'grid_cols' => 2],
                    ['section' => 'Company Information', 'label' => 'Tax ID / EIN Number *', 'name' => 'taxId', 'type' => 'text', 'is_required' => true, 'sort_order' => 8, 'grid_cols' => 2],
                    ['section' => 'Company Information', 'label' => 'Headquarters Address *', 'name' => 'companyAddress', 'type' => 'textarea', 'is_required' => true, 'sort_order' => 9, 'grid_cols' => 1],

                    // Section 2: Product & Manufacturing Capabilities
                    ['section' => 'Product & Manufacturing Capabilities', 'label' => 'Primary Product Categories Provided', 'name' => 'productCategories', 'type' => 'checkbox', 'options' => ['Blank Apparel (Tees, Hoodies, Polos)', 'Headwear & Caps', 'Embroidery Threads & Backings', 'Industrial Machinery & Needles', 'Packaging & Shipping Supplies', 'Subcontract Embroidery / Print', 'Hardware & Accessories'], 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Product & Manufacturing Capabilities', 'label' => 'Monthly Production / Supply Capacity', 'name' => 'monthlyCapacity', 'type' => 'select', 'options' => ['1,000 - 10,000 units', '10,000 - 50,000 units', '50,000 - 200,000 units', '200,000+ units'], 'sort_order' => 2, 'grid_cols' => 1],
                    ['section' => 'Product & Manufacturing Capabilities', 'label' => 'Average Order Lead Time', 'name' => 'orderLeadTime', 'type' => 'radio', 'options' => ['Same Day / Next Day Dispatch', '2-5 Business Days', '1-2 Weeks', '3-4 Weeks', 'Custom Production Cycle'], 'sort_order' => 3, 'grid_cols' => 1],
                    ['section' => 'Product & Manufacturing Capabilities', 'label' => 'Upload Product Catalog / Line Sheet (PDF)', 'name' => 'productCatalogFile', 'type' => 'file', 'sort_order' => 4, 'grid_cols' => 1],

                    // Section 3: Compliance & Quality Standards
                    ['section' => 'Compliance & Quality Standards', 'label' => 'Quality Certifications Held', 'name' => 'certifications', 'type' => 'checkbox', 'options' => ['ISO 9001', 'OEKO-TEX Standard 100', 'WRAP Certified', 'GOTS (Organic)', 'Fair Trade Certified', 'None / In Progress'], 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Compliance & Quality Standards', 'label' => 'Defect Rate / Quality Assurance Policy Summary', 'name' => 'qaPolicySummary', 'type' => 'textarea', 'placeholder' => 'Describe your inspection process and warranty on defective batches...', 'sort_order' => 2, 'grid_cols' => 1],
                    ['section' => 'Compliance & Quality Standards', 'label' => 'Upload W-9 / Business Registration Document', 'name' => 'w9Document', 'type' => 'file', 'sort_order' => 3, 'grid_cols' => 1],

                    // Section 4: Commercial Terms & Agreement
                    ['section' => 'Commercial Terms & Agreement', 'label' => 'Offered Payment Terms', 'name' => 'offeredPaymentTerms', 'type' => 'radio', 'options' => ['Net 30', 'Net 45', 'Net 60', '2% 10 Net 30 Discount', 'Credit Card / Immediate', 'Custom Terms'], 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Commercial Terms & Agreement', 'label' => 'Do you provide drop-shipping or direct distribution?', 'name' => 'dropShipSupport', 'type' => 'radio', 'options' => ['Yes, full drop-ship capabilities', 'Bulk warehouse delivery only', 'Both options available'], 'sort_order' => 2, 'grid_cols' => 1],
                    ['section' => 'Commercial Terms & Agreement', 'label' => 'I certify that all information provided in this vendor application is true and accurate.', 'name' => 'certifyTruth', 'type' => 'checkbox', 'is_required' => true, 'sort_order' => 3, 'grid_cols' => 1],
                    ['section' => 'Commercial Terms & Agreement', 'label' => 'I agree to the Mecarvi Vendor Code of Conduct and Supply Chain Policies.', 'name' => 'agreeVendorTerms', 'type' => 'checkbox', 'is_required' => true, 'sort_order' => 4, 'grid_cols' => 1],
                ],
            ],

            // ═══════════════════════════════════════════════════════════════════
            // 3. FINANCING APPLICATION
            // ═══════════════════════════════════════════════════════════════════
            'financing-application' => [
                'setting' => [
                    'form_title' => 'Commercial Financing Application',
                    'form_description' => 'Apply for commercial equipment, bulk order inventory, or machinery financing with flexible payment plans.',
                    'submit_button_text' => 'Submit Financing Application',
                    'contact_email' => 'financing@mecarvi.com',
                    'section_order' => ['Applicant Details', 'Business Profile', 'Financing Request', 'Financial Information', 'Consent & Signature'],
                ],
                'fields' => [
                    // Section 1: Applicant Details
                    ['section' => 'Applicant Details', 'label' => 'Applicant Full Name *', 'name' => 'contactFullName', 'type' => 'text', 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 2],
                    ['section' => 'Applicant Details', 'label' => 'Applicant Title / Role *', 'name' => 'contactTitle', 'type' => 'text', 'is_required' => true, 'sort_order' => 2, 'grid_cols' => 2],
                    ['section' => 'Applicant Details', 'label' => 'Email Address *', 'name' => 'contactBusinessEmail', 'type' => 'email', 'is_required' => true, 'sort_order' => 3, 'grid_cols' => 2],
                    ['section' => 'Applicant Details', 'label' => 'Phone Number *', 'name' => 'contactDirectPhone', 'type' => 'tel', 'is_required' => true, 'sort_order' => 4, 'grid_cols' => 2],
                    ['section' => 'Applicant Details', 'label' => 'Date of Birth', 'name' => 'dateOfBirth', 'type' => 'date', 'sort_order' => 5, 'grid_cols' => 2],
                    ['section' => 'Applicant Details', 'label' => 'Social Security Number (Last 4 digits) or Tax ID', 'name' => 'ssnLastFour', 'type' => 'text', 'placeholder' => 'XXXX', 'sort_order' => 6, 'grid_cols' => 2],

                    // Section 2: Business Profile
                    ['section' => 'Business Profile', 'label' => 'Legal Business Name *', 'name' => 'legalCompanyName', 'type' => 'text', 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 2],
                    ['section' => 'Business Profile', 'label' => 'DBA (Doing Business As) if different', 'name' => 'dbaName', 'type' => 'text', 'sort_order' => 2, 'grid_cols' => 2],
                    ['section' => 'Business Profile', 'label' => 'Years in Business *', 'name' => 'yearsInBusiness', 'type' => 'select', 'options' => ['Less than 1 year (Startup)', '1 - 2 years', '3 - 5 years', '5 - 10 years', '10+ years'], 'is_required' => true, 'sort_order' => 3, 'grid_cols' => 2],
                    ['section' => 'Business Profile', 'label' => 'Business Entity Type', 'name' => 'businessEntityType', 'type' => 'select', 'options' => ['LLC', 'C-Corp', 'S-Corp', 'Sole Proprietorship', 'Partnership'], 'sort_order' => 4, 'grid_cols' => 2],
                    ['section' => 'Business Profile', 'label' => 'Physical Business Address *', 'name' => 'businessAddress', 'type' => 'textarea', 'is_required' => true, 'sort_order' => 5, 'grid_cols' => 1],

                    // Section 3: Financing Request
                    ['section' => 'Financing Request', 'label' => 'Financing Purpose *', 'name' => 'financingPurpose', 'type' => 'radio', 'options' => ['Commercial Embroidery Machinery', 'Bulk Apparel Inventory Production', 'Turnkey Production Equipment', 'Working Capital / Order Fulfillment'], 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Financing Request', 'label' => 'Requested Financing Amount ($ USD) *', 'name' => 'requestedAmount', 'type' => 'number', 'is_required' => true, 'placeholder' => 'e.g. 25000', 'sort_order' => 2, 'grid_cols' => 2],
                    ['section' => 'Financing Request', 'label' => 'Preferred Repayment Term', 'name' => 'preferredTerm', 'type' => 'select', 'options' => ['12 Months', '24 Months', '36 Months', '48 Months', '60 Months'], 'sort_order' => 3, 'grid_cols' => 2],
                    ['section' => 'Financing Request', 'label' => 'Down Payment Willing to Provide ($)', 'name' => 'downPayment', 'type' => 'number', 'placeholder' => 'e.g. 5000', 'sort_order' => 4, 'grid_cols' => 2],
                    ['section' => 'Financing Request', 'label' => 'Equipment / Order Quotation Reference (if existing)', 'name' => 'quoteReference', 'type' => 'text', 'placeholder' => 'Quote # or Machine Model', 'sort_order' => 5, 'grid_cols' => 2],

                    // Section 4: Financial Information
                    ['section' => 'Financial Information', 'label' => 'Estimated Annual Gross Revenue *', 'name' => 'annualRevenue', 'type' => 'select', 'options' => ['Under $100,000', '$100,000 - $250,000', '$250,000 - $500,000', '$500,000 - $1,000,000', '$1,000,000 - $5,000,000', '$5,000,000+'], 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 2],
                    ['section' => 'Financial Information', 'label' => 'Estimated Personal Credit Score Range', 'name' => 'creditScoreRange', 'type' => 'select', 'options' => ['Excellent (720+)', 'Good (680 - 719)', 'Fair (620 - 679)', 'Building Credit (Below 620)'], 'sort_order' => 2, 'grid_cols' => 2],
                    ['section' => 'Financial Information', 'label' => 'Upload Recent Bank Statements (3 Months / PDF)', 'name' => 'bankStatementsFile', 'type' => 'file', 'sort_order' => 3, 'grid_cols' => 1],

                    // Section 5: Consent & Signature
                    ['section' => 'Consent & Signature', 'label' => 'I authorize Mecarvi Technologies and its financing partners to review business credit history.', 'name' => 'consentCreditCheck', 'type' => 'checkbox', 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Consent & Signature', 'label' => 'Electronic Signature (Full Legal Name) *', 'name' => 'electronicSignature', 'type' => 'signature', 'is_required' => true, 'sort_order' => 2, 'grid_cols' => 1],
                ],
            ],

            // ═══════════════════════════════════════════════════════════════════
            // 4. SCHOLARSHIP APPLICATION
            // ═══════════════════════════════════════════════════════════════════
            'scholarship-application' => [
                'setting' => [
                    'form_title' => 'Mecarvi Creative Embroidery Scholarship',
                    'form_description' => 'Supporting aspiring textile designers, apparel creators, and embroidery artists with grant awards, training, and production credits.',
                    'submit_button_text' => 'Submit Scholarship Application',
                    'contact_email' => 'scholarships@mecarvi.com',
                    'section_order' => ['Personal Information', 'Academic & Institution Profile', 'Creative Portfolio & Work', 'Personal Essay & Goals', 'Declaration'],
                ],
                'fields' => [
                    // Section 1: Personal Information
                    ['section' => 'Personal Information', 'label' => 'Applicant Full Name *', 'name' => 'contactFullName', 'type' => 'text', 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 2],
                    ['section' => 'Personal Information', 'label' => 'Email Address *', 'name' => 'contactBusinessEmail', 'type' => 'email', 'is_required' => true, 'sort_order' => 2, 'grid_cols' => 2],
                    ['section' => 'Personal Information', 'label' => 'Phone Number *', 'name' => 'contactDirectPhone', 'type' => 'tel', 'is_required' => true, 'sort_order' => 3, 'grid_cols' => 2],
                    ['section' => 'Personal Information', 'label' => 'Mailing Address *', 'name' => 'mailingAddress', 'type' => 'textarea', 'is_required' => true, 'sort_order' => 4, 'grid_cols' => 1],

                    // Section 2: Academic Profile
                    ['section' => 'Academic & Institution Profile', 'label' => 'Current Institution / School *', 'name' => 'schoolName', 'type' => 'text', 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 2],
                    ['section' => 'Academic & Institution Profile', 'label' => 'Degree / Major Program *', 'name' => 'majorField', 'type' => 'text', 'placeholder' => 'e.g. Fashion Design, Textile Arts, Graphic Design', 'is_required' => true, 'sort_order' => 2, 'grid_cols' => 2],
                    ['section' => 'Academic & Institution Profile', 'label' => 'Current Academic Standing', 'name' => 'academicStanding', 'type' => 'select', 'options' => ['High School Senior', 'Undergraduate (Freshman/Sophomore)', 'Undergraduate (Junior/Senior)', 'Graduate / Masters Program', 'Vocational / Apprenticeship'], 'sort_order' => 3, 'grid_cols' => 2],
                    ['section' => 'Academic & Institution Profile', 'label' => 'Cumulative GPA', 'name' => 'gpa', 'type' => 'text', 'placeholder' => 'e.g. 3.75', 'sort_order' => 4, 'grid_cols' => 2],
                    ['section' => 'Academic & Institution Profile', 'label' => 'Upload Student ID or Enrollment Verification (PDF / Image)', 'name' => 'enrollmentDoc', 'type' => 'file', 'sort_order' => 5, 'grid_cols' => 1],

                    // Section 3: Creative Portfolio
                    ['section' => 'Creative Portfolio & Work', 'label' => 'Primary Creative Focus', 'name' => 'creativeFocus', 'type' => 'checkbox', 'options' => ['Machine Embroidery', 'Hand Embroidery / Fiber Art', 'Apparel & Streetwear Design', 'Pattern Making & Digitizing', 'Textile Innovation', 'Mixed Media Surface Design'], 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Creative Portfolio & Work', 'label' => 'Online Portfolio / Instagram / Behance URL', 'name' => 'portfolioUrl', 'type' => 'text', 'placeholder' => 'https://instagram.com/mywork or portfolio link', 'sort_order' => 2, 'grid_cols' => 1],
                    ['section' => 'Creative Portfolio & Work', 'label' => 'Upload Portfolio Samples / Lookbook (PDF or ZIP)', 'name' => 'portfolioSampleFile', 'type' => 'file', 'sort_order' => 3, 'grid_cols' => 1],

                    // Section 4: Personal Essay & Goals
                    ['section' => 'Personal Essay & Goals', 'label' => 'Statement of Purpose: How will this scholarship accelerate your creative career? *', 'name' => 'statementOfPurpose', 'type' => 'textarea', 'is_required' => true, 'placeholder' => 'Write 200-500 words about your vision, inspiration, and goals in apparel/embroidery...', 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Personal Essay & Goals', 'label' => 'Community Impact or Sustainability Focus', 'name' => 'communityImpact', 'type' => 'textarea', 'placeholder' => 'How does your work give back or address sustainable textiles?', 'sort_order' => 2, 'grid_cols' => 1],

                    // Section 5: Declaration
                    ['section' => 'Declaration', 'label' => 'I confirm that all portfolio work submitted is my original creative creation.', 'name' => 'confirmOriginalWork', 'type' => 'checkbox', 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Declaration', 'label' => 'I agree that Mecarvi may showcase my scholarship submission work on social media and website.', 'name' => 'agreeShowcase', 'type' => 'checkbox', 'is_required' => true, 'sort_order' => 2, 'grid_cols' => 1],
                ],
            ],

            // ═══════════════════════════════════════════════════════════════════
            // 5. SPONSORSHIP APPLICATION
            // ═══════════════════════════════════════════════════════════════════
            'sponsorship' => [
                'setting' => [
                    'form_title' => 'Event & Athlete Sponsorship Request',
                    'form_description' => 'Partner with Mecarvi Technologies for team kits, event merchandise, motorsport/athletics apparel, and community sponsorships.',
                    'submit_button_text' => 'Submit Sponsorship Request',
                    'contact_email' => 'sponsorships@mecarvi.com',
                    'section_order' => ['Organization & Event Details', 'Contact Person', 'Sponsorship Package Requested', 'Audience & Brand Exposure', 'Proposal Upload & Terms'],
                ],
                'fields' => [
                    // Section 1: Organization & Event Details
                    ['section' => 'Organization & Event Details', 'label' => 'Organization / Team / Event Name *', 'name' => 'legalCompanyName', 'type' => 'text', 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 2],
                    ['section' => 'Organization & Event Details', 'label' => 'Category of Sponsorship *', 'name' => 'sponsorshipCategory', 'type' => 'select', 'options' => ['Sports Team / Athletics Club', 'Motorsport / Racing Team', 'Charity / Non-Profit Gala', 'Industry Conference / Expo', 'Community Arts & Festival', 'Influencer / Creator Collaboration'], 'is_required' => true, 'sort_order' => 2, 'grid_cols' => 2],
                    ['section' => 'Organization & Event Details', 'label' => 'Event / Season Date(s)', 'name' => 'eventDates', 'type' => 'text', 'placeholder' => 'e.g. October 15-20, 2026 or 2026-2027 Season', 'sort_order' => 3, 'grid_cols' => 2],
                    ['section' => 'Organization & Event Details', 'label' => 'Event Location / Venue', 'name' => 'eventLocation', 'type' => 'text', 'sort_order' => 4, 'grid_cols' => 2],
                    ['section' => 'Organization & Event Details', 'label' => 'Website & Social Media Channels *', 'name' => 'socialChannels', 'type' => 'text', 'is_required' => true, 'placeholder' => 'Website link and primary @handles', 'sort_order' => 5, 'grid_cols' => 1],

                    // Section 2: Contact Person
                    ['section' => 'Contact Person', 'label' => 'Representative Full Name *', 'name' => 'contactFullName', 'type' => 'text', 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 2],
                    ['section' => 'Contact Person', 'label' => 'Title / Role in Organization *', 'name' => 'contactTitle', 'type' => 'text', 'is_required' => true, 'sort_order' => 2, 'grid_cols' => 2],
                    ['section' => 'Contact Person', 'label' => 'Email Address *', 'name' => 'contactBusinessEmail', 'type' => 'email', 'is_required' => true, 'sort_order' => 3, 'grid_cols' => 2],
                    ['section' => 'Contact Person', 'label' => 'Phone Number *', 'name' => 'contactDirectPhone', 'type' => 'tel', 'is_required' => true, 'sort_order' => 4, 'grid_cols' => 2],

                    // Section 3: Sponsorship Package Requested
                    ['section' => 'Sponsorship Package Requested', 'label' => 'Requested Support Type *', 'name' => 'supportType', 'type' => 'checkbox', 'options' => ['Custom Team Uniforms / Jerseys', 'Embroidered Hats / Merchandise', 'Event Staff Apparel', 'Financial Sponsorship Grant', 'Co-Branded Promotional Products', 'Discounted Production Tier'], 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Sponsorship Package Requested', 'label' => 'Estimated Quantity of Items / Total Value Requested', 'name' => 'requestedValue', 'type' => 'text', 'placeholder' => 'e.g. 150 embroidered caps + 100 jerseys', 'sort_order' => 2, 'grid_cols' => 1],
                    ['section' => 'Sponsorship Package Requested', 'label' => 'Lead Time Required for Apparel Delivery', 'name' => 'apparelDeliveryDeadline', 'type' => 'date', 'sort_order' => 3, 'grid_cols' => 1],

                    // Section 4: Audience & Brand Exposure
                    ['section' => 'Audience & Brand Exposure', 'label' => 'Estimated In-Person Attendance / Participant Count', 'name' => 'attendanceCount', 'type' => 'select', 'options' => ['Under 500 people', '500 - 2,500 people', '2,500 - 10,000 people', '10,000 - 50,000 people', '50,000+ people'], 'sort_order' => 1, 'grid_cols' => 2],
                    ['section' => 'Audience & Brand Exposure', 'label' => 'Combined Digital / Social Media Reach', 'name' => 'socialReach', 'type' => 'select', 'options' => ['Under 10K followers/impressions', '10K - 50K reach', '50K - 250K reach', '250K - 1M reach', '1M+ reach'], 'sort_order' => 2, 'grid_cols' => 2],
                    ['section' => 'Audience & Brand Exposure', 'label' => 'Mecarvi Brand Visibility Deliverables Offered', 'name' => 'visibilityDeliverables', 'type' => 'checkbox', 'options' => ['Mecarvi Logo on Uniform Chest/Sleeve', 'Banner Display at Main Stage/Field', 'Logo on Website & Event Program', 'Social Media Tagged Posts & Reels', 'Product Sampling / Booth Space', 'Press Release Mention'], 'sort_order' => 3, 'grid_cols' => 1],

                    // Section 5: Proposal Upload & Terms
                    ['section' => 'Proposal Upload & Terms', 'label' => 'Upload Sponsorship Deck / Pitch Document (PDF)', 'name' => 'sponsorshipDeckFile', 'type' => 'file', 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Proposal Upload & Terms', 'label' => 'Additional Message or Specific Campaign Idea', 'name' => 'specialComplianceRequirements', 'type' => 'textarea', 'sort_order' => 2, 'grid_cols' => 1],
                    ['section' => 'Proposal Upload & Terms', 'label' => 'I confirm the accuracy of this sponsorship request and agree to brand reporting requirements.', 'name' => 'confirmSponsorshipAgreement', 'type' => 'checkbox', 'is_required' => true, 'sort_order' => 3, 'grid_cols' => 1],
                ],
            ],

            // ═══════════════════════════════════════════════════════════════════
            // 6. CAREER APPLICATION
            // ═══════════════════════════════════════════════════════════════════
            'career-application' => [
                'setting' => [
                    'form_title' => 'Join the Mecarvi Team — Career Application',
                    'form_description' => 'Explore exciting careers in embroidery machinery operations, digital digitizing, apparel production, quality assurance, and creative design.',
                    'submit_button_text' => 'Submit Job Application',
                    'contact_email' => 'careers@mecarvi.com',
                    'section_order' => ['Personal Information', 'Position & Employment Preferences', 'Professional Experience', 'Resume & Cover Letter', 'Verification & Consent'],
                ],
                'fields' => [
                    // Section 1: Personal Information
                    ['section' => 'Personal Information', 'label' => 'Full Name *', 'name' => 'contactFullName', 'type' => 'text', 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 2],
                    ['section' => 'Personal Information', 'label' => 'Email Address *', 'name' => 'contactBusinessEmail', 'type' => 'email', 'is_required' => true, 'sort_order' => 2, 'grid_cols' => 2],
                    ['section' => 'Personal Information', 'label' => 'Phone Number *', 'name' => 'contactDirectPhone', 'type' => 'tel', 'is_required' => true, 'sort_order' => 3, 'grid_cols' => 2],
                    ['section' => 'Personal Information', 'label' => 'City, State & Postal Code *', 'name' => 'applicantLocation', 'type' => 'text', 'is_required' => true, 'sort_order' => 4, 'grid_cols' => 2],
                    ['section' => 'Personal Information', 'label' => 'LinkedIn Profile / Portfolio Link', 'name' => 'linkedinUrl', 'type' => 'text', 'placeholder' => 'https://linkedin.com/in/...', 'sort_order' => 5, 'grid_cols' => 1],

                    // Section 2: Position & Preferences
                    ['section' => 'Position & Employment Preferences', 'label' => 'Position(s) Applying For *', 'name' => 'positionApplied', 'type' => 'select', 'options' => ['Industrial Embroidery Machine Operator', 'Embroidery Digitizer / Technical Artist', 'Apparel Production Specialist', 'Quality Control Inspector', 'Graphic Designer & Merchandise Artist', 'Warehouse & Fulfillment Lead', 'Customer Success & Account Manager', 'Sales & Corporate Merchandising Rep', 'Other / General Application'], 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 2],
                    ['section' => 'Position & Employment Preferences', 'label' => 'Desired Employment Type', 'name' => 'employmentType', 'type' => 'select', 'options' => ['Full-Time Permanent', 'Part-Time', 'Contract / Freelance', 'Internship / Apprenticeship'], 'sort_order' => 2, 'grid_cols' => 2],
                    ['section' => 'Position & Employment Preferences', 'label' => 'Available Start Date', 'name' => 'startDate', 'type' => 'date', 'sort_order' => 3, 'grid_cols' => 2],
                    ['section' => 'Position & Employment Preferences', 'label' => 'Expected Hourly Rate / Salary ($ USD)', 'name' => 'expectedSalary', 'type' => 'text', 'placeholder' => 'e.g. $25/hr or $55,000/yr', 'sort_order' => 4, 'grid_cols' => 2],

                    // Section 3: Professional Experience
                    ['section' => 'Professional Experience', 'label' => 'Total Years of Relevant Industry Experience *', 'name' => 'yearsOfExperience', 'type' => 'select', 'options' => ['Entry Level (0 - 1 year)', '1 - 3 years', '3 - 5 years', '5 - 10 years', '10+ years (Master Craftsman)'], 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 2],
                    ['section' => 'Professional Experience', 'label' => 'Software & Machine Proficiency', 'name' => 'skillsProficiency', 'type' => 'checkbox', 'options' => ['Wilcom EmbroideryStudio', 'Tajima DG/ML Pulse', 'Adobe Illustrator / Photoshop', 'Barudan / Tajima Multi-Head Operation', 'Direct-to-Film (DTF) Printing', 'Heat Press & Vinyl Application', 'Laser Cutting & Engraving'], 'sort_order' => 2, 'grid_cols' => 1],
                    ['section' => 'Professional Experience', 'label' => 'Summary of Key Achievements & Background', 'name' => 'backgroundSummary', 'type' => 'textarea', 'placeholder' => 'Briefly describe your previous experience and why you are interested in joining Mecarvi...', 'sort_order' => 3, 'grid_cols' => 1],

                    // Section 4: Resume & Cover Letter
                    ['section' => 'Resume & Cover Letter', 'label' => 'Upload Resume / CV (PDF or DOCX) *', 'name' => 'resumeFile', 'type' => 'file', 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Resume & Cover Letter', 'label' => 'Cover Letter / Additional Notes', 'name' => 'specialComplianceRequirements', 'type' => 'textarea', 'sort_order' => 2, 'grid_cols' => 1],

                    // Section 5: Verification & Consent
                    ['section' => 'Verification & Consent', 'label' => 'Are you legally authorized to work in the country of employment?', 'name' => 'workAuthorization', 'type' => 'radio', 'options' => ['Yes, authorized without sponsorship', 'Yes, with current work visa', 'No, require sponsorship'], 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Verification & Consent', 'label' => 'I certify that all statements made in this job application are true and complete.', 'name' => 'certifyTruth', 'type' => 'checkbox', 'is_required' => true, 'sort_order' => 2, 'grid_cols' => 1],
                ],
            ],

            // ═══════════════════════════════════════════════════════════════════
            // 7. WARRANTY CLAIM & SERVICE APPLICATION
            // ═══════════════════════════════════════════════════════════════════
            'warranty' => [
                'setting' => [
                    'form_title' => 'Warranty Claim & Repair Application',
                    'form_description' => 'Submit a warranty evaluation request for embroidery defects, hardware failures, or garment replacement support under Mecarvi Guarantee.',
                    'submit_button_text' => 'Submit Warranty Claim',
                    'contact_email' => 'warranty@mecarvi.com',
                    'section_order' => ['Customer & Order Details', 'Product Identification', 'Defect & Issue Description', 'Evidence & Photos', 'Claim Resolution Request'],
                ],
                'fields' => [
                    // Section 1: Customer & Order Details
                    ['section' => 'Customer & Order Details', 'label' => 'Customer / Company Full Name *', 'name' => 'contactFullName', 'type' => 'text', 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 2],
                    ['section' => 'Customer & Order Details', 'label' => 'Original Order Number / Invoice # *', 'name' => 'orderNumber', 'type' => 'text', 'is_required' => true, 'placeholder' => 'e.g. ORD-10948', 'sort_order' => 2, 'grid_cols' => 2],
                    ['section' => 'Customer & Order Details', 'label' => 'Account Email Address *', 'name' => 'contactBusinessEmail', 'type' => 'email', 'is_required' => true, 'sort_order' => 3, 'grid_cols' => 2],
                    ['section' => 'Customer & Order Details', 'label' => 'Contact Phone Number *', 'name' => 'contactDirectPhone', 'type' => 'tel', 'is_required' => true, 'sort_order' => 4, 'grid_cols' => 2],
                    ['section' => 'Customer & Order Details', 'label' => 'Date of Original Delivery / Purchase *', 'name' => 'purchaseDate', 'type' => 'date', 'is_required' => true, 'sort_order' => 5, 'grid_cols' => 2],

                    // Section 2: Product Identification
                    ['section' => 'Product Identification', 'label' => 'Item Description / SKU Name *', 'name' => 'productName', 'type' => 'text', 'is_required' => true, 'placeholder' => 'e.g. Richardson 112 Embroidered Trucker Hat', 'sort_order' => 1, 'grid_cols' => 2],
                    ['section' => 'Product Identification', 'label' => 'Product Category', 'name' => 'productCategory', 'type' => 'select', 'options' => ['Embroidered Headwear / Caps', 'Custom Apparel / Polos / Hoodies', 'Custom Embroidered Patches', 'Direct-to-Film (DTF) Printed Goods', 'Commercial Machinery / Hardware', 'Accessories / Bags'], 'sort_order' => 2, 'grid_cols' => 2],
                    ['section' => 'Product Identification', 'label' => 'Total Quantity Affected *', 'name' => 'quantityAffected', 'type' => 'number', 'is_required' => true, 'placeholder' => 'e.g. 5', 'sort_order' => 3, 'grid_cols' => 2],
                    ['section' => 'Product Identification', 'label' => 'Serial Number / Batch Tag (if applicable)', 'name' => 'batchNumber', 'type' => 'text', 'sort_order' => 4, 'grid_cols' => 2],

                    // Section 3: Defect & Issue Description
                    ['section' => 'Defect & Issue Description', 'label' => 'Nature of the Issue *', 'name' => 'issueType', 'type' => 'radio', 'options' => ['Stitching Unraveling / Loose Thread', 'Color Fading / Thread Discoloration', 'Garment Fabric Defect / Hole / Tear', 'Print Peeling / Cracking', 'Incorrect Embroidery Placement / Spelling Error', 'Hardware / Zipper / Snap Defect', 'Other Defect'], 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Defect & Issue Description', 'label' => 'Detailed Description of the Defect *', 'name' => 'specialComplianceRequirements', 'type' => 'textarea', 'is_required' => true, 'placeholder' => 'Please explain in detail how and when the issue occurred...', 'sort_order' => 2, 'grid_cols' => 1],

                    // Section 4: Evidence & Photos
                    ['section' => 'Evidence & Photos', 'label' => 'Upload Clear Close-Up Photos of the Defect (JPG / PNG) *', 'name' => 'defectPhotoFile', 'type' => 'file', 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Evidence & Photos', 'label' => 'Upload Original Invoice / Receipt Proof (PDF / Image)', 'name' => 'invoiceReceiptFile', 'type' => 'file', 'sort_order' => 2, 'grid_cols' => 1],

                    // Section 5: Claim Resolution Request
                    ['section' => 'Claim Resolution Request', 'label' => 'Preferred Resolution *', 'name' => 'preferredResolution', 'type' => 'radio', 'options' => ['Free Replacement / Remake of Defective Items', 'Expert Repair & Restitching', 'Store Credit / Refund for Defective Portion', 'Technical Support / Replacement Parts'], 'is_required' => true, 'sort_order' => 1, 'grid_cols' => 1],
                    ['section' => 'Claim Resolution Request', 'label' => 'I confirm that the damaged goods were maintained according to recommended garment care instructions.', 'name' => 'confirmCareInstructions', 'type' => 'checkbox', 'is_required' => true, 'sort_order' => 2, 'grid_cols' => 1],
                    ['section' => 'Claim Resolution Request', 'label' => 'I agree to return defective items for inspection if requested by Mecarvi QA team.', 'name' => 'agreeReturnIfRequired', 'type' => 'checkbox', 'is_required' => true, 'sort_order' => 3, 'grid_cols' => 1],
                ],
            ],
        ];

        foreach ($allForms as $pageSlug => $formData) {
            // Seed / Update FormPageSetting
            if (isset($formData['setting'])) {
                FormPageSetting::updateOrCreate(
                    ['page_slug' => $pageSlug],
                    array_merge(['page_slug' => $pageSlug], $formData['setting'])
                );
            }

            // Seed / Update QuoteFormField items
            foreach ($formData['fields'] as $field) {
                QuoteFormField::updateOrCreate(
                    [
                        'name' => $field['name'],
                        'page_slug' => $pageSlug,
                    ],
                    array_merge([
                        'page_slug' => $pageSlug,
                        'is_required' => false,
                        'is_active' => true,
                        'placeholder' => null,
                        'options' => null,
                        'config' => null,
                        'grid_cols' => 1,
                    ], $field)
                );
            }
        }
    }
}
