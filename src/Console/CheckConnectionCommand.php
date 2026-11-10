<?php

declare(strict_types=1);

namespace Beziworld\Proxmox\Console;

use Beziworld\Proxmox\Exceptions\ProxmoxException;
use Beziworld\Proxmox\ProxmoxManager;
use Illuminate\Console\Command;

/**
 * Verifies the credentials of a configured Proxmox VE cluster and lists its nodes.
 */
final class CheckConnectionCommand extends Command
{
    /** @var string */
    protected $signature = 'proxmox:check {connection? : Connection name from config/proxmox.php}';

    /** @var string */
    protected $description = 'Check the credentials of a Proxmox VE connection';

    public function handle(ProxmoxManager $manager): int
    {
        $argument = $this->input->getArgument('connection');
        $name = is_string($argument) && $argument !== '' ? $argument : $manager->getDefaultConnection();

        try {
            $client = $manager->connection($name);
            $version = $client->version();
            $nodes = $client->nodes()->list();
        } catch (ProxmoxException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $config = $client->config();

        $this->components->info(sprintf(
            'Connected to %s (Proxmox VE %s) with %s.',
            $config->url,
            $version->version,
            $config->usesToken() ? 'API token ' . $config->tokenId : 'ticket of ' . $config->username,
        ));

        foreach ($nodes as $node) {
            $this->components->twoColumnDetail($node->node, sprintf('%s, %d CPU, %.0f%% memory', $node->status, $node->maxCpu, $node->memoryUsage() * 100));
        }

        return self::SUCCESS;
    }
}
