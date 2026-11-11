<?php

namespace Database\Seeders\Demo;

/**
 * Static, clearly fictional demonstration data. Names are common Pakistani names combined at random
 * (deterministically); CNICs are synthetic (prefix 99xxx is used for demo identities and does not
 * correspond to a real NADRA district code allocation used by these generators).
 */
final class DemoCatalog
{
    public const PASSWORD = 'Demo@12345';

    public const TENANTS = [
        ['code' => 'amcl', 'name' => 'AMCL', 'legal_name' => 'Al-Mawakhat Solutions (Pvt) Ltd (demo data)', 'city' => 'Lahore', 'color' => '#15803d', 'initials' => 'AM', 'shape' => 'circle',
            'tagline' => 'Shariah-compliant financing for every household – on Raast.', 'tagline_ur' => 'ہر گھرانے کے لیے شریعہ کے مطابق فنانسنگ۔',
            'regions' => [['PB-N', 'Punjab North', [['LHR', 'Lahore', ['LHR-01' => 'Lahore Gulberg', 'LHR-02' => 'Lahore Johar Town']], ['GRW', 'Gujranwala', ['GRW-01' => 'Gujranwala Main']]]],
                ['PB-S', 'Punjab South', [['MLT', 'Multan', ['MLT-01' => 'Multan Cantt']]]]]],
        ['code' => 'amanah', 'name' => 'Amanah Microfinance Demo', 'legal_name' => 'Amanah Microfinance Demo (Pvt) Ltd', 'city' => 'Karachi', 'color' => '#0f766e', 'initials' => 'AN', 'shape' => 'rounded',
            'tagline' => 'Small loans, big steps – for Karachi\'s entrepreneurs.', 'tagline_ur' => 'چھوٹے قرض، بڑے قدم۔',
            'regions' => [['SD', 'Sindh', [['KHI', 'Karachi', ['KHI-01' => 'Karachi Saddar', 'KHI-02' => 'Karachi Gulshan']], ['HYD', 'Hyderabad', ['HYD-01' => 'Hyderabad Latifabad']]]]]],
        ['code' => 'roshan', 'name' => 'Roshan Finance Demo', 'legal_name' => 'Roshan Finance Demo Ltd', 'city' => 'Islamabad', 'color' => '#0e7490', 'initials' => 'RF', 'shape' => 'hexagon',
            'tagline' => 'Solar and education financing that lights up futures.', 'tagline_ur' => 'سولر اور تعلیم کے لیے فنانسنگ۔',
            'regions' => [['ICT', 'Capital & North', [['ISB', 'Islamabad', ['ISB-01' => 'Islamabad Blue Area']], ['RWP', 'Rawalpindi', ['RWP-01' => 'Rawalpindi Saddar']]]]]],
        ['code' => 'karobar', 'name' => 'Naya Karobar Finance Demo', 'legal_name' => 'Naya Karobar Finance Demo (Pvt) Ltd', 'city' => 'Faisalabad', 'color' => '#1f2937', 'initials' => 'NK', 'shape' => 'diamond',
            'tagline' => 'Karobar Sahulat for traders, rickshaw owners and artisans.', 'tagline_ur' => 'کاروبار سہولت – تاجروں اور کاریگروں کے لیے۔',
            'regions' => [['PB-C', 'Punjab Central', [['FSD', 'Faisalabad', ['FSD-01' => 'Faisalabad D-Ground', 'FSD-02' => 'Faisalabad Jaranwala']], ['SKT', 'Sialkot', ['SKT-01' => 'Sialkot Main']]]]]],
        ['code' => 'barakah', 'name' => 'Barakah Financial Services Demo', 'legal_name' => 'Barakah Financial Services Demo Ltd', 'city' => 'Peshawar', 'color' => '#4d7c0f', 'initials' => 'BF', 'shape' => 'shield',
            'tagline' => 'Barakah in every instalment.', 'tagline_ur' => 'ہر قسط میں برکت۔',
            'regions' => [['KP', 'Khyber Pakhtunkhwa', [['PSH', 'Peshawar', ['PSH-01' => 'Peshawar University Road']], ['MDN', 'Mardan', ['MDN-01' => 'Mardan City']]]]]],
    ];

    public const MALE = ['Ali', 'Ahmed', 'Hassan', 'Usman', 'Bilal', 'Imran', 'Faisal', 'Kamran', 'Zeeshan', 'Asad', 'Hamza', 'Salman', 'Adnan', 'Waqas', 'Shahid', 'Tariq', 'Naveed', 'Junaid', 'Umair', 'Rizwan'];

    public const FEMALE = ['Ayesha', 'Fatima', 'Sana', 'Hira', 'Maryam', 'Zainab', 'Rabia', 'Saima', 'Nadia', 'Amna', 'Sadia', 'Iqra', 'Mehwish', 'Bushra', 'Kiran'];

    public const SURNAMES = ['Khan', 'Raza', 'Qureshi', 'Malik', 'Butt', 'Sheikh', 'Chaudhry', 'Siddiqui', 'Hussain', 'Iqbal', 'Javed', 'Mirza', 'Abbasi', 'Rana', 'Baig', 'Ansari', 'Akhtar', 'Shah'];

    public const OCCUPATIONS = [
        ['salaried', 'Office assistant', 'Textile mill (fictional)'], ['salaried', 'School teacher', 'City Public School (fictional)'], ['salaried', 'Delivery rider', 'Quick Logistics (fictional)'],
        ['business', 'Grocery shop owner', null], ['business', 'Tailoring shop', null], ['self_employed', 'Rickshaw driver', null], ['self_employed', 'Electrician', null],
        ['salaried', 'Nurse', 'General Hospital (fictional)'], ['business', 'Mobile repair shop', null], ['self_employed', 'Freelance designer', null],
    ];

    /** [code, name, name_ur, category, structure, terms overrides, charges, docs, vendor category] */
    public static function products(): array
    {
        $vendorDocs = [['CNIC_FRONT'], ['CNIC_BACK'], ['QUOTATION', 'applicant', false]];
        $std = [['PF', 'Processing fee', 'processing_fee', 'percentage', '2', 'upfront_deducted'], ['TKF', 'Takaful contribution', 'takaful', 'percentage', '1.5', 'added_to_instalments'],
            ['LPC', 'Late payment amount (charity)', 'late_payment', 'flat', '50', 'on_event']];

        return [
            ['MBS', 'Motorbike Sahulat', 'موٹر بائیک سہولت', 'motorbike', 'murabaha', ['min_amount' => 60000, 'max_amount' => 450000, 'min_tenure' => 6, 'max_tenure' => 24, 'profit_rate' => 24, 'down_payment_pct' => 10, 'min_income' => 30000, 'requires_vendor' => true, 'disbursement_mode' => 'vendor', 'guarantors_required' => 1], $std, $vendorDocs, 'motorbike',
                'Own a new motorbike through Murabaha: we buy the bike from the dealer and sell it to you at a disclosed profit.'],
            ['ARK', 'Apna Rikshaw', 'اپنا رکشہ', 'rickshaw', 'murabaha', ['min_amount' => 200000, 'max_amount' => 900000, 'min_tenure' => 12, 'max_tenure' => 36, 'profit_rate' => 22, 'down_payment_pct' => 15, 'min_income' => 35000, 'requires_vendor' => true, 'disbursement_mode' => 'vendor', 'guarantors_required' => 1], $std, $vendorDocs, 'rickshaw',
                'Become your own boss with a loader or passenger rickshaw – asset-backed Murabaha financing.'],
            ['MLT', 'Mera Laptop', 'میرا لیپ ٹاپ', 'laptop', 'murabaha', ['min_amount' => 50000, 'max_amount' => 350000, 'min_tenure' => 3, 'max_tenure' => 18, 'profit_rate' => 20, 'down_payment_pct' => 10, 'min_income' => 25000, 'requires_vendor' => true, 'disbursement_mode' => 'vendor'], $std, $vendorDocs, 'electronics',
                'Laptops for students and freelancers with easy monthly instalments.'],
            ['SSC', 'Sohni Scooty', 'سوہنی سکوٹی', 'scooty', 'murabaha', ['min_amount' => 80000, 'max_amount' => 400000, 'min_tenure' => 6, 'max_tenure' => 24, 'profit_rate' => 21, 'down_payment_pct' => 10, 'min_income' => 30000, 'requires_vendor' => true, 'disbursement_mode' => 'vendor'], $std, $vendorDocs, 'motorbike',
                'Scooty financing designed for working women and students.'],
            ['SLR', 'Solar Panel Financing', 'سولر پینل فنانسنگ', 'solar', 'ijarah', ['min_amount' => 150000, 'max_amount' => 1500000, 'min_tenure' => 12, 'max_tenure' => 48, 'profit_rate' => 19, 'profit_method' => 'reducing', 'down_payment_pct' => 20, 'min_income' => 50000, 'requires_vendor' => true, 'disbursement_mode' => 'vendor', 'guarantors_required' => 1], $std, array_merge($vendorDocs, [['UTILITY_BILL']]), 'solar',
                'Ijarah-based solar systems – cut your electricity bill while you pay rentals towards ownership.'],
            ['HAP', 'Home Appliances', 'گھریلو سامان', 'home_appliances', 'murabaha', ['min_amount' => 30000, 'max_amount' => 300000, 'min_tenure' => 3, 'max_tenure' => 18, 'profit_rate' => 23, 'down_payment_pct' => 10, 'min_income' => 25000, 'requires_vendor' => true, 'disbursement_mode' => 'vendor', 'allow_partial_payment' => false], $std, $vendorDocs, 'appliances',
                'Refrigerators, ACs, washing machines and sewing machines on Murabaha.'],
            ['KBS', 'Karobar Sahulat', 'کاروبار سہولت', 'karobar_sme', 'musharakah', ['min_amount' => 100000, 'max_amount' => 2000000, 'min_tenure' => 6, 'max_tenure' => 36, 'profit_rate' => 26, 'profit_method' => 'reducing', 'min_income' => 60000, 'requires_vendor' => false, 'disbursement_mode' => 'customer_bank', 'guarantors_required' => 1, 'cooling_off_waivable' => true, 'eligibility' => ['employment_types' => ['business', 'self_employed']]],
                $std, [['CNIC_FRONT'], ['CNIC_BACK'], ['BUSINESS_PROOF'], ['BANK_STATEMENT', 'applicant', true, 500000]], null,
                'Working-capital Musharakah partnership for shops and small enterprises. The 26% p.a. shown is the EXPECTED return under the agreed profit-sharing ratio (institution 60% / partner 40%); actual profit is shared at that ratio and losses in proportion to capital (see D-019 – structure pending Shariah board review).'],
            ['EDU', 'School Fee Financing', 'اسکول فیس فنانسنگ', 'school_fees', 'murabaha', ['min_amount' => 20000, 'max_amount' => 250000, 'min_tenure' => 3, 'max_tenure' => 12, 'profit_rate' => 18, 'min_income' => 25000, 'requires_vendor' => true, 'disbursement_mode' => 'vendor'],
                [['PF', 'Processing fee', 'processing_fee', 'flat', '500', 'upfront_deducted'], ['LPC', 'Late payment amount (charity)', 'late_payment', 'flat', '30', 'on_event']], [['CNIC_FRONT'], ['FEE_CHALLAN']], null,
                "Your child's school fees are paid directly to the school – no cash is given to you. Repay over the school term (see D-020)."],
        ];
    }

    /** [code, business name, category, items [name, price], is_merchant] */
    /** [business name, vendor category, items [name, price, product category], is_merchant] — item categories match ProductCategory (M4/M21) */
    public const VENDORS = [
        ['Crescent Motors (demo)', 'motorbike', [['Honda CD-70 2026', 165000, 'motorbike'], ['Honda CG-125 2026', 245000, 'motorbike'], ['Yamaha YBR-125G', 365000, 'motorbike'], ['Honda Scooty Dio 110 (demo model)', 210000, 'scooty']], false],
        ['Pak Wheels Bikes Point (demo)', 'motorbike', [['Suzuki GD-110S', 310000, 'motorbike'], ['United US-70', 115000, 'motorbike'], ['Super Power Scooty 70', 128000, 'scooty'], ['Road Prince Scooty 100', 152000, 'scooty']], true],
        ['Rehmat Rickshaw Dealers (demo)', 'rickshaw', [['Sazgar 6-seater rickshaw', 785000, 'rickshaw'], ['New Asia loader rickshaw', 610000, 'rickshaw']], false],
        ['TechZone Computers (demo)', 'electronics', [['HP 250 G10 laptop', 145000, 'laptop'], ['Dell Vostro 3520', 168000, 'laptop'], ['Lenovo IdeaPad Slim 3', 189000, 'laptop']], true],
        ['SunPower Solar Solutions (demo)', 'solar', [['5 kW on-grid solar system', 920000, 'solar'], ['3 kW hybrid system', 610000, 'solar']], false],
        ['Al-Noor Electronics (demo)', 'appliances', [['Dawlance 15 cft refrigerator', 142000, 'home_appliances'], ['Haier 1.5 ton DC inverter AC', 178000, 'home_appliances'], ['Singer sewing machine', 38000, 'home_appliances']], true],
        ['Madina Sewing Machines (demo)', 'appliances', [['Juki industrial sewing machine', 96000, 'home_appliances'], ['Brother domestic machine', 45000, 'home_appliances']], false],
        ['City Grocers (demo)', 'retail', [], true],
        ['Office Supplies Hub (demo)', 'operational', [], false],
        ['NetConnect ISP (demo)', 'operational', [], false],
        ['Prime Properties Rentals (demo)', 'operational', [], false],
        ['City Grammar School (demo)', 'education', [['Term fee – Grade 6 (2026-27)', 85000, 'school_fees'], ['Annual fee – Grade 9 (2026-27)', 145000, 'school_fees']], false],
        ['Fresh Applicant Traders (web request, demo)', 'other', [], false],
    ];

    /** role key => [display name prefix, count | 'branch' = one per branch] for demo staff accounts */
    public const STAFF = [
        'tenant_admin' => ['Tenant Admin', 1], 'loan_officer' => ['Loan Officer', 'branch'], 'branch_manager' => ['Branch Manager', 'branch'], 'cro' => ['CRO', 1],
        'manager_operations' => ['Manager Operations', 1], 'compliance_officer' => ['Compliance Officer', 1], 'risk_officer' => ['Risk Officer', 1],
        'disbursement_approver' => ['Disbursement Approver', 2], 'credit_analyst' => ['Credit Analyst', 1], 'approval_committee' => ['Approval Committee', 1],
        'visit_officer' => ['Visit Officer', 'branch'], 'recovery_agent' => ['Recovery Agent', 'branch'], 'audit_officer' => ['Audit Officer', 1],
        'finance_officer' => ['Finance Officer', 1], 'accountant' => ['Accountant', 1], 'customer_support' => ['Customer Support', 1],
    ];
}
