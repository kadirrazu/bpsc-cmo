<?php

namespace App\Console\Commands;

use App\Console\Questions\AdminChoiceQuestion;
use App\Services\Library\Audit;
use App\Services\Viva\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearVivaData extends Command
{
    protected $signature = 'cmo:viva-clear {scope? : pools, distributions, candidates, or all; omit for the interactive menu}';

    protected $description = 'Permanently clear selected Viva module data after interactive confirmation';

    public const MENU_QUESTION = 'What would you like to clear?';

    public const CONFIRMATION = 'Permanently delete the Viva data listed above?';

    public function handle(Workspace $workspace, Audit $audit): int
    {
        $scope = $this->argument('scope');
        if ($scope !== null && ! in_array($scope, ['pools', 'distributions', 'candidates', 'all'], true)) {
            $this->error('Invalid scope. Use pools, distributions, candidates, or all.');

            return self::INVALID;
        }
        if (! $this->input->isInteractive()) {
            $this->error('Interactive confirmation is required. Run this command in a terminal without --no-interaction.');

            return self::FAILURE;
        }
        if ($scope === null) {
            $choices = [
                'cancel' => 'Cancel (no changes)',
                'pools' => 'Member pools only',
                'distributions' => 'Board distributions only',
                'candidates' => 'Candidate imports and their board distributions',
                'all' => 'All Viva operational data',
            ];
            $selected = $this->output->askQuestion(new AdminChoiceQuestion(self::MENU_QUESTION, array_values($choices), 0));
            $scope = array_search($selected, $choices, true);
            if ($scope === 'cancel' || $scope === false) {
                $this->info('Cancelled. No data was deleted.');

                return self::SUCCESS;
            }
        }
        $tables = match ($scope) {
            'pools' => ['viva_pool_user', 'viva_pools'],
            'distributions' => ['viva_assignments'],
            'candidates' => ['viva_assignments', 'viva_candidates', 'viva_boards', 'viva_imports', 'viva_days'],
            'all' => ['viva_assignments', 'viva_pool_user', 'viva_pools', 'viva_candidates', 'viva_boards', 'viva_imports', 'viva_days'],
        };
        $this->warn('Permanent deletion across ALL dates. Back up your database before continuing.');
        $this->line('Database: '.DB::connection()->getDatabaseName());
        $this->line('Run while nobody is importing candidates, editing pools or distributing boards (maintenance mode is recommended).');
        if ($scope === 'pools') {
            $this->line('All pools, including previously removed pools, and their memberships will be deleted. Existing board assignments stay; their pool reference becomes empty.');
        }
        if (in_array($scope, ['candidates', 'all'], true)) {
            $this->warn('Deleting candidate imports also deletes imported board groups, ALL board assignments and ALL staged candidate-import previews.');
        }
        $this->line('Users, Expert Evaluation data, Viva settings/display credentials and existing audit logs are retained.');
        $this->table(['Table to clear', 'Current rows'], array_map(fn ($table) => [$table, DB::table($table)->count()], $tables));
        if (! $this->confirm(self::CONFIRMATION, false)) {
            $this->info('Cancelled. No data was deleted.');

            return self::SUCCESS;
        }
        $counts = DB::transaction(function () use ($tables, $workspace, $audit, $scope) {
            $dates = DB::table('viva_boards')->pluck('vdate')->merge(DB::table('viva_assignments')->pluck('vdate'))->unique()->sort()->values();
            foreach ($dates as $date) {
                $workspace->lockDay($date);
            }
            $counts = [];
            foreach ($tables as $table) {
                // DELETE respects foreign keys and transaction rollback on MySQL and SQLite.
                $counts[$table] = DB::table($table)->delete();
            }
            $audit->record('viva.data.cleared', ['scope' => $scope, 'deleted_rows' => $counts]);

            return $counts;
        }, 3);
        $this->table(['Cleared table', 'Deleted rows'], array_map(fn ($table, $count) => [$table, $count], array_keys($counts), array_values($counts)));
        $this->info('Viva data cleared successfully.');

        return self::SUCCESS;
    }
}
