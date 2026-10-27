<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Bootstraps the CRM: creates the first VAIF staff login, or repairs/resets one.
 *
 * Deliberately not a seeder: `db:seed` creates the demo users with the password
 * "password", which must never reach production. This command never has a
 * default password.
 */
class CreateStaffUser extends Command
{
    protected $signature = 'crm:make-staff
        {email : Login e-mail}
        {--name= : Display name (defaults to the e-mail local part)}
        {--password= : Password (avoid this on shared hosts; it lands in shell history)}
        {--reset-password : Overwrite an existing password via a hidden prompt}
        {--force : Promote an existing artist user to staff}';

    protected $description = 'Create or repair a VAIF staff (admin) login for the CRM';

    private const MIN_PASSWORD_LENGTH = 8;

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid e-mail: {$email}");

            return self::FAILURE;
        }

        try {
            $existing = User::where('email', $email)->first();
        } catch (QueryException $e) {
            $this->error('Could not read the users table: '.$e->getMessage());
            $this->line('Did you run `php artisan migrate --force`? (RUN_MIGRATIONS=true does it on boot.)');

            return self::FAILURE;
        }

        if ($existing && $existing->isArtist() && ! $this->option('force')) {
            $this->error("{$email} is an artist login (linked to artist {$existing->artist_id}).");
            $this->line('Re-run with --force to promote it to staff and unlink the artist.');

            return self::FAILURE;
        }

        $password = $this->resolvePassword($existing);

        if ($password === null) {
            return self::FAILURE;
        }

        $name = $this->option('name')
            ?: $existing?->name
            ?: Str::headline(str_replace(['.', '_', '-'], ' ', Str::before($email, '@')));

        $attributes = [
            'name' => $name,
            'role' => User::ROLE_STAFF,
            'artist_id' => null,
        ];

        // An empty password means "keep the current one" (existing user only).
        if ($password !== '') {
            $attributes['password'] = $password;
        }

        $user = User::updateOrCreate(['email' => $email], $attributes);

        $this->info($existing ? 'Staff login updated.' : 'Staff login created.');
        $this->table(
            ['Field', 'Value'],
            [
                ['id', $user->id],
                ['email', $user->email],
                ['name', $user->name],
                ['role', $user->role],
                ['password', $password === '' ? '(unchanged)' : '(set)'],
            ],
        );

        $this->line('Sign in at '.rtrim((string) config('app.url'), '/').'/login');

        return self::SUCCESS;
    }

    /**
     * Resolve the password.
     *
     * Returns '' when an existing user keeps its current password, or null when
     * the input is unusable.
     */
    private function resolvePassword(?User $existing): ?string
    {
        $option = $this->option('password');

        if (is_string($option) && $option !== '') {
            if (! $this->passwordIsValid($option)) {
                $this->error('Password must be at least '.self::MIN_PASSWORD_LENGTH.' characters.');

                return null;
            }

            $this->warn('--password was passed on the command line: it may be kept in shell history.');

            return $option;
        }

        // Existing user, no explicit reset: leave the password alone. Never
        // silently reset a working login on a re-run.
        if ($existing && ! $this->option('reset-password')) {
            return '';
        }

        if (! $this->input->isInteractive()) {
            $this->error(
                $this->option('reset-password')
                    ? '--reset-password needs an interactive terminal; use --password instead.'
                    : 'No password given. Pass --password when running non-interactively.'
            );

            return null;
        }

        $password = (string) $this->secret('Password');

        if (! $this->passwordIsValid($password)) {
            $this->error('Password must be at least '.self::MIN_PASSWORD_LENGTH.' characters.');

            return null;
        }

        if ((string) $this->secret('Confirm password') !== $password) {
            $this->error('Passwords do not match.');

            return null;
        }

        return $password;
    }

    private function passwordIsValid(string $password): bool
    {
        // Hash::isHashed() guards against pasting an already-hashed value and
        // having the model's `hashed` cast store it as-is.
        return mb_strlen($password) >= self::MIN_PASSWORD_LENGTH && ! Hash::isHashed($password);
    }
}
