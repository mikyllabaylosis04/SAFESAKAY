<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder): void {
            $organizationId = static::currentOrganizationId();

            if ($organizationId) {
                $builder->where(
                    $builder->getModel()->qualifyColumn('organization_id'),
                    $organizationId,
                );
            }
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('organization_id')) {
                return;
            }

            $organizationId = static::currentOrganizationId();

            if ($organizationId) {
                $model->setAttribute('organization_id', $organizationId);
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    private static function currentOrganizationId(): ?int
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        return $user->organization_id;
    }
}
