<?php

namespace App\Enums;

/** Optional emails sent to every admin, which each admin can turn off in My Account ›
 *  Notifications. Turning one off stops only the email: the in-app notification bell still shows it. */
enum AdminEmail: string
{
    case Payments = 'admin_payments';
    case IntakeSubmissions = 'admin_intake_submissions';
    case SpecialistCalls = 'admin_specialist_calls';
    case NewLeads = 'admin_new_leads';
    case PlanChangeRequests = 'admin_plan_change_requests';

    public function label(): string
    {
        return match ($this) {
            self::Payments => 'Payments received',
            self::IntakeSubmissions => 'Intake submissions',
            self::SpecialistCalls => 'Specialist call requests',
            self::NewLeads => 'New leads',
            self::PlanChangeRequests => 'Plan change requests',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Payments => 'When a client pays for a package, a trial converts or a subscription renews.',
            self::IntakeSubmissions => 'When a client submits their intake for review.',
            self::SpecialistCalls => 'When a client asks for a call with a compliance specialist.',
            self::NewLeads => 'When someone sends the contact or quote form on the website.',
            self::PlanChangeRequests => 'When a client asks to change their provider count or billing period (My Account › Plan).',
        };
    }
}
