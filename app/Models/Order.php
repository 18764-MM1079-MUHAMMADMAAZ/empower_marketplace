<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\IntakeSubmissionStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'user_id', 'external_practice_id', 'package_id', 'checkout_batch_id', 'status', 'payment_status', 'billing_cycle',
    'payment_reference', 'billing_address', 'amount_paid', 'paid_at', 'completed_at',
    'cancelled_at', 'notes', 'terms_accepted_at', 'terms_accepted_ip',
    'discount_code_id', 'discount_code', 'discount_percentage', 'original_price', 'discount_amount',
    'trial_ends_at', 'trial_confirmed_at', 'trial_reminder_sent_at', 'clover_card_token',
    'card_expiry_month', 'card_expiry_year', 'card_last_four', 'mtbc_reference_number',
    'next_bill_date', 'renewal_attempts', 'last_renewal_attempt_at', 'last_renewal_error',
    'finance_processed_at',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * Every payment_status value that means "checkout is complete, proceed to onboarding" — not
     * strictly "money was collected" (Trialing hasn't been charged yet, SimulatedPaid never
     * charges a real card either; both still grant full portal access like a real payment does).
     */
    public const PAID_STATUSES = [
        PaymentStatus::SimulatedPaid, PaymentStatus::Paid, PaymentStatus::Trialing, PaymentStatus::PastDue,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'billing_cycle' => BillingCycle::class,
            'billing_address' => 'array',
            'amount_paid' => 'decimal:2',
            'paid_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'finance_processed_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'original_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'discount_percentage' => 'integer',
            'trial_ends_at' => 'datetime',
            'trial_confirmed_at' => 'datetime',
            'trial_reminder_sent_at' => 'datetime',
            'card_expiry_month' => 'integer',
            'card_expiry_year' => 'integer',
            'next_bill_date' => 'date',
            'renewal_attempts' => 'integer',
            'last_renewal_attempt_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(DiscountCode::class);
    }

    public function intakeSubmission(): HasOne
    {
        return $this->hasOne(IntakeSubmission::class);
    }

    public function generatedDocuments(): HasMany
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function isPaid(): bool
    {
        return in_array($this->payment_status, self::PAID_STATUSES, true);
    }

    /**
     * How far through the Practice Intake wizard this order's submission has gotten, 0-100 —
     * used by the homepage's "Welcome back" resume banner. Mirrors (deliberately not shared
     * code with, to avoid coupling a domain model to a Livewire component's internal state)
     * practice-intake-wizard.blade.php's BASICS_SUB_SCREENS/TEAM_SUB_SCREENS/chapterProgress().
     * Once the wizard itself is done, the client's share of "intake" is 100% regardless of
     * what happens afterward (submit, review, approval).
     */
    public function intakePercentComplete(): int
    {
        $submission = $this->intakeSubmission;

        if (! $submission) {
            return 0;
        }

        if ($submission->status !== IntakeSubmissionStatus::Draft || $submission->wizard_screen === 'done') {
            return 100;
        }

        $reached = $submission->wizard_reached_screens ?? ['documents'];
        $basicsScreens = ['b_profile', 'b_providers', 'b_address', 'b_logo'];
        $teamScreens = ['t_practice', 't_officers', 't_it', 't_hotline', 't_leadership'];

        $totalItems = 1 + count($basicsScreens);
        $doneItems = (in_array('documents', $reached, true) ? 1 : 0) + count(array_intersect($basicsScreens, $reached));

        if ($this->package?->includesWorkflowQuestionnaire()) {
            $totalItems += count($teamScreens) + IntakeQuestion::count();
            $doneItems += count(array_intersect($teamScreens, $reached)) + $submission->intakeAnswers()->count();
        }

        return $totalItems > 0 ? (int) round($doneItems / $totalItems * 100) : 0;
    }

    /**
     * A cancelled order — a lapsed/rejected free trial, a subscription cancelled after repeated
     * failed renewals, or one an admin cancelled directly (e.g. a refund). AI document generation
     * (new intake submissions, regenerating a document) is blocked for these; documents already
     * generated/approved beforehand remain downloadable.
     */
    public function blockedFromAiGeneration(): bool
    {
        return $this->status === OrderStatus::Cancelled;
    }

    /**
     * Delete every file this order owns (generated documents, intake uploads) ahead of a
     * DB-level cascading delete, which removes the rows but never touches storage.
     */
    public function deleteCascadingFiles(): void
    {
        foreach ($this->generatedDocuments as $document) {
            foreach ([$document->pdf_storage_path, $document->docx_storage_path, $document->custom_storage_path] as $path) {
                if ($path) {
                    Storage::disk('local')->delete($path);
                }
            }
        }

        foreach ($this->intakeSubmission?->intakeUploads ?? [] as $upload) {
            if ($upload->storage_path) {
                Storage::disk('local')->delete($upload->storage_path);
            }
        }
    }
}
