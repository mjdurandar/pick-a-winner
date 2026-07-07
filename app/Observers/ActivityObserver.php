<?php

namespace App\Observers;

use App\Models\ExportLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes an activity-log entry (into export_logs via ExportLog) whenever an audited
 * model is created, updated, or deleted. Attached to user-meaningful models only.
 *
 * @see \App\Providers\AppServiceProvider for the list of observed models.
 */
class ActivityObserver
{
    /** Dropped entirely from logged changes (noise / framework internals). */
    private const IGNORED_KEYS = ['updated_at', 'created_at', 'remember_token', 'email_verified_at'];

    /** Value redacted but the field name is still recorded. */
    private const SENSITIVE_KEYS = ['password', 'password_confirmation', 'token', '_token', 'api_key', 'secret'];

    public function created(Model $model): void
    {
        $this->log('create', $model, ['attributes' => $this->clean($model->getAttributes())]);
    }

    public function updated(Model $model): void
    {
        $changes = $this->clean($model->getChanges());
        // Skip framework-only updates (e.g. remember_token refreshed on login).
        if (empty($changes)) {
            return;
        }
        $this->log('update', $model, ['changes' => $changes]);
    }

    public function deleted(Model $model): void
    {
        $this->log('delete', $model, ['deleted' => $this->clean($model->getOriginal())]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function log(string $action, Model $model, array $extra): void
    {
        $type = class_basename($model);

        ExportLog::recordActivity($action, [
            'target_type' => $type,
            'target_id' => (string) $model->getKey(),
            'params' => array_merge([
                'model' => $type,
                'label' => $this->label($model),
            ], $extra),
        ]);
    }

    /**
     * Drop noise/timestamp keys and redact secret values.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function clean(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $key => $value) {
            if (in_array($key, self::IGNORED_KEYS, true)) {
                continue;
            }
            if (in_array($key, self::SENSITIVE_KEYS, true)) {
                $out[$key] = '[redacted]';

                continue;
            }
            if (is_scalar($value) || is_null($value) || is_array($value)) {
                $out[$key] = $value;
            } else {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * A human-friendly label for the record, from common name/title columns.
     */
    private function label(Model $model): ?string
    {
        foreach (['event_name', 'name', 'title', 'film_name', 'email'] as $field) {
            $value = $model->getAttribute($field);
            if (is_string($value) && trim($value) !== '') {
                return Str::limit(trim($value), 120);
            }
        }

        return null;
    }
}
