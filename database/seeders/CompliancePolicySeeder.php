<?php

namespace Database\Seeders;

use App\Models\CompliancePolicy;
use Illuminate\Database\Seeder;

/**
 * Seeds the 101 numbered policies (17 Compliance & Ethics, 38 HIPAA Privacy, 46 HIPAA Security)
 * from data/compliance_policies.json, which was extracted directly from the real manual templates
 * in storage/app/templates/ (title, full question, and "your response should cover" bullets are
 * ground truth from the templates themselves) plus page references transcribed from the client's
 * Workflow & Policy Mapping doc.
 */
class CompliancePolicySeeder extends Seeder
{
    public function run(): void
    {
        $policies = json_decode(file_get_contents(__DIR__.'/data/compliance_policies.json'), true);

        foreach ($policies as $policy) {
            CompliancePolicy::updateOrCreate(['code' => $policy['code']], [
                'manual' => $policy['manual'],
                'title' => $policy['title'],
                'page_reference' => $policy['page_reference'],
                'requirements' => [
                    'full_question' => $policy['full_question'],
                    'bullets' => $policy['requirements'],
                ],
            ]);
        }
    }
}
