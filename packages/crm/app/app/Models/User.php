<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ARTIST = 'artist';

    public const ROLE_STAFF = 'staff';

    public const ROLE_SALES = 'sales';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'artist_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function isStaff(): bool
    {
        return $this->role === self::ROLE_STAFF;
    }

    public function isArtist(): bool
    {
        return $this->role === self::ROLE_ARTIST;
    }

    public function isSales(): bool
    {
        return $this->role === self::ROLE_SALES;
    }

    /**
     * Human-readable role label used by the Team screen.
     */
    public function roleLabel(): string
    {
        return match ($this->role) {
            self::ROLE_STAFF => 'Administrador',
            self::ROLE_SALES => 'Vendas',
            self::ROLE_ARTIST => 'Artista',
            default => ucfirst((string) $this->role),
        };
    }

    /**
     * Roles managed from the Team screen. Artist logins stay in the Artists
     * area because they are tied to an artist_id.
     *
     * @return array<string, string>
     */
    public static function internalRoleOptions(): array
    {
        return [
            self::ROLE_STAFF => 'Administrador',
            self::ROLE_SALES => 'Vendas',
        ];
    }

    public function isInternal(): bool
    {
        return in_array($this->role, [self::ROLE_STAFF, self::ROLE_SALES], true);
    }

    /**
     * Artist prospects owned by this sales user.
     */
    public function prospects(): HasMany
    {
        return $this->hasMany(Prospect::class, 'owner_id');
    }
}
