<?php

namespace App\Models;

use Database\Factories\PracticeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'name', 'logo_path', 'address',
    'specialty', 'billable_providers_count',
    'is_profile_locked', 'locked_at',
    'compliance_officer_name', 'compliance_officer_phone', 'compliance_officer_email',
    'hipaa_privacy_officer_name', 'hipaa_privacy_officer_phone', 'hipaa_privacy_officer_email',
    'hipaa_security_officer_name', 'hipaa_security_officer_phone', 'hipaa_security_officer_email',
    'release_of_info_officer_name', 'release_of_info_officer_phone', 'release_of_info_officer_email',
    'it_vendor_name', 'compliance_hotline_number', 'compliance_hotline_email',
    'uses_ehcp_hotline', 'hotline_poster_count',
    'compliance_committee_members', 'compliance_governing_board_members',
    'legal_practice_name', 'dba_name', 'other_entities', 'main_phone', 'main_email', 'practice_locations',
    'it_mode', 'it_contact_name', 'it_contact_phone', 'it_contact_email',
    'committee_none', 'board_mode', 'dashboard_next_steps',
    'source_system', 'external_practice_id', 'phone', 'subscription_account_id', 'preferred_billing_option',
])]
class Practice extends Model
{
    /** @use HasFactory<PracticeFactory> */
    use HasFactory;

    public const SPECIALTIES = [
        'General Practice', 'Family Medicine', 'Internal Medicine', 'Pediatrics', 'Cardiology',
        'Orthopedics', 'Dermatology', 'OB/GYN', 'Behavioral Health', 'Gastroenterology',
        'Neurology', 'Oncology', 'Ophthalmology', 'Otolaryngology (ENT)', 'Pain Management',
        'Physical Therapy', 'Podiatry', 'Pulmonology', 'Radiology', 'Rheumatology', 'Urgent Care',
        'Urology', 'Multi-specialty', 'Other',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_profile_locked' => 'boolean',
            'locked_at' => 'datetime',
            'billable_providers_count' => 'integer',
            'uses_ehcp_hotline' => 'boolean',
            'hotline_poster_count' => 'integer',
            'compliance_committee_members' => 'array',
            'compliance_governing_board_members' => 'array',
            'practice_locations' => 'array',
            'committee_none' => 'boolean',
            'dashboard_next_steps' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function oshaLocations(): HasMany
    {
        return $this->hasMany(OshaLocation::class)->orderBy('sort_order');
    }

    protected static function booted(): void
    {
        static::updated(function (Practice $practice) {
            if (! $practice->getOriginal('is_profile_locked')) {
                return;
            }

            if (! $practice->wasChanged(['name', 'logo_path', 'address', 'specialty', 'billable_providers_count'])) {
                return;
            }

            $orderIds = $practice->user->orders()->pluck('id');

            GeneratedDocument::whereIn('order_id', $orderIds)
                ->where('is_stale', false)
                ->update(['is_stale' => true, 'stale_reason' => 'practice_profile_updated']);
        });
    }
}
