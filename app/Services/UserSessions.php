<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The devices a user is signed in on, read from the database session store (SESSION_DRIVER=database)
 * — My Account › Sign-in & security. With any other session driver there's nothing to list, so
 * {@see available()} is false and the page says so instead.
 */
class UserSessions
{
    public function __construct(private User $user) {}

    public static function for(User $user): self
    {
        return new self($user);
    }

    public static function available(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * Newest first, with this device on top.
     *
     * @return Collection<int, array{id: string, browser: string, platform: string, mobile: bool, ip: ?string, sign_in_method: ?string, last_active: Carbon, current: bool}>
     */
    public function all(): Collection
    {
        if (! self::available()) {
            return collect();
        }

        $currentId = session()->getId();

        return $this->query()
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity', 'payload'])
            ->map(fn (object $row) => [
                'id' => $row->id,
                ...self::describe($row->user_agent),
                'ip' => $row->ip_address,
                'sign_in_method' => $row->id === $currentId ? session('sign_in_method') : self::signInMethod($row->payload),
                'last_active' => Carbon::createFromTimestamp($row->last_activity),
                'current' => $row->id === $currentId,
            ])
            ->sortByDesc('current')
            ->values();
    }

    /**
     * How a stored session signed in ('password', or the partner's name such as 'talkEHR'), saved by
     * the login form as `sign_in_method`. Null for older sessions or when it can't be read.
     */
    private static function signInMethod(?string $payload): ?string
    {
        if (blank($payload)) {
            return null;
        }

        try {
            $raw = base64_decode($payload, true);

            if ($raw !== false && config('session.encrypt')) {
                $raw = app('encrypter')->decrypt($raw, false);
            }

            $data = $raw === false ? false : @unserialize($raw, ['allowed_classes' => false]);
        } catch (\Throwable) {
            return null;
        }

        return is_array($data) && is_string($data['sign_in_method'] ?? null) ? $data['sign_in_method'] : null;
    }

    /** "Email & password", "via talkEHR SSO"… for a sign_in_method value. */
    public static function signInMethodLabel(?string $method): ?string
    {
        return match (true) {
            $method === null => null,
            $method === 'password' => 'Email & password',
            default => "via {$method} SSO",
        };
    }

    /** Signs out one of the user's other devices. Returns false for this device or an unknown id. */
    public function signOut(string $sessionId): bool
    {
        if (! self::available() || $sessionId === session()->getId()) {
            return false;
        }

        $deleted = $this->query()->where('id', $sessionId)->delete() > 0;

        if ($deleted) {
            $this->forgetRememberedDevices();
        }

        return $deleted;
    }

    /** Signs out every device except this one — after a password change, or on request. */
    public function signOutOthers(): int
    {
        $deleted = self::available()
            ? $this->query()->where('id', '!=', session()->getId())->delete()
            : 0;

        $this->forgetRememberedDevices();

        return $deleted;
    }

    private function query(): Builder
    {
        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $this->user->getAuthIdentifier());
    }

    /**
     * A "Remember me" cookie would sign a signed-out device straight back in, so give the user a new
     * remember token (which invalidates every such cookie), then re-issue this device's cookie if it
     * had one so the current browser stays remembered.
     */
    private function forgetRememberedDevices(): void
    {
        $guard = Auth::guard('web');
        $remembered = request()->cookies->has($guard->getRecallerName());

        $this->user->setRememberToken(Str::random(60));
        $this->user->save();

        if ($remembered && $guard->id() === $this->user->getAuthIdentifier()) {
            $guard->login($this->user, remember: true);
        }
    }

    /**
     * A friendly browser and system name from a user agent string (no external library — just the
     * common cases; anything else reads "Unknown browser").
     *
     * @return array{browser: string, platform: string, mobile: bool}
     */
    public static function describe(?string $userAgent): array
    {
        $ua = (string) $userAgent;

        $browser = match (true) {
            str_contains($ua, 'Edg/') || str_contains($ua, 'EdgA/') || str_contains($ua, 'EdgiOS/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser/') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') || str_contains($ua, 'FxiOS/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };

        $platform = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Unknown system',
        };

        return [
            'browser' => $browser,
            'platform' => $platform,
            'mobile' => in_array($platform, ['iPhone', 'Android'], true) || str_contains($ua, 'Mobile'),
        ];
    }
}
