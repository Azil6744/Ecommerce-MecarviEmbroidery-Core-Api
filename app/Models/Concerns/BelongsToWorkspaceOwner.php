<?php

namespace App\Models\Concerns;

use App\Support\WorkspaceOwner;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scopes a model to the current notes/todos workspace (see App\Support\WorkspaceOwner):
 * admin requests only see owner_id NULL rows, personal requests only see their own rows,
 * and new rows are stamped with the current owner.
 */
trait BelongsToWorkspaceOwner
{
    public static function bootBelongsToWorkspaceOwner(): void
    {
        static::addGlobalScope('workspace_owner', function (Builder $query) {
            $column = $query->getModel()->qualifyColumn('owner_id');
            $ownerId = WorkspaceOwner::id();

            $ownerId === null
                ? $query->whereNull($column)
                : $query->where($column, $ownerId);
        });

        static::creating(function ($model) {
            $model->owner_id = WorkspaceOwner::id();
        });
    }
}
