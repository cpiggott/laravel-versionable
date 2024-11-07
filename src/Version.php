<?php

namespace Overtrue\LaravelVersionable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

use function class_uses;
use function config;
use function in_array;
use function tap;

/**
 * @property Model|\Overtrue\LaravelVersionable\Versionable $versionable
 * @property array $contents
 * @property int $id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Version extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    /**
     * @var array
     */
    protected $casts = [
        'contents' => 'json',
    ];

    protected $with = [
        'versionable',
    ];

    public function getIncrementing()
    {
        return \config('versionable.uuid') ? false : parent::getIncrementing();
    }

    public function getKeyType()
    {
        return \config('versionable.uuid') ? 'string' : parent::getKeyType();
    }

    protected static function booted()
    {
        static::creating(function (Version $version) {
            if (\config('versionable.uuid')) {
                $version->{$version->getKeyName()} = $version->{$version->getKeyName()} ?: (string) Str::orderedUuid();
            }
        });
    }

    public function user(): ?BelongsTo
    {
        $useSoftDeletes = in_array(SoftDeletes::class, class_uses(config('versionable.user_model')));

        return tap(
            $this->belongsTo(
                config('versionable.user_model'),
                config('versionable.user_foreign_key')
            ),
            fn ($relation) => $useSoftDeletes ? $relation->withTrashed() : $relation
        );
    }

    public function versionable(): MorphTo
    {
        return $this->morphTo('versionable');
    }

    /**
     * @param  string|\DateTimeInterface|null  $time
     *
     * @throws \Carbon\Exceptions\InvalidFormatException
     */
    public static function createForModel(Model $model, array $replacements = [], $time = null): Version
    {
        /* @var \Overtrue\LaravelVersionable\Versionable|Model $model */
        $versionClass = $model->getVersionModel();
        $versionConnection = $model->getConnectionName();
        $userForeignKeyName = $model->getUserForeignKeyName();

        $version = new $versionClass;
        $version->setConnection($versionConnection);

        $version->versionable_id = $model->getKey();
        $version->versionable_type = $model->getMorphClass();
        $version->{$userForeignKeyName} = $model->getVersionUserId();
        $version->contents = $model->getVersionableAttributes($model->getVersionStrategy(), $replacements);

        if ($time) {
            $version->created_at = Carbon::parse($time);
        }

        $version->save();

        return $version;
    }

    public function revert(): bool
    {
        return $this->revertWithoutSaving()->save();
    }

    public function revertWithoutSaving(): ?Model
    {
        $original = $this->versionable->getRawOriginal();

        switch ($this->versionable->getVersionStrategy()) {
            case VersionStrategy::DIFF:
                // v1 + ... + vN
                $versionsBeforeThis = $this->previousVersions()->orderOldestFirst()->get();
                foreach ($versionsBeforeThis as $version) {
                    if (! empty($version->contents)) {
                        $this->versionable->setRawAttributes(array_merge($original, $version->contents));
                    }
                }
                break;
            case VersionStrategy::SNAPSHOT:
                // v1 + vN
                /** @var \Overtrue\LaravelVersionable\Version $initVersion */
                $initVersion = $this->versionable->versions()->first();
                if (! empty($initVersion->contents)) {
                    $this->versionable->setRawAttributes(array_merge($original, $initVersion->contents));
                }
        }

        if (! empty($this->contents)) {
            $this->versionable->setRawAttributes(array_merge($original, $this->contents));
        }

        return $this->versionable;
    }

    public function scopeOrderOldestFirst(Builder $query): Builder
    {
        return $query->oldest()->oldest('id');
    }

    public function scopeOrderLatestFirst(Builder $query): Builder
    {
        return $query->latest()->latest('id');
    }

    public function previousVersions(): MorphMany
    {
        return $this->versionable->latestVersions()
            ->where(function ($query) {
                $query->where('created_at', '<', $this->created_at)
                    ->orWhere(function ($query) {
                        $query->where('id', '<', $this->getKey())
                            ->where('created_at', '<=', $this->created_at);
                    });
            });
    }

    public function previousVersion(): ?static
    {
        return $this->previousVersions()->orderLatestFirst()->first();
    }

    public function nextVersion(): ?static
    {
        return $this->versionable->versions()
            ->where(function ($query) {
                $query->where('created_at', '>', $this->created_at)
                    ->orWhere(function ($query) {
                        $query->where('id', '>', $this->getKey())
                            ->where('created_at', '>=', $this->created_at);
                    });
            })
            ->orderOldestFirst()
            ->first();
    }

    public function isLatest(): bool
    {
        return $this->getKey() === $this->versionable->latestVersion()->getKey();
    }

    public function diff(?Version $toVersion = null, array $differOptions = [], array $renderOptions = []): Diff
    {
        if (! $toVersion) {
            $toVersion = $this->previousVersion() ?? new static;
        }

        return new Diff($this, $toVersion, $differOptions, $renderOptions);
    }
}
