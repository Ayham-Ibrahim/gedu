<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Program;
use App\Models\SupportMember;
use App\Models\University;
use Illuminate\Database\Seeder;

/**
 * DatabaseSeeder
 *
 * Seeds REAL GEDULink data, sourced directly from the frontend's
 * studyData.js (STUDY_ABROAD_DATA) and TeamView translations —
 * not fictional placeholder universities.
 *
 * Honesty notes on inferred vs. real fields (see chat for full context):
 *   - Universities/cities/tuition/program names/visa info/living costs
 *     are real, taken directly from studyData.js.
 *   - Program degree/mode/language were NOT in the source data (it's just
 *     a flat array of program-name strings per university) — these are
 *     inferred from keywords and default to "Onsite". Review in Filament.
 *   - University website/established_year/accreditation are NOT in the
 *     source data — left blank. Fill in real values via the admin panel.
 *   - No standalone "Courses" exist in the real site data — left empty
 *     rather than keep the previous fabricated $299 IELTS-prep entries.
 *   - Support team uses real names/titles from TeamView, but personal
 *     emails were never provided anywhere — left blank, fill in yourself.
 *
 * After seeding, run: php artisan knowledge:build
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedEgypt();
        $this->seedUsa();
        $this->seedCanada();
        $this->seedMexico();
        $this->seedSouthAfrica();
        $this->seedGeorgia();
        $this->seedChina();
        $this->seedUae();
        $this->seedRomania();
        $this->seedSupportTeam();

        $this->command->info('✅ Real site data seeded. Now run: php artisan knowledge:build');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Infer a degree level from a plain program-name string.
     * The source data doesn't separate this out explicitly.
     */
    private function inferDegree(string $programName): string
    {
        $name = mb_strtolower($programName);

        if (str_contains($name, 'phd') || str_contains($name, 'doctor') || str_contains($name, 'dds')
            || str_contains($name, 'dmd') || str_contains($name, 'dba') || str_contains($name, 'md')
        ) {
            return 'Doctorate';
        }

        if (str_contains($name, 'master') || str_contains($name, 'mba') || str_contains($name, 'msc')
            || str_contains($name, 'm.s.') || str_contains($name, 'ms in')
        ) {
            return 'Master';
        }

        return 'Bachelor';
    }

    /**
     * Build a combined "admission_requirements" text block from the visa
     * info + living cost data, since the University model has no separate
     * visa/living-cost fields. This text gets embedded for RAG retrieval.
     */
    private function buildAdmissionRequirementsText(array $visa, array $livingCost): string
    {
        $text = "Visa type: {$visa['type']}\n";
        $text .= "Visa requirements:\n";
        foreach ($visa['requirements'] as $req) {
            $text .= "- {$req}\n";
        }

        if (!empty($visa['embassy_abudhabi'])) {
            $e = $visa['embassy_abudhabi'];
            $text .= "\nEmbassy contact (Abu Dhabi): {$e['name']}";
            if (!empty($e['phone'])) $text .= ", Phone: {$e['phone']}";
            if (!empty($e['email'])) $text .= ", Email: {$e['email']}";
            $text .= "\n";
        }

        if (!empty($livingCost)) {
            $text .= "\nEstimated monthly living costs:\n";
            foreach ($livingCost as $label => $value) {
                $label = str_replace('_', ' ', $label);
                $text .= "- " . ucfirst($label) . ": {$value}\n";
            }
        }

        return trim($text);
    }

    /**
     * Create programs for a university from a flat array of program-name
     * strings (the shape used in studyData.js), reusing the university's
     * overall tuition range for each program.
     */
    private function createProgramsFromNames(University $university, array $programNames, string $tuition, string $language): void
    {
        foreach ($programNames as $programName) {
            Program::create([
                'university_id' => $university->id,
                'name'          => $programName,
                'name_ar'       => $programName, // TODO: add real Arabic translations via admin panel
                'degree'        => $this->inferDegree($programName),
                'mode'          => 'Onsite', // No online programs are listed in the source data
                'duration'      => $this->inferDegree($programName) === 'Bachelor' ? '4 Years' : '1-2 Years',
                'fees'          => $tuition,
                'language'      => $language,
            ]);
        }
    }

    // ─── Egypt ───────────────────────────────────────────────────────────────

    private function seedEgypt(): void
    {
        $visa = [
            'type' => 'Egyptian student visa',
            'requirements' => [
                'Official university acceptance letter approved by the Egyptian Ministry of Higher Education (International Students Office)',
                'Valid passport with at least 6 months remaining validity',
                'Bank statement or proof of sponsorship covering living and tuition costs',
                'Approved medical exam certificate (free of major infectious diseases)',
                'Certified copies of academic qualifications and previous certificates',
                'Completed visa application form and embassy fee payment',
            ],
            'embassy_abudhabi' => [
                'name' => 'Embassy of the Arab Republic of Egypt – Abu Dhabi',
                'phone' => '+971 2 444 5555',
                'email' => 'embassy.abudhabi@mfa.gov.eg',
            ],
        ];
        $livingCost = [
            'housing_shared_room' => '$150 - $300 / month',
            'housing_student_residence' => '$100 - $250 / month',
            'food_grocery_home_cooking' => '$100 - $200 / month',
            'economic_restaurant_meal' => '$3 - $7',
            'transport_monthly_pass' => '$15 - $30',
            'bills_internet_phone' => '$20 - $45 / month',
        ];
        $admissionText = $this->buildAdmissionRequirementsText($visa, $livingCost);

        $cairo = University::create([
            'name' => 'Cairo University',
            'name_ar' => 'جامعة القاهرة',
            'country' => 'Egypt',
            'city' => 'Giza / Cairo',
            'description' => 'One of the oldest and largest universities in the Arab world.',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($cairo, [
            'Computer Science & AI',
            'Medicine & Surgery (MD)',
            'Mechanical Engineering',
            'Pharmacy & Clinical Pharmacy',
            'Business Administration',
            'Economics & Political Science',
        ], '$3,000 - $6,000 / Year', 'Arabic / English');

        $auc = University::create([
            'name' => 'The American University in Cairo',
            'name_ar' => 'الجامعة الأمريكية بالقاهرة',
            'country' => 'Egypt',
            'city' => 'New Cairo',
            'description' => 'A leading English-language university in the Middle East.',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($auc, [
            'Global MBA',
            'Computer Science',
            'Graphic Design',
            'Mechanical Engineering',
            'Integrated Marketing Communications',
            'Political Science & International Relations',
        ], '$12,000 - $18,000 / Year', 'English');

        $ainShams = University::create([
            'name' => 'Ain Shams University',
            'name_ar' => 'جامعة عين شمس',
            'country' => 'Egypt',
            'city' => 'Cairo',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($ainShams, [
            'Civil & Infrastructure Engineering',
            'Dentistry & Oral Health (DDS)',
            'Information Systems & Data Science',
            'Architectural Engineering',
            'Software Engineering',
            'Accounting & Finance',
        ], '$2,500 - $5,000 / Year', 'Arabic / English');
    }

    // ─── USA ─────────────────────────────────────────────────────────────────

    private function seedUsa(): void
    {
        $visa = [
            'type' => 'F-1 Student Visa for the United States',
            'requirements' => [
                'Official I-20 form issued by the partner university',
                'Full SEVIS fee payment with I-901 receipt from immigration services',
                'Fully completed and confirmed DS-160 nonimmigrant visa application form',
                'Scheduled personal interview at the Abu Dhabi embassy or Dubai consulate',
                'Strong, documented proof of financial support (sponsor bank statement, personal funding, or approved scholarship)',
                'Proof of intent to depart the US and strong ties motivating return home',
            ],
            'embassy_abudhabi' => [
                'name' => 'Embassy of the United States of America – Abu Dhabi',
                'phone' => '+971 2 414 2200',
                'email' => 'abudhabiacs@state.gov',
            ],
        ];
        $livingCost = [
            'university_dormitory' => '$800 - $1500 / month',
            'shared_apartment' => '$700 - $1800 / month',
            'food_groceries' => '$300 - $600 / month',
            'economic_restaurant_meal' => '$12 - $22',
            'transport_monthly_pass' => '$70 - $150',
            'bills_internet_phone' => '$100 - $250 / month',
        ];
        $admissionText = $this->buildAdmissionRequirementsText($visa, $livingCost);

        $potomac = University::create([
            'name' => 'Potomac University',
            'name_ar' => 'جامعة بوتوماك',
            'country' => 'United States',
            'city' => 'Washington, D.C.',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($potomac, [
            'Bachelor of Science in Computer Science',
            'Master of Business Administration (MBA)',
            'Cybersecurity & Information Assurance',
            'International Business Administration',
            'Information Technology Management',
        ], '$12,000 - $15,000 / Year', 'English');

        $westAlabama = University::create([
            'name' => 'University of West Alabama',
            'name_ar' => 'جامعة ويست ألاباما',
            'country' => 'United States',
            'city' => 'Livingston, Alabama',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($westAlabama, [
            'Bachelor of Business Administration',
            'Master of Science in Experimental Psychology',
            'Integrated Marketing Communications',
            'Biology and Environmental Sciences',
            'Sport Management & Athletics',
        ], '$10,000 - $14,000 / Year', 'English');
    }

    // ─── Canada ──────────────────────────────────────────────────────────────

    private function seedCanada(): void
    {
        $visa = [
            'type' => 'Canadian Study Permit',
            'requirements' => [
                'Official Letter of Acceptance from a Designated Learning Institution (DLI)',
                'Proof of sufficient financial means to cover tuition and living costs (including family if applicable)',
                'Police clearance certificate, certified and translated',
                'Approved medical exam from an embassy-approved physician',
                'Statement of Purpose / Study Plan letter',
                'Visa processing fee payment and biometrics appointment',
            ],
            'embassy_abudhabi' => [
                'name' => 'Embassy of Canada – Abu Dhabi',
                'phone' => '+971 2 694 0300',
            ],
        ];
        $livingCost = [
            'shared_room_or_apartment' => '600 - 1200 CAD / month',
            'university_residence' => '700 - 1500 CAD / month',
            'food_and_groceries' => '300 - 500 CAD / month',
            'economic_restaurant_meal' => '15 - 25 CAD',
            'transport_monthly_pass' => '80 - 130 CAD',
            'bills_internet_phone' => '90 - 180 CAD / month',
        ];
        $admissionText = $this->buildAdmissionRequirementsText($visa, $livingCost);

        $toronto = University::create([
            'name' => 'University of Toronto',
            'name_ar' => 'جامعة تورنتو',
            'country' => 'Canada',
            'city' => 'Toronto, Ontario',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($toronto, [
            'Computer Science & Advanced Programming',
            'Master of Business Administration (Rotman MBA)',
            'Electrical & Computer Engineering',
            'Biotechnology & Health Sciences',
            'Data Science & Statistical Analysis',
        ], '$35,000 - $55,000 / Year', 'English');

        $mcgill = University::create([
            'name' => 'McGill University',
            'name_ar' => 'جامعة ماكغيل',
            'country' => 'Canada',
            'city' => 'Montreal, Quebec',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($mcgill, [
            'Mechanical & Aerospace Engineering',
            'Clinical Psychology & Neuroscience',
            'Commerce & Global Finance',
            'Software Engineering',
            'Economics and Strategic Management',
        ], '$30,000 - $48,000 / Year', 'English');

        $ubc = University::create([
            'name' => 'University of British Columbia',
            'name_ar' => 'جامعة كولومبيا البريطانية',
            'country' => 'Canada',
            'city' => 'Vancouver, BC',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($ubc, [
            'Civil & Environmental Engineering',
            'Environmental Sciences',
            'Business Administration (Sauder BBA)',
            'International Relations & Public Policy',
            'Computer Science & Machine Learning',
        ], '$32,000 - $45,000 / Year', 'English');
    }

    // ─── Mexico ──────────────────────────────────────────────────────────────

    private function seedMexico(): void
    {
        $visa = [
            'type' => 'Mexican Temporary Resident Student Visa',
            'requirements' => [
                'Original, officially certified university acceptance letter from Universidad Azteca',
                'Valid passport for at least six months with color photos',
                'Bank statement for the last 3 months proving financial stability',
                'Two recent biometric passport photos on a white background',
                'Visa processing fee payment and in-person embassy interview',
            ],
            'embassy_abudhabi' => [
                'name' => 'Embassy of Mexico – Abu Dhabi',
                'phone' => '+971 2 511 9900',
                'email' => 'consularemiau@sre.gob.mx',
            ],
        ];
        $livingCost = [
            'housing_shared_apartment' => '$200 - $350 / month',
            'housing_private_studio' => '$300 - $600 / month',
            'food_groceries' => '$150 - $250 / month',
            'economic_restaurant_meal' => '$5 - $10',
            'transport_monthly_pass' => '$15 - $30',
            'bills_internet_phone' => '$30 - $60 / month',
        ];
        $admissionText = $this->buildAdmissionRequirementsText($visa, $livingCost);

        $azteca = University::create([
            'name' => 'Universidad Azteca',
            'name_ar' => 'جامعة أزتيكا',
            'country' => 'Mexico',
            'city' => 'Chalco, State of Mexico',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($azteca, [
            'Bachelor of Business Administration (BBA)',
            'Executive Master of Business Administration (EMBA)',
            'Doctor of Business Administration (DBA)',
            'Information Technology Management',
            'International Law & Human Rights',
            'Educational Sciences & Leadership',
        ], '$4,000 - $7,000 / Year', 'English');
    }

    // ─── South Africa ────────────────────────────────────────────────────────

    private function seedSouthAfrica(): void
    {
        $visa = [
            'type' => 'South African Study Visa',
            'requirements' => [
                'Official acceptance letter from the accredited South African academic institution',
                'Comprehensive medical report including a chest X-ray clearing the applicant of pulmonary tuberculosis',
                'Proof of financial means to cover annual living and tuition costs via certified bank statements',
                'Police clearance certificate from country of residence for the last 12 months',
                'Approved international medical insurance covering the full stay in South Africa',
                'Confirmed round-trip flight booking or a cash deposit guarantee for return',
            ],
            'embassy_abudhabi' => [
                'name' => 'Embassy of the Republic of South Africa – Abu Dhabi',
                'phone' => '+971 2 417 6400',
                'email' => 'consular.abudhabi@dirco.gov.za',
            ],
        ];
        $livingCost = [
            'housing_shared_apartment' => '$250 - $450 / month',
            'university_residence' => '$200 - $400 / month',
            'food_groceries' => '$150 - $300 / month',
            'economic_restaurant_meal' => '$7 - $12',
            'transport_monthly_pass' => '$30 - $60',
            'bills_internet_phone' => '$40 - $80 / month',
        ];
        $admissionText = $this->buildAdmissionRequirementsText($visa, $livingCost);

        $benin = University::create([
            'name' => 'Benin University',
            'name_ar' => 'جامعة بينين - برنامج جنوب أفريقيا المعتمد',
            'country' => 'South Africa',
            'city' => 'Western Cape / Pretoria',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($benin, [
            'Bachelor of Business Administration',
            'Public Health & Epidemiology',
            'Information Technology Services',
            'Community Development Studies',
            'Environmental Protection & Eco-Systems',
            'Business Management & Leadership',
        ], '$2,000 - $4,500 / Year', 'English');
    }

    // ─── Georgia ─────────────────────────────────────────────────────────────

    private function seedGeorgia(): void
    {
        $visa = [
            'type' => 'Georgian National Study Visa (D3 Visa)',
            'requirements' => [
                'Official university acceptance letter, certified by the Georgian Ministry of Education and Science and the national registration system',
                'Valid passport covering the full academic period with color photos',
                'Certified bank statement proving financial means and adequate support for living in Georgia',
                'Comprehensive medical and accident insurance valid in Georgia for the full stay',
                'Certified translation of all academic documents into Georgian or English',
            ],
            'embassy_abudhabi' => [
                'name' => 'Embassy of Georgia – Abu Dhabi',
                'phone' => '+971 2 644 6023',
                'email' => 'abudhabi.emb@mfa.gov.ge',
            ],
        ];
        $livingCost = [
            'shared_flat' => '$200 - $400 / month',
            'private_apartment' => '$350 - $700 / month',
            'food_groceries' => '$100 - $200 / month',
            'economic_restaurant_meal' => '$6 - $11',
            'transport_monthly_pass' => '$10 - $20',
            'bills_internet_phone' => '$25 - $50 / month',
        ];
        $admissionText = $this->buildAdmissionRequirementsText($visa, $livingCost);

        $tbilisiMed = University::create([
            'name' => 'Tbilisi State Medical University',
            'name_ar' => 'جامعة تبليسي الطبية الحكومية',
            'country' => 'Georgia',
            'city' => 'Tbilisi',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($tbilisiMed, [
            'Doctor of Medicine (MD) - Certified English Program',
            'Doctor of Dental Medicine (DMD)',
            'Bachelor of Pharmacy',
            'Physical Medicine and Rehabilitation',
        ], '$5,000 - $8,000 / Year', 'English');

        $caucasus = University::create([
            'name' => 'Caucasus University',
            'name_ar' => 'جامعة القوقاز',
            'country' => 'Georgia',
            'city' => 'Tbilisi',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($caucasus, [
            'Information Technology & Computer Science',
            'Bachelor of Business Administration (BBA)',
            'Cybersecurity & Networks Engineering',
            'International Relations and Diplomacy',
            'Tourism & Hospitality Management',
        ], '$4,000 - $6,500 / Year', 'English');

        $georgianTech = University::create([
            'name' => 'Georgian Technical University',
            'name_ar' => 'الجامعة التقنية الجورجية',
            'country' => 'Georgia',
            'city' => 'Tbilisi',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($georgianTech, [
            'Civil Engineering & Infrastructure',
            'Software Engineering',
            'Architecture and Town Planning',
            'Electrical Power Engineering',
            'Industrial Automation & Control Systems',
        ], '$3,550 - $5,000 / Year', 'English');
    }

    // ─── China ───────────────────────────────────────────────────────────────

    private function seedChina(): void
    {
        $visa = [
            'type' => 'Chinese Long-Term Student Visa (X1 Visa)',
            'requirements' => [
                'JW202 or JW201 form for incoming international student visa applications',
                'Final certified acceptance letter from the accredited Chinese university',
                'Comprehensive Foreigner Physical Examination Form (Chinese medical exam)',
                'Bank statement covering a full academic year of tuition and living expenses',
                'Valid passport plus certified and translated prior academic certificates',
            ],
            'embassy_abudhabi' => [
                'name' => 'Embassy of the People\'s Republic of China – Abu Dhabi',
                'phone' => '+971 2 443 4276',
                'email' => 'chinaemb_ae@mfa.gov.cn',
            ],
        ];
        $livingCost = [
            'shared_room' => '$150 - $300 / month',
            'student_dormitory_on_campus' => '$100 - $250 / month',
            'food_on_campus' => '$100 - $180 / month',
            'economic_restaurant_meal' => '$3 - $7',
            'subway_transportation_monthly' => '$15 - $35',
            'bills_internet_phone' => '$15 - $30 / month',
        ];
        $admissionText = $this->buildAdmissionRequirementsText($visa, $livingCost);

        $tsinghua = University::create([
            'name' => 'Tsinghua University',
            'name_ar' => 'جامعة تسينغهوا',
            'country' => 'China',
            'city' => 'Beijing',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($tsinghua, [
            'Global Business Administration',
            'Advanced Computer Science',
            'Engineering & Technology Management',
            'Artificial Intelligence & Software Engineering',
            'Structural & Civil Engineering',
        ], '$6,500 - $9,500 / Year', 'English');

        $peking = University::create([
            'name' => 'Peking University',
            'name_ar' => 'جامعة بكين',
            'country' => 'China',
            'city' => 'Beijing',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($peking, [
            'International Relations & Public Policy',
            'Computer Science & Advanced Software Development',
            'Global MBA (Guanghua MBA)',
            'Chinese Language, History & Culture Studies',
            'International Economics and Finance',
        ], '$6,000 - $9,000 / Year', 'English');

        $fudan = University::create([
            'name' => 'Fudan University',
            'name_ar' => 'جامعة فودان',
            'country' => 'China',
            'city' => 'Shanghai',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($fudan, [
            'International Finance & Economics',
            'Data Science & Statistical Modeling',
            'MBBS (Medical Studies in English)',
            'Strategic Marketing & Management Science',
            'Advanced Chemistry and Material Science',
        ], '$5,500 - $8,200 / Year', 'English');
    }

    // ─── UAE ─────────────────────────────────────────────────────────────────

    private function seedUae(): void
    {
        $visa = [
            'type' => 'UAE Student Visa & Residency',
            'requirements' => [
                'Final unconditional acceptance letter from a licensed and accredited UAE university',
                'Valid passport for the student for more than 6 months with recent certified photos',
                'Comprehensive medical fitness exam at an approved health center',
                'A copy of a bank guarantee or document from the university or approved financial sponsor',
                'Comprehensive and valid health insurance within the UAE',
                'Emirates ID issuance request and residency file activation',
            ],
            'embassy_abudhabi' => [
                'name' => 'Federal Authority for Identity, Citizenship, Customs & Port Security – Abu Dhabi',
                'phone' => '+971 2 403 0000',
            ],
        ];
        $livingCost = [
            'shared_apartment' => '1500 - 3000 AED / month',
            'student_hostel_dormitory' => '1000 - 2500 AED / month',
            'food_and_grocery' => '800 - 1500 AED / month',
            'economic_restaurant_meal' => '25 - 45 AED',
            'transport_monthly_pass_card' => '150 - 300 AED',
            'bills_internet_phone' => '250 - 500 AED / month',
        ];
        $admissionText = $this->buildAdmissionRequirementsText($visa, $livingCost);

        $uaeUni = University::create([
            'name' => 'United Arab Emirates University',
            'name_ar' => 'جامعة الإمارات العربية المتحدة',
            'country' => 'United Arab Emirates',
            'city' => 'Al Ain',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($uaeUni, [
            'Mechanical Engineering & Robotic Automation',
            'Computer Science & Systems Security',
            'Doctor of Medicine & Health Sciences (MD)',
            'Business Administration & Global Entrepreneurship',
            'Accounting, Auditing & Financial Analysis',
        ], 'AED 35,000 - 55,000 / Year', 'Arabic / English');

        $khalifa = University::create([
            'name' => 'Khalifa University',
            'name_ar' => 'جامعة خليفة',
            'country' => 'United Arab Emirates',
            'city' => 'Abu Dhabi',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($khalifa, [
            'Aerospace & Defense Engineering',
            'Biomedical Engineering & Devices Innovation',
            'Cybersecurity & Computer Networks',
            'Executive Master of Business Administration (EMBA)',
            'Chemical & Environmental Engineering',
        ], 'AED 50,000 - 80,000 / Year', 'English');

        $aus = University::create([
            'name' => 'American University of Sharjah',
            'name_ar' => 'الجامعة الأمريكية في الشارقة',
            'country' => 'United Arab Emirates',
            'city' => 'Sharjah',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($aus, [
            'Architecture and Urban Planning',
            'Electrical & Electronic Engineering',
            'Finance, Banking and Asset Valuation',
            'Civil Engineering & Infrastructure Management',
            'Master of Science in Business Management',
        ], 'AED 60,000 - 95,000 / Year', 'English');
    }

    // ─── Romania ─────────────────────────────────────────────────────────────

    private function seedRomania(): void
    {
        $visa = [
            'type' => 'Romanian Long-Term Study Visa (D/SD Type)',
            'requirements' => [
                'Acceptance letter officially issued by the Romanian Ministry of Education',
                'Full first-year tuition payment with the original bank transfer receipt',
                'Proof of monthly financial sufficiency (at least Romania\'s minimum wage) for the full stay, via bank statement',
                'Original medical exam certificate and police clearance certificate, translated and certified',
                'International travel medical insurance valid in Romania, covering at least €30,000',
                'Parental consent and certified guardianship authorization if the student is under 18',
            ],
            'embassy_abudhabi' => [
                'name' => 'Embassy of Romania – Abu Dhabi',
                'phone' => '+971 2 635 3217',
                'email' => 'abudhabi@mae.ro',
            ],
        ];
        $livingCost = [
            'shared_room_or_flat' => '150 - 300 EUR / month',
            'university_hostel' => '80 - 150 EUR / month',
            'food_groceries' => '150 - 250 EUR / month',
            'economic_restaurant_meal' => '6 - 12 EUR',
            'student_transportation_pass' => '10 - 20 EUR',
            'bills_internet_phone' => '20 - 40 EUR / month',
        ];
        $admissionText = $this->buildAdmissionRequirementsText($visa, $livingCost);

        $bucharest = University::create([
            'name' => 'University of Bucharest',
            'name_ar' => 'جامعة بوخارست',
            'country' => 'Romania',
            'city' => 'Bucharest',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($bucharest, [
            'Applied Computer Science & Algorithms',
            'Bachelor of Business Administration (BBA)',
            'English and American Studies Program',
            'Biochemistry and Molecular Biology',
            'Software Engineering & Web Technologies',
        ], '€2,200 - €4,500 / Year', 'English');

        $babesBolyai = University::create([
            'name' => 'Babeș-Bolyai University',
            'name_ar' => 'جامعة بابيش-بولياي',
            'country' => 'Romania',
            'city' => 'Cluj-Napoca',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($babesBolyai, [
            'Global Business & Marketing Studies',
            'Informatics & Artificial Intelligence (AI)',
            'Advanced Theoretical Physics',
            'Clinical Psychology and Cognitive Science',
            'International Relations and Security Studies',
        ], '€2,000 - €4,000 / Year', 'English');

        $polytechnic = University::create([
            'name' => 'Polytechnic University of Bucharest',
            'name_ar' => 'جامعة الـ بوليتكنيك في بوخارست',
            'country' => 'Romania',
            'city' => 'Bucharest',
            'admission_requirements' => $admissionText,
        ]);
        $this->createProgramsFromNames($polytechnic, [
            'Aerospace Engineering & Navigation',
            'Automatic Control & Industrial Software Management',
            'Telecommunications Networks and Systems',
            'Mechanical Engineering & Advanced Fluid Dynamics',
            'Renewables and Clean Energy Systems Engineering',
        ], '€2,500 - €5,000 / Year', 'English');
    }

    // ─── Support Team (real advisors from TeamView) ─────────────────────────

    private function seedSupportTeam(): void
    {
        // Real names/titles/specialties from the site's TeamView translations.
        // Phone/whatsapp use the one real contact channel found in the
        // codebase (App.jsx's WhatsApp button). Emails were never provided
        // anywhere in the source data — fill these in yourself via Filament.
        SupportMember::create([
            'name' => 'Entisar Jafar',
            'name_ar' => 'انتصار جعفر',
            'department' => 'Head of Academic Consultants',
            'phone' => '+971589690014',
            'whatsapp' => '+971589690014',
            'specialty' => 'Over 4 years of experience in guidance and admission to United States universities.',
        ]);

        SupportMember::create([
            'name' => 'Noor Al Dunya',
            'name_ar' => 'نور الدنيا',
            'department' => 'Scholarship Requirements Coordinator',
            'phone' => '+971589690014',
            'whatsapp' => '+971589690014',
            'specialty' => '2 years of experience in scholarships at universities in Mexico and Africa.',
        ]);

        SupportMember::create([
            'name' => 'Manal Al Dunya',
            'name_ar' => 'منال الدنيا',
            'department' => 'Academic Support & Assistance Expert',
            'phone' => '+971589690014',
            'whatsapp' => '+971589690014',
            'specialty' => '2 years of experience in academic support and scholarship admissions in Europe.',
        ]);
    }
}