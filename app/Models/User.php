<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Mail\ResetPasswordMail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;

#[Fillable(['name', 'email', 'password', 'role', 'is_active', 'moodle_user_id', 'external_id', 'is_practice_admin'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'is_practice_admin' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function sendPasswordResetNotification($token): void
    {
        try {
            Mail::to($this->email)->send(new ResetPasswordMail($this, $token));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** The Empower LMS (Moodle) username, keyed on the Marketplace user id rather than the email
     *  so the two accounts stay linked through an email change. Also sent as the OpenID Connect
     *  preferred_username claim, which the Moodle plugin binds to this username. */
    public function lmsUsername(): string
    {
        return 'mp_'.$this->id;
    }

    /** Whether the client owns a live (paid, not cancelled) order for a package that includes the
     *  Empower LMS — gates the "Access LMS" button and the OpenID Connect sign-in to Moodle. */
    public function hasLmsAccess(): bool
    {
        if ($this->isAdmin() || ! $this->is_active) {
            return false;
        }

        return $this->orders()
            ->whereIn('payment_status', Order::PAID_STATUSES)
            ->where('status', '!=', OrderStatus::Cancelled)
            ->whereNull('cancelled_at')
            ->with('package')
            ->get()
            ->contains(fn (Order $order) => $order->package?->includesLmsAccess());
    }

    public function practice(): HasOne
    {
        return $this->hasOne(Practice::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->latest();
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class)->latest();
    }
}
