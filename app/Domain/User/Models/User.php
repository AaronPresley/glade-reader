<?php

namespace App\Domain\User\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Entry\Models\Entry;
use App\Domain\SourceReference\Models\SourceReference;
use App\Domain\User\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'username', 'email', 'password', 'share_token'])]
#[Hidden(['password', 'remember_token', 'share_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if (! $user->share_token) {
                $user->share_token = static::generateShareToken();
            }
        });
    }

    public static function generateShareToken(): string
    {
        do {
            $token = bin2hex(random_bytes(32));
        } while (static::query()->where('share_token', $token)->exists());

        return $token;
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * @return HasMany<SourceReference, $this>
     */
    public function sourceReferences(): HasMany
    {
        return $this->hasMany(SourceReference::class);
    }

    /**
     * @return HasMany<Entry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
