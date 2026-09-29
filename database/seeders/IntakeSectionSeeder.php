<?php

namespace Database\Seeders;

use App\Models\CompliancePolicy;
use App\Models\IntakeQuestion;
use App\Models\IntakeSection;
use Illuminate\Database\Seeder;

/**
 * Seeds the 8 workflow-question sections, the 66 questions, and their mapping to the 101
 * compliance_policies rows — all transcribed directly from the client's interactive prototype
 * (changes/Proactive Compliance Marketplace - Interactive (1).html, the QUESTIONS array) and its
 * "Workflow & Policy Mapping" doc. `title`, `prompt_summary` (the one-line question shown under
 * the title) and `why_we_ask` (the side panel rationale) are all transcribed word-for-word from
 * the prototype's Q(id, chapter, title, prompt, policyCodes, why, fields, extras) entries — only
 * the prototype's per-topic structured sub-fields (who/where/frequency/etc.) are intentionally
 * left out, since this wizard captures one free-text response per question instead.
 */
class IntakeSectionSeeder extends Seeder
{
    public function run(): void
    {
        $sections = [
            'compliance_program' => [
                'label' => 'Compliance program',
                'questions' => [
                    ['title' => 'Owner & board oversight', 'policies' => ['CMP-01'], 'prompt_summary' => 'How do your owners or governing board oversee the compliance program?', 'why_we_ask' => 'OIG guidance expects leadership to actively oversee compliance, not just approve it once.'],
                    ['title' => "Management's role", 'policies' => ['CMP-02'], 'prompt_summary' => 'Which managers carry compliance duties, and how do they act on problems?', 'why_we_ask' => 'Managers turn policy into day-to-day practice. This names who supervises the program and who fixes problems.'],
                    ['title' => 'Compliance Officer duties', 'policies' => ['CMP-03'], 'prompt_summary' => 'How does your Compliance Officer carry out the role day to day?', 'why_we_ask' => 'Regulators look for a named, empowered Compliance Officer with a backup and a clear reporting line.'],
                    ['title' => 'Compliance Committee', 'policies' => ['CMP-04'], 'prompt_summary' => 'How does your Compliance Committee work?', 'why_we_ask' => 'A committee spreads compliance across departments so issues surface early.'],
                    ['title' => 'Staff sign-offs', 'policies' => ['CMP-05', 'SEC-26'], 'prompt_summary' => 'How do staff sign off on your code of conduct and acceptable-use rules?', 'why_we_ask' => 'Signed acknowledgments prove each person was told the rules, including the duty to report problems.'],
                    ['title' => 'Compliance & HIPAA training', 'policies' => ['CMP-07', 'PRV-36', 'SEC-43'], 'prompt_summary' => 'How is compliance, HIPAA privacy and security training delivered and tracked?', 'why_we_ask' => 'All three manuals require training at hire and periodically after. One answer covers them all.'],
                    ['title' => 'Reporting concerns', 'policies' => ['CMP-08', 'PRV-11', 'PRV-36'], 'prompt_summary' => 'How can staff and patients report a compliance or privacy concern?', 'why_we_ask' => 'Staff need several safe ways to report, and must be protected from retaliation when they do, including whistleblowers.'],
                    ['title' => 'Discipline & sanctions', 'policies' => ['CMP-15', 'SEC-13', 'PRV-36'], 'prompt_summary' => 'How is discipline decided when someone breaks a compliance or HIPAA policy?', 'why_we_ask' => 'Consistent, documented discipline is one of the seven elements. The same process covers compliance and HIPAA violations.'],
                    ['title' => 'Investigations & overpayments', 'policies' => ['CMP-10'], 'prompt_summary' => 'How do you investigate a reported concern from start to finish?', 'why_we_ask' => 'Once an overpayment is confirmed, federal law gives you 60 days to report and return it. That makes the timeline matter.'],
                    ['title' => 'Program & policy review', 'policies' => ['CMP-17', 'SEC-44'], 'prompt_summary' => 'How do you check the program is working and keep policies up to date?', 'why_we_ask' => 'Both the compliance program and the security policies need an annual review with documented approvals.'],
                ],
            ],
            'billing_audits' => [
                'label' => 'Billing & audits',
                'questions' => [
                    ['title' => 'Contracts & referral relationships', 'policies' => ['CMP-06'], 'prompt_summary' => 'How do you review contracts and referral relationships for fraud and abuse risk?', 'why_we_ask' => 'Leases, directorships and referral arrangements are where Stark and Anti-Kickback risk usually hides.'],
                    ['title' => 'Coding & billing audits', 'policies' => ['CMP-09'], 'prompt_summary' => 'What audits do you run on coding, documentation and billing?', 'why_we_ask' => 'Your audit plan shows payers you catch errors yourself. It shapes the mini audit in the Advanced package.'],
                    ['title' => 'Medical necessity', 'policies' => ['CMP-11'], 'prompt_summary' => 'How do you confirm medical necessity before tests and procedures?', 'why_we_ask' => 'Orders without a supporting diagnosis are a common audit finding. So are missing ABNs.'],
                    ['title' => 'Claims workflow', 'policies' => ['CMP-12'], 'prompt_summary' => 'How does an encounter become a submitted claim?', 'why_we_ask' => 'This describes who codes, who checks and who releases claims, so the policy reflects your real workflow.'],
                    ['title' => 'Out-of-network plan patients', 'policies' => ['CMP-13'], 'prompt_summary' => 'How do you handle Medicare Advantage or Medicaid plan patients when you’re out of network?', 'why_we_ask' => 'Out-of-network plan patients need specific notices and consent within set timeframes.'],
                    ['title' => 'Exclusion screening', 'policies' => ['CMP-14'], 'prompt_summary' => 'How do you screen staff, providers and vendors against exclusion lists?', 'why_we_ask' => 'Billing for services by an excluded person creates overpayments and penalties.'],
                    ['title' => 'Government visits & requests', 'policies' => ['CMP-16', 'PRV-19'], 'prompt_summary' => 'What do staff do when an investigator, auditor or surveyor arrives or calls?', 'why_we_ask' => 'A calm, scripted response protects the practice and patient privacy. One process covers investigations and oversight audits.'],
                ],
            ],
            'patient_privacy' => [
                'label' => 'Patient privacy',
                'questions' => [
                    ['title' => 'Notice of Privacy Practices', 'policies' => ['PRV-31', 'PRV-10'], 'prompt_summary' => 'How do patients receive your Notice of Privacy Practices, and how do you keep it accurate?', 'why_we_ask' => 'Every patient must be offered the Notice, and your real practices must match what it says.'],
                    ['title' => 'Routine uses & disclosures', 'policies' => ['PRV-01'], 'prompt_summary' => 'How do staff decide if a records request is routine (treatment, payment, operations) or needs review?', 'why_we_ask' => 'Most sharing is routine, but staff need to spot the requests that aren’t.'],
                    ['title' => 'Minimum necessary & role-based access', 'policies' => ['PRV-03', 'PRV-26', 'SEC-15'], 'prompt_summary' => 'How do you limit patient information to what each role needs?', 'why_we_ask' => 'Covers the privacy rule’s minimum necessary standard and how it’s enforced in your systems.'],
                    ['title' => 'Patient access to records', 'policies' => ['PRV-34'], 'prompt_summary' => 'How do patients request and receive copies of their records?', 'why_we_ask' => 'Patients have a right to their records within 30 days. The OCR actively enforces it.'],
                    ['title' => 'Requests to amend records', 'policies' => ['PRV-35'], 'prompt_summary' => 'How do you handle a patient’s written request to correct their record?', 'why_we_ask' => 'You have 60 days to accept or deny, and accepted amendments must reach earlier recipients.'],
                    ['title' => 'Accounting of disclosures', 'policies' => ['PRV-04'], 'prompt_summary' => 'How do you track disclosures a patient can ask for a list of?', 'why_we_ask' => 'Patients can request a list of certain disclosures from the past six years.'],
                    ['title' => 'Restriction requests', 'policies' => ['PRV-32'], 'prompt_summary' => 'How do you handle requests to restrict how information is shared?', 'why_we_ask' => 'You must honor a request not to bill a health plan when the patient paid in full out of pocket.'],
                    ['title' => 'Confidential communications', 'policies' => ['PRV-09', 'PRV-33'], 'prompt_summary' => 'How do you handle a patient’s request to be contacted another way, like a different phone, address or no voicemail?', 'why_we_ask' => 'Reasonable requests must be honored without asking the patient why.'],
                    ['title' => "Verifying who's asking", 'policies' => ['PRV-30'], 'prompt_summary' => 'How do staff verify identity and authority before releasing information?', 'why_we_ask' => 'Releasing records to the wrong person is one of the most common privacy breaches.'],
                    ['title' => 'Patient authorizations', 'policies' => ['PRV-14'], 'prompt_summary' => 'How do you check and file signed authorizations to release information?', 'why_we_ask' => 'Authorizations must have required elements. Psychotherapy notes and marketing need their own.'],
                    ['title' => 'Personal representatives', 'policies' => ['PRV-08'], 'prompt_summary' => 'How do you decide who can act for a patient, such as a guardian, POA or parent of a minor?', 'why_we_ask' => 'Representatives get the patient’s rights, so their authority must be checked and recorded.'],
                    ['title' => 'Family & caregivers', 'policies' => ['PRV-15'], 'prompt_summary' => 'How do you decide what to share with family members or caregivers?', 'why_we_ask' => 'Covers sharing when the patient is present, incapacitated or in an emergency.'],
                    ['title' => 'Fundraising', 'policies' => ['PRV-28'], 'prompt_summary' => 'Does your practice use patient information for fundraising?', 'why_we_ask' => 'Most practices don’t fundraise. If you do, every message needs a simple opt-out.'],
                ],
            ],
            'special_requests' => [
                'label' => 'Special requests',
                'questions' => [
                    ['title' => 'Deceased patients', 'policies' => ['PRV-05', 'PRV-07', 'PRV-20'], 'prompt_summary' => 'How do you handle requests about patients who have died?', 'why_we_ask' => 'Records stay protected for 50 years after death. Covers coroners, funeral homes, estates and organ donation.'],
                    ['title' => 'Public health reporting', 'policies' => ['PRV-16'], 'prompt_summary' => 'How do you file required public health reports?', 'why_we_ask' => 'Covers reportable diseases, child abuse reports, adverse events, school immunizations and employer notices.'],
                    ['title' => 'Abuse, neglect & domestic violence', 'policies' => ['PRV-17'], 'prompt_summary' => 'How do you handle reporting suspected abuse of an adult patient?', 'why_we_ask' => 'Staff need to know who authorizes a report and when the patient is told.'],
                    ['title' => 'Reproductive health requests', 'policies' => ['PRV-18'], 'prompt_summary' => 'How do you screen requests involving reproductive health care?', 'why_we_ask' => 'Some requests need a signed attestation before anything is released.'],
                    ['title' => 'Research requests', 'policies' => ['PRV-21'], 'prompt_summary' => 'How do you handle requests to use patient information for research?', 'why_we_ask' => 'Research needs an authorization or an approved waiver. Most practices rarely see these.'],
                    ['title' => 'Serious threats to safety', 'policies' => ['PRV-22'], 'prompt_summary' => 'What happens when a clinician believes a patient is a serious threat to someone?', 'why_we_ask' => 'Duty-to-warn situations need a clear decision-maker and careful documentation.'],
                    ['title' => 'Military, federal & correctional requests', 'policies' => ['PRV-23'], 'prompt_summary' => 'How do you handle requests from the military, federal officials or correctional facilities?', 'why_we_ask' => 'These requests are rare but need verified authority before release.'],
                    ['title' => "Workers' compensation", 'policies' => ['PRV-24'], 'prompt_summary' => 'How do you release records for a workers’ comp claim?', 'why_we_ask' => 'Covers what is released to employers, carriers and the state board for a work injury.'],
                    ['title' => 'De-identified & limited data', 'policies' => ['PRV-25', 'PRV-27'], 'prompt_summary' => 'How do you strip identifiers before sharing data for reports or analytics?', 'why_we_ask' => 'Covers fully de-identified data and limited data sets shared under a data use agreement.'],
                    ['title' => 'Selling data & genetic information', 'policies' => ['PRV-02', 'PRV-29'], 'prompt_summary' => 'How do you prevent selling patient data or misusing genetic information?', 'why_we_ask' => 'Payment in exchange for patient data is prohibited, and genetic information can’t be used for underwriting.'],
                    ['title' => 'Multiple covered functions', 'policies' => ['PRV-13'], 'prompt_summary' => 'Does your practice also act as a health plan or clearinghouse?', 'why_we_ask' => 'Only applies if you perform more than one HIPAA covered function. Most practices are providers only.'],
                ],
            ],
            'vendors_incidents' => [
                'label' => 'Vendors & incidents',
                'questions' => [
                    ['title' => 'Business Associate Agreements', 'policies' => ['PRV-06', 'PRV-12', 'SEC-30', 'SEC-32', 'SEC-35'], 'prompt_summary' => 'How do you make sure vendors sign a BAA before they get patient data?', 'why_we_ask' => 'Five policies across both HIPAA manuals cover vendor agreements, including cloud, file-sharing and email vendors. This one question fills all of them.'],
                    ['title' => 'Security incidents & breaches', 'policies' => ['SEC-10', 'PRV-37', 'PRV-38'], 'prompt_summary' => 'What happens when someone reports a phishing click, lost laptop or privacy breach?', 'why_we_ask' => 'One workflow covers incident response, the breach risk assessment, notification deadlines and fixing the cause.'],
                ],
            ],
            'security_people_access' => [
                'label' => 'Security: people & access',
                'questions' => [
                    ['title' => 'Security Officer role', 'policies' => ['SEC-01'], 'prompt_summary' => 'How does your Security Officer carry out the role?', 'why_we_ask' => 'HIPAA requires a named Security Officer with documented authority.'],
                    ['title' => 'Risk analysis & remediation', 'policies' => ['SEC-02', 'SEC-03'], 'prompt_summary' => 'How do you perform your Security Risk Analysis and fix what it finds?', 'why_we_ask' => 'The SRA is the most-cited gap in OCR enforcement. Finding risks isn’t enough; they have to be tracked to closure.'],
                    ['title' => 'Granting access & MFA', 'policies' => ['SEC-14', 'SEC-20', 'SEC-22'], 'prompt_summary' => 'How is a new user’s access approved and secured?', 'why_we_ask' => 'Covers access approval, unique user IDs and multi-factor authentication.'],
                    ['title' => 'When someone leaves', 'policies' => ['SEC-11', 'SEC-39'], 'prompt_summary' => 'What happens to access when a workforce member leaves?', 'why_we_ask' => 'Leftover accounts from former staff are a top audit finding.'],
                    ['title' => 'Job descriptions & access', 'policies' => ['SEC-12'], 'prompt_summary' => 'How do job descriptions line up with system access?', 'why_we_ask' => 'Covers how job descriptions relate to each role’s system access.'],
                    ['title' => 'Reviewing system activity', 'policies' => ['SEC-09', 'SEC-16', 'SEC-19', 'SEC-21'], 'prompt_summary' => 'How do you review who is accessing your systems?', 'why_we_ask' => 'Four security policies require reviewing logs and user lists. One answer covers them all.'],
                    ['title' => 'Passwords', 'policies' => ['SEC-17'], 'prompt_summary' => 'How are password rules enforced?', 'why_we_ask' => 'Your response becomes the practice-specific description in the policy.'],
                    ['title' => 'Phishing tests', 'policies' => ['SEC-18'], 'prompt_summary' => 'Do you run phishing simulations and awareness campaigns?', 'why_we_ask' => 'Phishing is the most common way practices get breached.'],
                ],
            ],
            'security_offices_devices' => [
                'label' => 'Security: offices & devices',
                'questions' => [
                    ['title' => 'Physical access to offices & servers', 'policies' => ['SEC-04', 'SEC-05', 'SEC-08'], 'prompt_summary' => 'How do you control physical access to areas with servers and workstations?', 'why_we_ask' => 'Covers locks, alarms, cameras and who may enter restricted areas at each location.'],
                    ['title' => 'Visitors & vendors on site', 'policies' => ['SEC-06'], 'prompt_summary' => 'How are visitors and repair technicians signed in and escorted?', 'why_we_ask' => 'Unescorted visitors near workstations are an easy path to patient data.'],
                    ['title' => 'Security repairs', 'policies' => ['SEC-07'], 'prompt_summary' => 'How are repairs to locks, alarms and cameras recorded?', 'why_we_ask' => 'HIPAA expects a record of changes to physical security components.'],
                    ['title' => 'Workstations', 'policies' => ['SEC-23', 'SEC-24'], 'prompt_summary' => 'How are workstations used and protected?', 'why_we_ask' => 'Covers screen placement, screen locks, software installs and physical protection.'],
                    ['title' => 'Devices & media', 'policies' => ['SEC-25'], 'prompt_summary' => 'How do you track devices with patient data and dispose of them safely?', 'why_we_ask' => 'Lost or improperly discarded devices are a frequent cause of breaches.'],
                    ['title' => 'Personal devices (BYOD)', 'policies' => ['SEC-38'], 'prompt_summary' => 'Can staff use personal phones, tablets or laptops for work?', 'why_we_ask' => 'Personal devices need approval, management and remote wipe.'],
                ],
            ],
            'security_systems_network' => [
                'label' => 'Security: systems & network',
                'questions' => [
                    ['title' => 'Antivirus & malware', 'policies' => ['SEC-27'], 'prompt_summary' => 'How are your systems protected from malware?', 'why_we_ask' => 'Tell us what’s installed so the policy names real products.'],
                    ['title' => 'Patching', 'policies' => ['SEC-34'], 'prompt_summary' => 'How do you keep systems patched?', 'why_we_ask' => 'Unpatched systems are a leading cause of ransomware.'],
                    ['title' => 'Encryption', 'policies' => ['SEC-31'], 'prompt_summary' => 'How is data encrypted on laptops, servers and backups?', 'why_we_ask' => 'Encrypted devices can turn a lost laptop from a reportable breach into a non-event.'],
                    ['title' => 'Firewall', 'policies' => ['SEC-33'], 'prompt_summary' => 'What firewall protects each location?', 'why_we_ask' => 'The policy lists the actual firewall and who controls it.'],
                    ['title' => 'Wi-Fi', 'policies' => ['SEC-36', 'SEC-37'], 'prompt_summary' => 'How is your wireless network set up and secured?', 'why_we_ask' => 'Covers access points, guest separation and changing the Wi-Fi key.'],
                    ['title' => 'Remote access & VPN', 'policies' => ['SEC-40', 'SEC-41'], 'prompt_summary' => 'How do staff connect to your systems from outside the office?', 'why_we_ask' => 'Covers VPN, two-factor login and requirements for home computers.'],
                    ['title' => 'Telehealth', 'policies' => ['SEC-42'], 'prompt_summary' => 'How do you deliver telehealth securely?', 'why_we_ask' => 'Telehealth platforms need a BAA, identity checks and a private setting.'],
                    ['title' => 'Backups', 'policies' => ['SEC-29'], 'prompt_summary' => 'How is your data backed up and restored?', 'why_we_ask' => 'Tested backups are what let a practice recover from ransomware.'],
                    ['title' => 'Disaster recovery & emergency access', 'policies' => ['SEC-28', 'SEC-45', 'SEC-46'], 'prompt_summary' => 'What’s your plan if systems or the office are unavailable?', 'why_we_ask' => 'Covers critical systems, recovery times, who declares a disaster and emergency facility access.'],
                ],
            ],
        ];

        $sectionOrder = 0;

        foreach ($sections as $key => $sectionData) {
            $section = IntakeSection::updateOrCreate(['key' => $key], [
                'label' => $sectionData['label'],
                'sort_order' => $sectionOrder++,
            ]);

            $questionOrder = 0;

            foreach ($sectionData['questions'] as $questionData) {
                $question = IntakeQuestion::updateOrCreate([
                    'intake_section_id' => $section->id,
                    'title' => $questionData['title'],
                ], [
                    'sort_order' => $questionOrder++,
                    'prompt_summary' => $questionData['prompt_summary'] ?? null,
                    'why_we_ask' => $questionData['why_we_ask'] ?? null,
                ]);

                $policyIds = CompliancePolicy::whereIn('code', $questionData['policies'])->pluck('id');
                $question->policies()->sync($policyIds);
            }
        }
    }
}
