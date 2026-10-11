<?php

namespace App\Policies;

use App\Enums\FeatureEnum;
use App\Models\AlertSetting;
use App\Models\User;
use Laravel\Pennant\Feature;

class AlertSettingPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, AlertSetting $model): bool
    {
        return Feature::for($user)->active(FeatureEnum::ALERT_SETTINGS->slug()) &&
            ($user->id === $model->alertable_id && $model->alertable_type === 'user');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return Feature::for($user)->active(FeatureEnum::ALERT_SETTINGS->slug());
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, AlertSetting $model): bool
    {
        return Feature::for($user)->active(FeatureEnum::ALERT_SETTINGS->slug()) &&
            ($user->id === $model->alertable_id && $model->alertable_type === 'user');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AlertSetting $model): bool
    {
        return Feature::for($user)->active(FeatureEnum::ALERT_SETTINGS->slug()) &&
            ($user->id === $model->alertable_id && $model->alertable_type === 'user');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, AlertSetting $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, AlertSetting $model): bool
    {
        return false;
    }
}
