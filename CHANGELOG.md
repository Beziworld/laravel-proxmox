# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Proxmox VE API client with API token or ticket authentication (cached ticket, CSRFPreventionToken, single renewal on expiry).
- QEMU VMs and LXC containers: status, start, stop, shutdown, reboot, reset, suspend, resume, configuration read and update, resize, clone, template conversion, migration, destruction, snapshots (list, create, rollback, delete), backups (vzdump and backup listing), guest agent interfaces, creation with the next free VMID.
- Guests addressed by VMID with node lookup in the cluster resources.
- Task handles for every UPID with a waiter (`wait()` with timeout, poll interval and progress callback), task status, log, listing and stopping.
- Nodes, cluster resources, next free VMID, cluster status, storages, storage content and volume deletion.
- Access control: users, API tokens (create, list, delete), effective permissions, roles, groups and ACL updates.
- Multiple named clusters, runtime credentials with `using()`, per-connection TLS verification setting.
- `proxmox:check` and `proxmox:task` console commands.
