<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /**
     * Whether this account may reach the master sheet sync screen.
     *
     * Deliberately one named account rather than the admin role: the screen
     * holds a Google connection, applies batches to live locations and can
     * delete a location along with its attendees. Compared case-insensitively
     * because an email typed with different capitalisation is the same mailbox.
     */
    public function canManageMasterSheet(): bool
    {
        $owner = config('services.google.sheets.owner_email');

        return $owner !== null && strcasecmp((string) $this->email, (string) $owner) === 0;
    }

    /** The SMS scheduler is one person's tool (see services.mailchimp.anz.sms_owner_email). */
    public function canManageSms(): bool
    {
        $owner = config('services.mailchimp.anz.sms_owner_email');

        return $owner !== null && strcasecmp((string) $this->email, (string) $owner) === 0;
    }

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

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
