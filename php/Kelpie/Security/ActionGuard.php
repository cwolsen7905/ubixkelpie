<?php

declare(strict_types=1);

namespace Kelpie\Security;

use Psr\Log\LoggerInterface as Logger;

/**
 * Deny by default: says whether an unattended run may run a command or a model tool
 *
 * A command is classified into one `UnattendedAction` by its exact shape, then checked against the list this
 * guard was built with. Programs, git subcommands and flags that are not known are refused, so a new capability
 * has to be added here on purpose.
 *
 * `composer install` is allowed only with `--no-scripts` and `--no-plugins`, and `make` only as exactly `make check` (a project's own gate). The gate still runs the repository's own code (plugins, tests,
 * analysers): see docs/security/context-scan.md.
 *
 * @see \Kelpie\Tests\Security\ActionGuardTest PHPUnit test case
 */
final class ActionGuard
{
    private const array GIT_READ = ['diff', 'show', 'ls-tree', 'cat-file', 'rev-parse', 'status', 'log'];

    private const array GIT_CHECKOUT = ['clone', 'fetch', 'checkout', 'add'];

    private const array GIT_CONFIG_KEYS = ['user.name', 'user.email', 'commit.gpgsign', 'safe.directory'];

    /**
     * Settings that only take a capability away, allowed with exactly these values: no fsmonitor program, no hooks
     */
    private const array GIT_SAFE_SETTINGS = ['core.fsmonitor=false', 'core.hooksPath=/dev/null'];

    private const array PUSH_FORBIDDEN = ['-f', '-d', '--delete', '--mirror', '--all', '--prune', '--tags'];

    private const array GATE_PROGRAMS = ['vendor/bin/phpcs', 'vendor/bin/phpstan', 'vendor/bin/phpunit'];

    /**
     * Options of clone, fetch and checkout that run a program or a hook, or reach beyond the one repository:
     * a custom upload/receive pack, a template (copies hooks), config, submodules, other object stores
     */
    private const array GIT_FETCH_FORBIDDEN = [
        '--config', '--exec', '--receive-pack', '--recurse-submodules', '--recursive', '--reference', '--reference-if-able',
        '--separate-git-dir', '--server-option', '--template', '--upload-pack', '--git-dir', '--work-tree', '--shallow-submodules',
    ];

    private const array AGENT_TOOLS = ['Read', 'Grep', 'Glob', 'Edit', 'Write'];

    /**
     * Constructor
     *
     * @param Logger              $logger  Logger
     * @param ?UnattendedAction[] $allowed The list; null for every action Kelpie's runs use
     */
    public function __construct(
        private readonly Logger $logger,
        private ?array $allowed = null,
    ) {
        $this->allowed ??= UnattendedAction::cases();
    }

    /**
     * Whether an action is on the list
     *
     * @param UnattendedAction $action The action
     *
     * @return bool
     */
    public function permits(UnattendedAction $action): bool
    {
        return in_array($action, $this->allowed ?? [], true);
    }

    /**
     * Whether a model's tool call is on the list
     *
     * @param string $tool The tool's name as the model called it
     *
     * @return bool
     */
    public function permitsTool(string $tool): bool
    {
        return match ($tool) {
            'read', 'grep', 'glob' => $this->permits(UnattendedAction::ReadRepo),
            default                => false,
        };
    }

    /**
     * Why a command is refused, or null when it may run
     *
     * @param string[] $argv The program and its arguments
     *
     * @return ?string
     */
    public function refusal(array $argv): ?string
    {
        $action = $this->classify($argv);
        $this->logger->debug('Unattended action checked', ['program' => basename($argv[0] ?? ''), 'action' => $action?->value]);
        if ($action === null) {
            return 'not a known action: ' . basename($argv[0] ?? '');
        }

        return $this->permits($action) ? null : 'action not on the list: ' . $action->value;
    }

    /**
     * The action a command is, by its exact shape
     *
     * @param string[] $argv The program and its arguments
     *
     * @return ?UnattendedAction Null for anything unknown or malformed
     */
    private function classify(array $argv): ?UnattendedAction
    {
        $program = $argv[0] ?? '';

        return match (true) {
            basename($program) === 'git'                        => $this->classifyGit(array_slice($argv, 1)),
            in_array($program, self::GATE_PROGRAMS, true)       => UnattendedAction::RunGate,
            $program === 'composer'                             => ($argv[1] ?? '') === 'install' && in_array('--no-scripts', $argv, true) && in_array('--no-plugins', $argv, true) ? UnattendedAction::RunGate : null,
            $program === 'make'                                 => $argv === ['make', 'check'] ? UnattendedAction::RunGate : null,
            basename($program) === 'claude'                     => $this->classifyAgent($argv),
            $program === 'rm'                                   => $this->classifyRemove($argv),
            default                                             => null,
        };
    }

    /**
     * @param string[] $args The git arguments
     *
     * @return ?UnattendedAction
     */
    private function classifyGit(array $args): ?UnattendedAction
    {
        // Leading global options: -c key=value (a few keys, or a setting that only removes a capability), -C path.
        while ($args !== [] && str_starts_with($args[0], '-')) {
            if ($args[0] === '-c' && isset($args[1]) && (in_array(explode('=', $args[1], 2)[0], self::GIT_CONFIG_KEYS, true) || in_array($args[1], self::GIT_SAFE_SETTINGS, true))) {
                $args = array_slice($args, 2);
            } elseif ($args[0] === '-C' && isset($args[1])) {
                $args = array_slice($args, 2);
            } else {
                return null;
            }
        }

        $sub  = $args[0] ?? '';
        $rest = array_slice($args, 1);

        return match (true) {
            in_array($sub, self::GIT_READ, true)     => UnattendedAction::GitRead,
            in_array($sub, self::GIT_CHECKOUT, true) => $this->checkoutIsPlain($sub, $rest) ? UnattendedAction::GitCheckout : null,
            $sub === 'commit'                        => in_array('--amend', $rest, true) ? null : UnattendedAction::GitCommit,
            $sub === 'push'                          => $this->pushIsPlain($rest) ? UnattendedAction::GitPush : null,
            default                                  => null,
        };
    }

    /**
     * A clone, fetch, checkout or add runs no program and no hook of its own choosing
     *
     * Refuses the options that name a program or copy hooks (git also takes an unambiguous abbreviation of a long
     * option, so `--templ` counts), `clone`/`fetch` `-c` and `-u`, any `::` or `file:` transport, and any URL that is
     * not https. One exception: `clone --separate-git-dir <path>` with the path under the temp directory, which is how
     * Kelpie's checkout keeps the repository outside the work tree.
     *
     * @param string   $sub  The subcommand
     * @param string[] $args The arguments after it
     *
     * @return bool
     */
    private function checkoutIsPlain(string $sub, array $args): bool
    {
        $options = true;
        $args    = array_values($args);
        $count   = count($args);
        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];
            if ($arg === '--') {
                $options = false;

                continue;
            }
            if ($options && $sub === 'clone' && $arg === '--separate-git-dir' && $this->isUnderTemp($args[$i + 1] ?? '')) {
                $i++;

                continue;
            }
            if ($options && str_starts_with($arg, '--')) {
                $name = explode('=', $arg, 2)[0];
                foreach (self::GIT_FETCH_FORBIDDEN as $forbidden) {
                    if (strlen($name) > 2 && str_starts_with($forbidden, $name)) {
                        return false;
                    }
                }
            } elseif ($options && ($sub === 'clone' || $sub === 'fetch') && preg_match('/^-[cu]/', $arg) === 1) {
                return false;
            }
            if (str_contains($arg, '::') || preg_match('~^(?:file|ext|fd):~i', $arg) === 1 || (str_contains($arg, '://') && !str_starts_with(strtolower($arg), 'https://'))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a path is strictly inside the temp directory, with no way back out of it
     *
     * @param string $path The path
     *
     * @return bool
     */
    private function isUnderTemp(string $path): bool
    {
        $tmp = rtrim(sys_get_temp_dir(), '/') . '/';

        return str_starts_with($path, $tmp) && strlen($path) > strlen($tmp) && !str_contains($path, '..') && !str_contains($path, "\0");
    }

    /**
     * A push has no force flag, no delete and no `+` refspec
     *
     * @param string[] $args The arguments after `push`
     *
     * @return bool
     */
    private function pushIsPlain(array $args): bool
    {
        foreach ($args as $arg) {
            if (in_array($arg, self::PUSH_FORBIDDEN, true) || str_starts_with($arg, '--force') || str_starts_with($arg, '+') || str_contains($arg, ':+') || str_starts_with($arg, ':')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The claude CLI only with the file tools, and without any switch that skips its own permission checks
     *
     * @param string[] $argv The command
     *
     * @return ?UnattendedAction
     */
    private function classifyAgent(array $argv): ?UnattendedAction
    {
        $tools = null;
        foreach ($argv as $i => $arg) {
            if (str_starts_with($arg, '--dangerously') || str_starts_with($arg, '--permission-mode') || $arg === '--allow-dangerously-skip-permissions') {
                return null;
            }
            if ($arg === '--allowedTools') {
                $tools = explode(',', $argv[$i + 1] ?? '');
            }
        }

        return $tools !== null && array_diff($tools, self::AGENT_TOOLS) === [] ? UnattendedAction::EditFiles : null;
    }

    /**
     * Only `rm -rf -- <a path under the temp directory>`, the checkout's own clean-up
     *
     * @param string[] $argv The command
     *
     * @return ?UnattendedAction
     */
    private function classifyRemove(array $argv): ?UnattendedAction
    {
        return array_slice($argv, 0, 3) === ['rm', '-rf', '--'] && count($argv) === 4 && $this->isUnderTemp($argv[3] ?? '') ? UnattendedAction::CleanCheckout : null;
    }
}
