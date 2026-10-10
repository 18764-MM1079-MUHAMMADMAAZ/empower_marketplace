<?php

namespace App\Services;

/** Normalizes MTBC's EmpowerSSOAPI login response into one simple result. */
class EmpowerSsoLoginResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $declineMessage = null,
        public readonly ?string $externalUserId = null,
        public readonly ?string $email = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $practiceName = null,
        public readonly ?string $practiceAddress = null,
        public readonly ?string $practiceCity = null,
        public readonly ?string $practiceState = null,
        public readonly ?string $practiceZip = null,
        public readonly ?string $practicePhone = null,
        public readonly bool $isPracticeAdmin = false,
        /** @var array<int, array{id: string, name: string, user_name?: string}> */
        public readonly array $practices = [],
        public readonly ?string $selectedPracticeId = null,
        /** @var array<int, array{user_name?: string, first_name?: string, last_name?: string, email?: string}> */
        public readonly array $practiceAdmins = [],
    ) {}
}
