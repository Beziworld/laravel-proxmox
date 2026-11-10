<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Console;

use Beziworld\Proxmox\Data\TaskStatus;
use Beziworld\Proxmox\Exceptions\ProxmoxException;
use Beziworld\Proxmox\ProxmoxManager;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Waits for a task (UPID) to finish and prints its exit status.
 */
final class WaitForTaskCommand extends Command
{
    /** @var string */
    protected $signature = 'proxmox:task
        {upid : Task UPID returned by an asynchronous call}
        {--timeout= : Seconds to wait (default from config/proxmox.php)}
        {--connection= : Connection name from config/proxmox.php}';

    /** @var string */
    protected $description = 'Wait for a Proxmox VE task and print its result';

    public function handle(ProxmoxManager $manager): int
    {
        $upid = $this->input->getArgument('upid');
        $timeout = $this->input->getOption('timeout');
        $connection = $this->input->getOption('connection');

        if (! is_string($upid)) {
            return self::INVALID;
        }

        try {
            $status = $manager->connection(is_string($connection) && $connection !== '' ? $connection : null)
                ->tasks()
                ->wait($upid, is_string($timeout) && is_numeric($timeout) ? (int) $timeout : null, throw: false, onPoll: function (TaskStatus $status): void {
                    $this->output->write('.');
                });
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::INVALID;
        } catch (ProxmoxException $exception) {
            $this->newLine();
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();

        if ($status->failed()) {
            $this->components->error(sprintf('%s failed: %s', $status->type, $status->exitStatus ?? 'unknown error'));

            return self::FAILURE;
        }

        $this->components->info(sprintf('%s finished: %s', $status->type, $status->exitStatus ?? 'OK'));

        return self::SUCCESS;
    }
}
