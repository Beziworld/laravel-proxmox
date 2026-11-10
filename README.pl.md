# Laravel Proxmox VE

[![tests](https://github.com/beziworld/laravel-proxmox/actions/workflows/tests.yml/badge.svg)](https://github.com/beziworld/laravel-proxmox/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/beziworld/laravel-proxmox.svg)](https://packagist.org/packages/beziworld/laravel-proxmox)
[![License](https://img.shields.io/packagist/l/beziworld/laravel-proxmox.svg)](LICENSE.md)

Klient Laravel dla API [Proxmox VE](https://www.proxmox.com/en/proxmox-virtual-environment): węzły, maszyny
wirtualne QEMU i kontenery LXC (cykl życia, konfiguracja, klonowanie, snapshoty, kopie zapasowe, migracja),
magazyny danych, zadania asynchroniczne z oczekiwaniem na UPID, zasoby klastra i kontrola dostępu, dla jednego lub
wielu klastrów.

[English documentation](README.md)

## Funkcje

- Tokeny API (`PVEAPIToken=user@realm!id=secret`) albo bilety (ticket) logowania hasłem z
  `CSRFPreventionToken`, trzymane w cache i odnawiane automatycznie
- Wiele nazwanych klastrów oraz `using([...])` dla klastrów zapisanych w bazie danych
- Weryfikacja TLS domyślnie włączona, `verify_ssl` per połączenie: true, false albo ścieżka do pakietu CA
  (`/etc/pve/pve-root-ca.pem`)
- Gości wskazuje się numerem VMID: `Proxmox::vm(100)` sam znajduje węzeł w zasobach klastra
- Każde wywołanie asynchroniczne zwraca `Task` (UPID) z metodami `wait()`, `status()`, `log()` i `stop()`; błąd
  zadania zgłasza `TaskFailed` z ostatnimi liniami logu, zbyt długie zadanie `TaskTimeout`
- Typowane DTO (`Node`, `ClusterResource`, `GuestStatus`, `Snapshot`, `TaskStatus`, `Storage`, `StorageVolume`,
  `ApiToken`, `User`) z zachowanym surowym payloadem
- Odczyty ponawiane przy błędach połączenia, HTTP 5xx oraz 595/596, zapisy nigdy; błędy walidacji dostępne per
  parametr
- `php artisan proxmox:check` i `php artisan proxmox:task {upid}`
- PHP 8.3+, Laravel 12 i 13, PHPStan poziom 8, działa z `Http::fake()` i `Sleep::fake()`

## Dlaczego ten pakiet

Na Packagist (listopad 2026) `corsinvest/cv4pve-api-php` ma ok. 1,37 mln pobrań: kompletny, generowany klient,
niezależny od frameworka, oparty na curl. Pakiety dla Laravela są nieliczne: `irabbi360/laravel-php-proxmox`
(ok. 4,6 tys. pobrań) zawsze wyłącza weryfikację TLS, obsługuje tylko bilety logowania hasłem dla jednego serwera i
nie obsługuje zadań; `h1g/proxmox` (160 pobrań) to powiązanie dla Laravela 5 z 2016 roku. Ten pakiet dodaje:

- uwierzytelnianie tokenem API i cache biletów współdzielony przez workery kolejek
- wiele klastrów, każdy z własnymi danymi logowania, węzłem i ustawieniami TLS
- oczekiwanie na zadania (UPID), dzięki czemu procesy provisioningu (klonowanie, konfiguracja, start) pisze się
  od góry do dołu
- klienta HTTP Laravela (testowalnego przez `Http::fake()`), typowane wyjątki i DTO

## Instalacja

```bash
composer require beziworld/laravel-proxmox
php artisan vendor:publish --tag=proxmox-config   # opcjonalnie
```

```dotenv
PROXMOX_URL=https://pve1.example.com:8006
PROXMOX_TOKEN_ID=automation@pve!laravel          # Datacenter > Permissions > API Tokens
PROXMOX_TOKEN_SECRET=b3c6a8e2-....
PROXMOX_NODE=pve1                                # domyślny węzeł dla tworzenia i list
PROXMOX_VERIFY_SSL=/etc/ssl/pve-root-ca.pem      # true, false albo ścieżka do pakietu CA

# albo logowanie biletem zamiast tokenu:
# PROXMOX_USERNAME=root@pam
# PROXMOX_PASSWORD=
```

```bash
php artisan proxmox:check
```

Tokeny API z separacją uprawnień mają tylko uprawnienia nadane samemu tokenowi: rolę (np. `PVEVMAdmin` na `/vms` i
`PVEDatastoreUser` na `/storage`) trzeba przypisać tokenowi, a nie wyłącznie jego użytkownikowi.

Kolejne klastry dodaje się w `config/proxmox.php`:

```php
'connections' => [
    'default' => [...],
    'dc2' => [
        'url' => 'https://dc2-pve1.example.com:8006',
        'token_id' => env('PROXMOX_DC2_TOKEN_ID'),
        'token_secret' => env('PROXMOX_DC2_TOKEN_SECRET'),
        'node' => 'dc2-pve1',
        'verify_ssl' => storage_path('certs/dc2-pve-root-ca.pem'),
    ],
],
```

## Użycie

### Nowa maszyna z szablonu

```php
use Beziworld\Proxmox\Facades\Proxmox;

$vmid = Proxmox::cluster()->nextId();

Proxmox::vm(9000)->clone($vmid, ['name' => 'shop-42', 'full' => true, 'storage' => 'local-lvm'])->wait();

$vm = Proxmox::vm($vmid);
$vm->updateConfig(['memory' => 4096, 'cores' => 2, 'ipconfig0' => 'ip=10.0.0.42/24,gw=10.0.0.1'])?->wait();
$vm->resize('scsi0', '+20G')?->wait();
$vm->start()->wait();
```

### Cykl życia

```php
$vm = Proxmox::vm(100);                      // węzeł z /cluster/resources
$vm = Proxmox::vm(100, 'pve1');              // albo podany wprost
$ct = Proxmox::container(200);
$guest = Proxmox::guest(100);                // typ i węzeł wykrywane automatycznie

$vm->status()->isRunning();
$vm->start(); $vm->shutdown(timeout: 120, forceStop: true); $vm->stop();
$vm->reboot(); $vm->reset(); $vm->suspend(); $vm->resume();
$vm->config(); $vm->config(current: true);
$vm->migrate('pve2', online: true)->wait(timeout: 3600);
$vm->convertToTemplate();
$vm->destroy(purge: true);
```

### Snapshoty i kopie zapasowe

```php
$vm->snapshot('before-upgrade', 'Aktualizacja PHP 8.4', withRam: false)->wait();
$vm->snapshots();                              // list<Snapshot>
$vm->rollback('before-upgrade', start: true)->wait();
$vm->deleteSnapshot('before-upgrade');

$vm->backup('pbs', mode: 'snapshot', compress: 'zstd', options: ['notes-template' => '{{guestname}}'])->wait();
$vm->backups('pbs');                           // list<StorageVolume>
Proxmox::storage('pve1')->deleteVolume('pbs', $backup->volid);
```

### Zadania (UPID)

```php
$task = $vm->start();                          // Task
$upid = (string) $task;                        // można zapisać, np. w zadaniu kolejki

$status = Proxmox::task($upid)->wait(timeout: 600, interval: 2, onPoll: fn($s) => logger()->info($s->status));
$status->succeeded(); $status->exitStatus;     // "OK", "WARNINGS: 1" albo komunikat błędu

Proxmox::task($upid)->log();                   // list<string>
Proxmox::tasks()->forNode('pve1', ['vmid' => 100, 'errors' => true]);
Proxmox::tasks()->recent();                    // zadania całego klastra
```

```bash
php artisan proxmox:task "UPID:pve1:000A1B2C:0123ABCD:6730A1B2:qmstart:100:root@pam:" --timeout=300
```

### Węzły, klaster i magazyny

```php
Proxmox::nodes()->list();                      // list<Node>
Proxmox::nodes()->status('pve1');
Proxmox::cluster()->resources('vm');           // list<ClusterResource>
Proxmox::cluster()->guests();
Proxmox::cluster()->isIdFree(150);
Proxmox::qemu('pve1')->list();                 // list<GuestStatus>
Proxmox::lxc('pve1')->create([
    'hostname' => 'ct1',
    'ostemplate' => 'local:vztmpl/debian-12-standard_12.7-1_amd64.tar.zst',
    'rootfs' => 'local-lvm:8',
    'net0' => 'name=eth0,bridge=vmbr0,ip=dhcp',
])->wait();
Proxmox::storage('pve1')->list('backup');      // list<Storage>
Proxmox::storage('pve1')->content('local', 'iso');
```

### Kontrola dostępu

```php
$token = Proxmox::access()->createToken('ci@pve', 'deploy', comment: 'CI', expiresAt: now()->addYear());
$token->value;                                 // widoczny tylko raz, trzeba go zapisać
Proxmox::access()->updateAcl('/vms/100', ['PVEVMUser'], tokens: [$token->fullTokenId]);
Proxmox::access()->can('/vms/100', 'VM.PowerMgmt');
Proxmox::access()->users();
```

### Klastry podawane w locie i pozostałe endpointy

```php
Proxmox::using([
    'url' => $cluster->url,
    'token_id' => $cluster->token_id,
    'token_secret' => decrypt($cluster->token_secret),
])->vm(100)->status();

Proxmox::request('GET', '/cluster/ha/resources');
Proxmox::request('POST', '/nodes/pve1/qemu/100/agent/ping');
```

## Wyjątki

Wszystkie wyjątki dziedziczą po `Beziworld\Proxmox\Exceptions\ProxmoxException`:

| Wyjątek | Kiedy |
|---|---|
| `AuthenticationException` | Odrzucony token, bilet albo logowanie (HTTP 401). Odrzucone logowanie nigdy nie jest ponawiane. |
| `PermissionDenied` | Brak uprawnienia (HTTP 403) |
| `ApiException` | Inny status błędu; `httpStatus()`, `errors()` (per parametr), `payload()` |
| `RateLimitException` | HTTP 429 z proxy przed klastrem; `retryAfter()` |
| `ConnectionFailed` | Błąd DNS, TCP, TLS albo przekroczenie czasu (po ponowieniach odczytu) |
| `TaskFailed` | `wait()` otrzymało status inny niż OK; `status()` i ostatnie linie logu |
| `TaskTimeout` | `wait()` przestało czekać; zadanie działa dalej (`upid()`) |
| `GuestNotFound` | VMID nie występuje w zasobach klastra (albo token go nie widzi) |
| `InvalidConfiguration` | Nieznane połączenie, brak adresu, danych logowania albo węzła |

## Uwagi

- Wywołania są synchroniczne, a zadania takie jak klonowanie, backup i migracja trwają minuty: uruchamiaj je w
  zadaniach kolejki i zapisuj UPID albo używaj `wait()` z limitem czasu dopasowanym do zadania.
- `updateConfig()` dla maszyn używa asynchronicznego endpointu POST (potrzebnego przy hotplug i alokacji dysków) i
  zwraca zadanie tylko wtedy, gdy Proxmox je uruchomił.
- Logowanie biletem nie obsługuje uwierzytelniania dwuskładnikowego: użyj tokenu API.

## Rozwój

```bash
composer install
composer ci   # pint --test, phpstan (poziom 8), pest
```

## Autor

Pakiet tworzy i utrzymuje **Kamil Ćwiertnia**, [beziworld.eu](https://beziworld.eu).

- E-mail: [biuro@beziworld.eu](mailto:biuro@beziworld.eu)
- Zgłoszenia i propozycje: [GitHub issues](https://github.com/beziworld/laravel-proxmox/issues)
- Podatności bezpieczeństwa: zobacz [SECURITY.md](SECURITY.md)

Potrzebujesz integracji? Skontaktuj się przez [beziworld.eu](https://beziworld.eu).

### Inne pakiety autora

- [beziworld/laravel-directadmin](https://github.com/beziworld/laravel-directadmin): panel hostingowy DirectAdmin
- [beziworld/laravel-plesk](https://github.com/beziworld/laravel-plesk): panel hostingowy Plesk
- [beziworld/laravel-unifi](https://github.com/beziworld/laravel-unifi): UniFi Network

## Licencja

MIT. Zobacz [LICENSE.md](LICENSE.md). Pakiet nie jest powiązany z Proxmox Server Solutions GmbH, ani przez nią
wspierany czy sponsorowany. Proxmox jest zastrzeżonym znakiem towarowym Proxmox Server Solutions GmbH.
