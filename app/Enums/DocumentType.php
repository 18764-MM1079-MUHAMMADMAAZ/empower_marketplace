<?php

namespace App\Enums;

enum DocumentType: string
{
    // Retired 2026-08-24 — no longer auto-generated; there is no questionnaire in the
    // catalog that feeds these, so nothing can trigger their generation anymore. Kept as
    // valid backing values so historical GeneratedDocument rows remain readable.
    case EmployeeHandbookBasic = 'employee_handbook_basic';
    case EmployeeHandbookFull = 'employee_handbook_full';
    case OshaSafetyPlan = 'osha_safety_plan';
    case HrPolicyManual = 'hr_policy_manual';
    case OshaLocationReport = 'osha_location_report';
    case CustomComplianceDocument = 'custom_compliance_document';
    case RevenueCycleBillingManual = 'revenue_cycle_billing_manual';
    case EmergencyOperationsPlan = 'emergency_operations_plan';

    // Active — each one generates only when its matching questionnaire is uploaded,
    // regardless of package tier. See linkedQuestionnaireType()/forQuestionnaireType().
    case HipaaPrivacyPolicy = 'hipaa_privacy_policy';
    case ComplianceEthicsManual = 'compliance_ethics_manual';
    case HipaaBusinessAssociateManual = 'hipaa_business_associate_manual';
    case HipaaSecurityManual = 'hipaa_security_manual';

    // A client-uploaded existing document, AI-polished and generated 1:1 per upload rather
    // than merged into one of the fixed manual templates above.
    case PolishedClientDocument = 'polished_client_document';

    // Advanced tier only — synthesized by AI from the practice's own data (workflow answers or
    // an uploaded encounter list) rather than merged into a fixed template. See
    // isAiSynthesizedReport() and GenerateComplianceDocument::generateAiSynthesizedReport().
    case SecurityRiskAssessment = 'security_risk_assessment';
    case CodingMiniAuditReport = 'coding_mini_audit_report';

    public function label(): string
    {
        return match ($this) {
            self::EmployeeHandbookBasic => 'Employee Handbook (Basic)',
            self::EmployeeHandbookFull => 'Employee Handbook (Full)',
            self::OshaSafetyPlan => 'OSHA Safety Plan',
            self::HrPolicyManual => 'HR Policy Manual',
            self::HipaaPrivacyPolicy => 'HIPAA Privacy Policy',
            self::OshaLocationReport => 'OSHA Location Report',
            self::CustomComplianceDocument => 'Custom Compliance Document',
            self::RevenueCycleBillingManual => 'Revenue Cycle & Billing Compliance Manual',
            self::ComplianceEthicsManual => 'Compliance & Ethics Manual',
            self::EmergencyOperationsPlan => 'Emergency Operations Plan',
            self::HipaaBusinessAssociateManual => 'HIPAA Business Associate Manual',
            self::HipaaSecurityManual => 'HIPAA Security Manual',
            self::PolishedClientDocument => 'Reviewed & Polished Document',
            self::SecurityRiskAssessment => 'Security Risk Assessment (SRA)',
            self::CodingMiniAuditReport => 'Coding & Documentation Mini Audit Report',
        };
    }

    /** Whether this document's content is synthesized by AI from the practice's own data
     *  (workflow answers or an uploaded file) rather than merged into a fixed .docx template. */
    public function isAiSynthesizedReport(): bool
    {
        return match ($this) {
            self::SecurityRiskAssessment, self::CodingMiniAuditReport => true,
            default => false,
        };
    }

    /** Whether this document's deliverable is the merged .docx template itself, with no PDF conversion. */
    public function isDocxOnly(): bool
    {
        return match ($this) {
            self::RevenueCycleBillingManual,
            self::EmergencyOperationsPlan,
            self::HipaaBusinessAssociateManual,
            self::HipaaSecurityManual => true,
            default => false,
        };
    }

    /** The DocumentType whose manual is built from this questionnaire, if any. */
    public static function forQuestionnaireType(IntakeUploadType $uploadType): ?self
    {
        foreach (self::cases() as $documentType) {
            if ($documentType->linkedQuestionnaireType() === $uploadType) {
                return $documentType;
            }
        }

        return null;
    }

    /** Whether this document is generated once per OSHA location */
    public function isPerLocation(): bool
    {
        return $this === self::OshaLocationReport;
    }

    /** Whether this document is generated once per source upload, rather than once per order. */
    public function isPerUpload(): bool
    {
        return $this === self::PolishedClientDocument;
    }

    /** The client questionnaire this document's content is sourced from, if any. */
    public function linkedQuestionnaireType(): ?IntakeUploadType
    {
        return match ($this) {
            self::ComplianceEthicsManual => IntakeUploadType::ComplianceEthicsQuestionnaire,
            self::HipaaBusinessAssociateManual => IntakeUploadType::HipaaBusinessAssociateQuestionnaire,
            self::HipaaPrivacyPolicy => IntakeUploadType::HipaaPrivacyQuestionnaire,
            self::HipaaSecurityManual => IntakeUploadType::HipaaSecurityQuestionnaire,
            self::PolishedClientDocument => IntakeUploadType::ClientDocumentForReview,
            default => null,
        };
    }
}
