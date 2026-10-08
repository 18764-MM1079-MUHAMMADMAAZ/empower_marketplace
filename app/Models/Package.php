<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\PackageTier;
use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'slug', 'name', 'tagline', 'monthly_price', 'annual_price', 'billing_type',
    'description', 'features', 'included_document_types', 'is_active', 'sort_order',
])]
class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monthly_price' => 'decimal:2',
            'annual_price' => 'decimal:2',
            'features' => 'array',
            'included_document_types' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function tier(): PackageTier
    {
        return PackageTier::from($this->slug);
    }

    public function isCustomQuote(): bool
    {
        return $this->tier()->isCustomQuote();
    }

    public function allowsQuestionnaireDownload(): bool
    {
        return $this->tier()->allowsQuestionnaireDownload();
    }

    public function includesWorkflowQuestionnaire(): bool
    {
        return $this->tier()->includesWorkflowQuestionnaire();
    }

    public function includesLmsAccess(): bool
    {
        return $this->tier()->includesLmsAccess();
    }

    public function includesAdvancedDocumentCategories(): bool
    {
        return $this->tier()->includesAdvancedDocumentCategories();
    }

    /** Null for Monthly means this package has no monthly price set — see hasMonthlyPricing(). */
    public function priceForCycle(BillingCycle $cycle): ?float
    {
        return $cycle === BillingCycle::Monthly
            ? ($this->monthly_price !== null ? (float) $this->monthly_price : null)
            : (float) $this->annual_price;
    }

    public function hasMonthlyPricing(): bool
    {
        return $this->monthly_price !== null;
    }
}
