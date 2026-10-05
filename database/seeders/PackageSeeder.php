<?php

namespace Database\Seeders;

use App\Models\Package;
use Illuminate\Database\Seeder;

class PackageSeeder extends Seeder
{
    public function run(): void
    {
        $packages = [
            [
                'slug' => 'essential',
                'name' => 'Essential Compliance',
                'tagline' => 'Everything you need to meet baseline compliance requirements.',
                'monthly_price' => 99.00,
                'annual_price' => 999.00,
                'billing_type' => 'annual',
                'description' => 'Listed trainings are general in nature. Harassment prevention (general) does not substitute for state-mandated training where subject- or frequency-specific training is required. Assigning and maintaining compliance officer responsibilities remains the practice\'s responsibility at this tier.',
                'features' => [
                    'Compliance & Ethics Program',
                    'HIPAA Privacy & Security',
                    'Trainings: Compliance & Ethics, HIPAA Privacy, HIPAA Security, Harassment prevention (general)¹',
                    'Exclusions Screening',
                    'Compliance Hotline',
                ],
                // Essential reviews the practice's own uploaded documents — it doesn't
                // generate any of the 3 policy-driven manuals at this price point, but it does
                // include the Exclusions Screening Report, same as every other tier.
                'included_document_types' => ['exclusions_screening_report'],
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'slug' => 'professional',
                'name' => 'Professional Compliance',
                'tagline' => 'Comprehensive compliance for growing practices.',
                'monthly_price' => 129.00,
                'annual_price' => 1299.00,
                'billing_type' => 'annual',
                'description' => 'Listed trainings are general in nature. Harassment prevention (general) does not substitute for state-mandated training where subject- or frequency-specific training is required. Compliance officer responsibilities remain the practice\'s responsibility at this tier.',
                'features' => [
                    'Manuals & Manual Updates',
                    'Safety Review',
                    'Quarterly Compliance Meeting',
                    'Employee Manual Updates',
                ],
                'included_document_types' => [
                    'compliance_ethics_manual',
                    'hipaa_privacy_policy',
                    'hipaa_security_manual',
                    'exclusions_screening_report',
                ],
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'slug' => 'advanced',
                'name' => 'Advanced Compliance',
                'tagline' => 'Full HIPAA and per-location OSHA coverage.',
                'monthly_price' => 169.00,
                'annual_price' => 1699.00,
                'billing_type' => 'annual',
                'description' => 'The Coding & Documentation Mini Audit is not conducted under attorney-client privilege. Identified overpayments must be reported and returned within 60 days under federal law, and we will recommend independent legal counsel where findings suggest material exposure. Compliance officer responsibilities remain the practice\'s, with our review and guidance.',
                'features' => [
                    'Coding & Documentation Mini Audit² (10 encounters/provider)',
                    'Security Risk Assessment (SRA)',
                    'Creation & Oversight of Compliance Department',
                    'Monthly Compliance Meeting',
                    'Employee Manual Creation',
                ],
                'included_document_types' => [
                    'compliance_ethics_manual',
                    'hipaa_privacy_policy',
                    'hipaa_security_manual',
                    'security_risk_assessment',
                    'coding_mini_audit_report',
                    'exclusions_screening_report',
                ],
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'slug' => 'complete',
                'name' => 'Complete Compliance',
                'tagline' => 'Fully custom compliance suite: contact us for a quote.',
                'monthly_price' => null,
                'annual_price' => null,
                'billing_type' => 'custom',
                'description' => 'Scope, deliverables, and pricing at this tier are customized per practice and confirmed in a separate written services agreement. Co-sourced, fractional, or outsourced compliance officer staffing is scoped individually and does not itself create an employment relationship with Empower.',
                'features' => [
                    'Customized Compliance Program',
                    'Compliance Officer Needs: Co-Sourced, Fractional, or Outsourced',
                ],
                'included_document_types' => [
                    'compliance_ethics_manual',
                    'hipaa_privacy_policy',
                    'hipaa_security_manual',
                    'exclusions_screening_report',
                ],
                'is_active' => true,
                'sort_order' => 4,
            ],
        ];

        foreach ($packages as $data) {
            Package::updateOrCreate(['slug' => $data['slug']], $data);
        }
    }
}
