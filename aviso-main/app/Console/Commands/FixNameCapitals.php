<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PersonName;
use Illuminate\Console\Command;

/**
 * One-time fix for names saved before sign-up tidied them: names typed all
 * lowercase or ALL CAPS become Title Case (see PersonName::normalize).
 * Lists every change first; saves only after confirmation.
 */
class FixNameCapitals extends Command
{
    protected $signature = 'aviso:fix-name-capitals {--force : Save without asking for confirmation}';

    protected $description = 'Put saved first/middle/last names that are all lowercase or all caps into proper capitals';

    private const FIELDS = ['first_name', 'middle_name', 'last_name'];

    public function handle(): int
    {
        $changes = [];

        foreach (User::query()->get(['id', 'username', ...self::FIELDS]) as $user) {
            $fixed = [];
            foreach (self::FIELDS as $field) {
                $normalized = PersonName::normalize($user->{$field});
                if ($normalized !== $user->{$field}) {
                    $fixed[$field] = $normalized;
                }
            }

            if ($fixed) {
                $changes[] = [$user, $fixed];
            }
        }

        if (!$changes) {
            $this->info('All names already have proper capitals. Nothing to change.');

            return self::SUCCESS;
        }

        $this->table(['Username', 'Before', 'After'], array_map(fn (array $change) => [
            $change[0]->username,
            $this->fullName($change[0]->only(self::FIELDS)),
            $this->fullName(array_merge($change[0]->only(self::FIELDS), $change[1])),
        ], $changes));

        if (!$this->option('force') && !$this->confirm('Save these '.count($changes).' name changes?')) {
            $this->info('Cancelled. Nothing was changed.');

            return self::SUCCESS;
        }

        foreach ($changes as [$user, $fixed]) {
            $user->forceFill($fixed)->save();
        }

        $this->info('Updated '.count($changes).' user(s).');

        return self::SUCCESS;
    }

    private function fullName(array $parts): string
    {
        return trim(implode(' ', array_filter($parts, fn ($part) => $part !== null && $part !== '')));
    }
}
