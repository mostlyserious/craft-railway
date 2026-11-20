<?php

declare(strict_types=1);

namespace mostlyserious\railway;

use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\App;
use craft\helpers\Console;
use craft\services\ProjectConfig;
use Throwable;
use yii\console\ExitCode;

/**
 * Runs `craft up` safely from a Railway pre-deploy command.
 */
class RailwayController extends Controller
{
    /**
     * The lock Craft's own `up` command takes, so manual runs are excluded too.
     */
    private const LOCK = 'isolated-command:up/index';

    /**
     * Waits for any other `craft up` to finish, refuses to apply project config
     * older than what the database already has, runs `craft up` in a fresh
     * process, then verifies nothing is left pending.
     */
    public function actionPreDeploy(): int
    {
        if (!Craft::$app->getIsInstalled()) {
            // Craft has already printed the cause: no database connection, or no info row.
            return $this->fail("Craft isn't installed or its database can't be read. Install Craft before deploying with this command.");
        }

        $exitCode = $this->acquireLock();

        if ($exitCode !== ExitCode::OK) {
            return $exitCode;
        }

        $problem = $this->releaseConfigProblem() ?? $this->releaseOrderProblem();
        $exitCode = $problem !== null
            ? $this->fail($problem)
            : $this->runCraft(['up', '--interactive=0', '--isolated=0']);

        if ($exitCode === ExitCode::OK) {
            // Make verify read project config from the database, not a cached copy.
            Craft::$app->getCache()?->delete(ProjectConfig::STORED_CACHE_KEY);
            $exitCode = $this->runCraft(['railway/verify', '--interactive=0']);
        }

        try {
            if (Craft::$app->getMutex()->release(self::LOCK)) {
                return $exitCode;
            }
        } catch (Throwable) {
            // A failed release means the lock was lost.
        }

        return $this->fail('The craft up lock was lost before this deploy finished, so another craft up may have overlapped it.');
    }

    /**
     * Exits non-zero if migrations or project config changes are still pending.
     */
    public function actionVerify(): int
    {
        $problem = $this->releaseConfigProblem();

        if ($problem !== null) {
            return $this->fail($problem);
        }

        $pending = [];

        if ($this->migrationsPending()) {
            $pending[] = 'migrations';
        }

        if (Craft::$app->getProjectConfig()->areChangesPending(null, true)) {
            $pending[] = 'project config changes';
        }

        if ($pending !== []) {
            return $this->fail('craft up finished, but ' . implode(' and ', $pending) . ' are still pending.');
        }

        $this->stdout("Migrations and project config are up to date.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function acquireLock(): int
    {
        $mutex = Craft::$app->getMutex();
        $timeout = App::env('CRAFT_RAILWAY_LOCK_TIMEOUT') ?? 300;

        if (!is_int($timeout) || $timeout < 0) {
            return $this->fail('CRAFT_RAILWAY_LOCK_TIMEOUT must be a whole number of seconds, 0 or more.');
        }

        if ($mutex->acquire(self::LOCK)) {
            return ExitCode::OK;
        }

        if ($timeout > 0) {
            $this->stdout("Another craft up is running. Waiting up to {$timeout}s for it to finish…\n");

            if ($mutex->acquire(self::LOCK, $timeout)) {
                return ExitCode::OK;
            }
        }

        $this->stderr("Another craft up still held the lock after {$timeout}s. Giving up.\n", Console::FG_RED);

        return ExitCode::TEMPFAIL;
    }

    /**
     * Why Craft wouldn't apply this release's YAML, if it wouldn't.
     */
    private function releaseConfigProblem(): ?string
    {
        $projectConfig = Craft::$app->getProjectConfig();

        if (!$projectConfig->getDoesExternalConfigExist()) {
            return 'This release has no project config (config/project/project.yaml).';
        }

        if ($projectConfig->getHadFileWriteIssues()) {
            return "Craft recorded a failed project config write, so it would read the database's config instead of "
                . "this release's YAML. Fix the file permissions, then run `php craft clear-caches/data`.";
        }

        return null;
    }

    /**
     * Why this release shouldn't be applied: a `dateModified` can't be read, or
     * the database already has newer project config. The comparison is skipped
     * when admin changes are allowed (the database can legitimately be ahead) or
     * CRAFT_RAILWAY_FORCE is set.
     */
    private function releaseOrderProblem(): ?string
    {
        // Read straight from the database: this process loaded its project config before it waited for the lock.
        $applied = (new Query())
            ->select('value')
            ->from(Table::PROJECTCONFIG)
            ->where(['path' => 'dateModified'])
            ->scalar();
        $incoming = Craft::$app->getProjectConfig()->get('dateModified', true);

        // Craft stores an integer `dateModified` as bare digits.
        if (
            !is_string($applied)
            || !ctype_digit($applied)
            || (string)(int)$applied !== $applied
            || !is_int($incoming)
            || $incoming < 0
        ) {
            return 'Could not read project config dateModified from the database or this release, so the release order is unknown.';
        }

        if (Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            return null;
        }

        if (App::parseBooleanEnv('$CRAFT_RAILWAY_FORCE')) {
            $this->stdout("CRAFT_RAILWAY_FORCE is set; skipping the release order check.\n", Console::FG_YELLOW);

            return null;
        }

        if ((int)$applied <= $incoming) {
            return null;
        }

        return "This release's project config (dateModified {$incoming}) is older than the database's "
            . "(dateModified {$applied}), so it was not applied.\n"
            . "If a newer release is deploying, no action is needed. If this release is intentional (a revert or "
            . "rollback), run `php craft project-config/touch` and commit, or set CRAFT_RAILWAY_FORCE=1 and redeploy.";
    }

    /**
     * Whether Craft, plugin, or content migrations are pending, including new
     * migration files on sites whose schema versions already match.
     */
    private function migrationsPending(): bool
    {
        if (Craft::$app->getUpdates()->getAreMigrationsPending(true)) {
            return true;
        }

        foreach ([Craft::$app, ...Craft::$app->getPlugins()->getAllPlugins()] as $module) {
            if ($module->getMigrator()->getNewMigrations() !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Runs a Craft console command in a fresh process, streaming its output.
     *
     * @param list<string> $args
     */
    private function runCraft(array $args): int
    {
        $process = proc_open(
            [PHP_BINARY, $this->request->getScriptFile(), ...$args],
            [['file', '/dev/null', 'r'], STDOUT, STDERR],
            $pipes,
        );

        if ($process === false) {
            return $this->fail('Could not start `craft ' . implode(' ', $args) . '`.');
        }

        $exitCode = proc_close($process);

        return $exitCode === 0 ? ExitCode::OK : max(1, $exitCode);
    }

    private function fail(string $message): int
    {
        $this->stderr("{$message}\n", Console::FG_RED);

        return ExitCode::UNSPECIFIED_ERROR;
    }
}
