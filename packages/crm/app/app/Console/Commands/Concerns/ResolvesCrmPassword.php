<?php

namespace App\Console\Commands\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Shared password handling for the `crm:make-*` bootstrap commands.
 *
 * The using command must define `private const MIN_PASSWORD_LENGTH = 8;`.
 */
trait ResolvesCrmPassword
{
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
