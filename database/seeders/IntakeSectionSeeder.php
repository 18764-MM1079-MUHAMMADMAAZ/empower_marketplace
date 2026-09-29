<?php

namespace Database\Seeders;

use App\Models\CompliancePolicy;
use App\Models\IntakeQuestion;
use App\Models\IntakeSection;
use Illuminate\Database\Seeder;

/**
 * Seeds the 8 workflow-question sections, the 66 questions, and their mapping to the 101
 * compliance_policies rows — all transcribed directly from the client's "Workflow & Policy
 * Mapping" doc's question-to-policy table. Must run after CompliancePolicySeeder.
 *
 * Only `title` (the question label shown in the wizard) is confirmed client content for every
 * row. `prompt_summary` (the one-line question shown under the title) and `why_we_ask` (the side
 * panel rationale) are only confirmed for questions 1 and 5, which were directly observed in the
 * client's interactive prototype — every other row is left null here and falls back to showing
 * just the title in the UI, rather than inventing compliance-adjacent copy the client hasn't
 * actually written. Fill these in via the admin panel once the client provides the real text.
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
                    ['title' => 'Compliance Officer duties', 'policies' => ['CMP-03']],
                    ['title' => 'Compliance Committee', 'policies' => ['CMP-04']],
                    ['title' => 'Staff sign-offs', 'policies' => ['CMP-05', 'SEC-26'], 'prompt_summary' => 'How do staff sign off on your code of conduct and acceptable-use rules?', 'why_we_ask' => 'Signed acknowledgments prove each person was told the rules, including the duty to report problems.'],
                    ['title' => 'Compliance & HIPAA training', 'policies' => ['CMP-07', 'PRV-36', 'SEC-43']],
                    ['title' => 'Reporting concerns', 'policies' => ['CMP-08', 'PRV-11', 'PRV-36']],
                    ['title' => 'Discipline & sanctions', 'policies' => ['CMP-15', 'SEC-13', 'PRV-36']],
                    ['title' => 'Investigations & overpayments', 'policies' => ['CMP-10']],
                    ['title' => 'Program & policy review', 'policies' => ['CMP-17', 'SEC-44']],
                ],
            ],
            'billing_audits' => [
                'label' => 'Billing & audits',
                'questions' => [
                    ['title' => 'Contracts & referral relationships', 'policies' => ['CMP-06']],
                    ['title' => 'Coding & billing audits', 'policies' => ['CMP-09']],
                    ['title' => 'Medical necessity', 'policies' => ['CMP-11']],
                    ['title' => 'Claims workflow', 'policies' => ['CMP-12']],
                    ['title' => 'Out-of-network plan patients', 'policies' => ['CMP-13']],
                    ['title' => 'Exclusion screening', 'policies' => ['CMP-14']],
                    ['title' => 'Government visits & requests', 'policies' => ['CMP-16', 'PRV-19']],
                ],
            ],
            'patient_privacy' => [
                'label' => 'Patient privacy',
                'questions' => [
                    ['title' => 'Notice of Privacy Practices', 'policies' => ['PRV-31', 'PRV-10']],
                    ['title' => 'Routine uses & disclosures', 'policies' => ['PRV-01']],
                    ['title' => 'Minimum necessary & role-based access', 'policies' => ['PRV-03', 'PRV-26', 'SEC-15']],
                    ['title' => 'Patient access to records', 'policies' => ['PRV-34']],
                    ['title' => 'Requests to amend records', 'policies' => ['PRV-35']],
                    ['title' => 'Accounting of disclosures', 'policies' => ['PRV-04']],
                    ['title' => 'Restriction requests', 'policies' => ['PRV-32']],
                    ['title' => 'Confidential communications', 'policies' => ['PRV-09', 'PRV-33']],
                    ['title' => "Verifying who's asking", 'policies' => ['PRV-30']],
                    ['title' => 'Patient authorizations', 'policies' => ['PRV-14']],
                    ['title' => 'Personal representatives', 'policies' => ['PRV-08']],
                    ['title' => 'Family & caregivers', 'policies' => ['PRV-15']],
                    ['title' => 'Fundraising', 'policies' => ['PRV-28']],
                ],
            ],
            'special_requests' => [
                'label' => 'Special requests',
                'questions' => [
                    ['title' => 'Deceased patients', 'policies' => ['PRV-05', 'PRV-07', 'PRV-20']],
                    ['title' => 'Public health reporting', 'policies' => ['PRV-16']],
                    ['title' => 'Abuse, neglect & domestic violence', 'policies' => ['PRV-17']],
                    ['title' => 'Reproductive health requests', 'policies' => ['PRV-18']],
                    ['title' => 'Research requests', 'policies' => ['PRV-21']],
                    ['title' => 'Serious threats to safety', 'policies' => ['PRV-22']],
                    ['title' => 'Military, federal & correctional requests', 'policies' => ['PRV-23']],
                    ['title' => "Workers' compensation", 'policies' => ['PRV-24']],
                    ['title' => 'De-identified & limited data', 'policies' => ['PRV-25', 'PRV-27']],
                    ['title' => 'Selling data & genetic information', 'policies' => ['PRV-02', 'PRV-29']],
                    ['title' => 'Multiple covered functions', 'policies' => ['PRV-13']],
                ],
            ],
            'vendors_incidents' => [
                'label' => 'Vendors & incidents',
                'questions' => [
                    ['title' => 'Business Associate Agreements', 'policies' => ['PRV-06', 'PRV-12', 'SEC-30', 'SEC-32', 'SEC-35']],
                    ['title' => 'Security incidents & breaches', 'policies' => ['SEC-10', 'PRV-37', 'PRV-38']],
                ],
            ],
            'security_people_access' => [
                'label' => 'Security: people & access',
                'questions' => [
                    ['title' => 'Security Officer role', 'policies' => ['SEC-01']],
                    ['title' => 'Risk analysis & remediation', 'policies' => ['SEC-02', 'SEC-03']],
                    ['title' => 'Granting access & MFA', 'policies' => ['SEC-14', 'SEC-20', 'SEC-22']],
                    ['title' => 'When someone leaves', 'policies' => ['SEC-11', 'SEC-39']],
                    ['title' => 'Job descriptions & access', 'policies' => ['SEC-12']],
                    ['title' => 'Reviewing system activity', 'policies' => ['SEC-09', 'SEC-16', 'SEC-19', 'SEC-21']],
                    ['title' => 'Passwords', 'policies' => ['SEC-17']],
                    ['title' => 'Phishing tests', 'policies' => ['SEC-18']],
                ],
            ],
            'security_offices_devices' => [
                'label' => 'Security: offices & devices',
                'questions' => [
                    ['title' => 'Physical access to offices & servers', 'policies' => ['SEC-04', 'SEC-05', 'SEC-08']],
                    ['title' => 'Visitors & vendors on site', 'policies' => ['SEC-06']],
                    ['title' => 'Security repairs', 'policies' => ['SEC-07']],
                    ['title' => 'Workstations', 'policies' => ['SEC-23', 'SEC-24']],
                    ['title' => 'Devices & media', 'policies' => ['SEC-25']],
                    ['title' => 'Personal devices (BYOD)', 'policies' => ['SEC-38']],
                ],
            ],
            'security_systems_network' => [
                'label' => 'Security: systems & network',
                'questions' => [
                    ['title' => 'Antivirus & malware', 'policies' => ['SEC-27']],
                    ['title' => 'Patching', 'policies' => ['SEC-34']],
                    ['title' => 'Encryption', 'policies' => ['SEC-31']],
                    ['title' => 'Firewall', 'policies' => ['SEC-33']],
                    ['title' => 'Wi-Fi', 'policies' => ['SEC-36', 'SEC-37']],
                    ['title' => 'Remote access & VPN', 'policies' => ['SEC-40', 'SEC-41']],
                    ['title' => 'Telehealth', 'policies' => ['SEC-42']],
                    ['title' => 'Backups', 'policies' => ['SEC-29']],
                    ['title' => 'Disaster recovery & emergency access', 'policies' => ['SEC-28', 'SEC-45', 'SEC-46']],
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
