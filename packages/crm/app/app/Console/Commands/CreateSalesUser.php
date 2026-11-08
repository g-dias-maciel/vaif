<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesCrmPassword;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Creates the first VAIF sales login for the internal CRM, or repairs one.
 *
 * Like `crm:make-staff`, it never uses a default password. The sales role can
 * only reach `/sales/*`; it never sees the Artist-facing CRM.
 */
class CreateSalesUser extends Command
{
    use ResolvesCrmPassword;

    protected $signature = 'crm:make-sales
        {email : Login e-mail}
        {--name= : Display name (defaults to the e-mail local part)}
        {--password= : Password (avoid this on shared hosts; it lands in shell history)}
        {--reset-password : Overwrite an existing password via a hidden prompt}
        {--force : Change an existing artist/staff login to sales}';

    protected $description = 'Create or repair a VAIF sales login for the internal CRM';

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

        if ($existing && ! $existing->isSales() && ! $this->option('force')) {
            $kind = $existing->isArtist() ? 'artist' : 'staff';
            $this->error("{$email} is an existing {$kind} login.");
            $this->line('Re-run with --force to change it to sales and unlink any artist.');

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
            'role' => User::ROLE_SALES,
            'artist_id' => null,
        ];

        // An empty password means "keep the current one" (existing user only).
        if ($password !== '') {
            $attributes['password'] = $password;
        }

        $user = User::updateOrCreate(['email' => $email], $attributes);

        $this->info($existing ? 'Sales login updated.' : 'Sales login created.');
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
}
