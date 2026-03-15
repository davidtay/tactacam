# Tactacam_Order

Magento 2 module that prevents an ERP connector from reading the customer's
current address-book entry instead of the address that was captured on the order
at placement time.

---

## Table of contents

1. [Problem statement](#1-problem-statement)
2. [Root cause](#2-root-cause)
3. [Solution overview](#3-solution-overview)
4. [Key design decision – why `afterGetData`, not `afterGetCustomer`](#4-key-design-decision)
5. [Plugin reference](#5-plugin-reference)
6. [Module configuration](#6-module-configuration)
7. [File structure](#7-file-structure)
8. [Diagnostic script – `OrderTest.php`](#8-diagnostic-script)
9. [Unit tests](#9-unit-tests)
10. [Debugging with Xdebug](#10-debugging-with-xdebug)

---

## 1. Problem statement

Customer Service reports that the shipping address the ERP system received at
order-import time sometimes differs from the address currently stored on the
order in Magento. There is no record in the Magento admin logs of anyone editing
the order address, and no cron job updates orders after they are sent to the ERP.

---

## 2. Root cause

The ERP connector was likely written in the Magento 1 style:

```php
// Bad – loads the customer's live address book, not the order snapshot
$address = $order->getCustomer()->getDefaultShippingAddress();
```

`sales_order_address` is a **point-in-time snapshot** taken when the order is
placed. It never changes unless an admin explicitly edits the order. The customer's
`customer_address_entity` row, however, can be updated at any time from My Account.

When a customer edits their address after placing an order the two records
diverge. Because the change happens entirely in the customer account — outside
the order workflow — no order-change log entry is created and no cron is needed
to explain the discrepancy.

The correct address to send to the ERP is always the one on `sales_order_address`.

---

## 3. Solution overview

Two Magento 2 plugins intercept every code path that could supply the wrong
address and override the customer's in-memory default shipping address with the
order snapshot **without writing to the database**.

| Plugin method | Intercepts | Covers |
|---|---|---|
| `afterGetData` on `Magento\Sales\Model\Order` | `$order->getCustomer()` (resolved via `getData('customer')`) | Any code that loads an `Order` model directly and calls `getCustomer()` |
| `afterGet` on `Magento\Sales\Api\OrderRepositoryInterface` | `$orderRepository->get($id)` | REST API (`GET /V1/orders/:id`), service-contract consumers |

In both cases the override is **in-memory only** — `$customerShippingAddress->save()`
is never called, so the customer's address book is never modified.

---

## 4. Key design decision

### Why `afterGetData`, not `afterGetCustomer`

`getCustomer()` is declared only as a `@method` docblock on
`Magento\Sales\Model\Order` (line 74). It is **not** a real PHP method. At
runtime it resolves through `AbstractModel::__call()` → `getData('customer')`.

Magento's interceptor generator reads the class via PHP Reflection and only
generates wrappers for **real declared methods**. An `afterGetCustomer` plugin
would therefore be silently ignored — the method wrapper would never appear in
the generated Interceptor.

`getData()` **is** a real declared method on `\Magento\Framework\DataObject`.
Its wrapper is always generated. `OrderPlugin::afterGetData()` guards on
`$key === 'customer'` so the overhead applies only to that one key.

```php
// What the ERP connector calls:
$order->getCustomer()
// ↓ resolves to:
$order->getData('customer')   // ← afterGetData fires here
```

You can verify the Interceptor was compiled correctly:

```bash
grep "function getData" generated/code/Magento/Sales/Model/Order/Interceptor.php
# Expected output:  public function getData($key = '', $index = null)
```

---

## 5. Plugin reference

### `afterGetData(Order $subject, $result, $key, $index)`

Registered on: `Magento\Sales\Model\Order`

**Flow:**

```
$key !== 'customer' or $result === null  →  return unchanged
Module disabled (isSetFlag)              →  return unchanged
No shipping address on order             →  return unchanged (virtual/downloadable)
Shipping address has no entity ID        →  return unchanged
Customer has no default shipping address →  log notice, return unchanged
Happy path                               →  override 9 fields in-memory, return customer
```

### `afterGet(OrderRepositoryInterface $subject, $result, $id)`

Registered on: `Magento\Sales\Api\OrderRepositoryInterface`

**Flow:**

```
Guest order (customerId === 0)           →  return unchanged
Module disabled (isSetFlag)              →  return unchanged
No shipping address on order             →  return unchanged
Customer not found in DB                 →  return unchanged
Customer has no default shipping address →  log notice, attach customer, return order
Happy path                               →  override 9 fields in-memory,
                                            attach customer via setData('customer'),
                                            return order
Any exception                            →  log error, return order (never breaks load)
```

**Address fields overridden (in-memory only):**
`firstname`, `lastname`, `street`, `city`, `region_id`, `region`,
`postcode`, `country_id`, `telephone`

---

## 6. Module configuration

**Stores → Configuration → Tactacam → Order Customizations → General Configuration**

| Field | Path | Default | Scope |
|---|---|---|---|
| Enabled | `tactacam_order_config/general/enabled` | Yes | Default / Website |

Setting **Enabled = No** disables both plugins. The enabled check uses
`ScopeInterface::SCOPE_STORE` and passes the order's `store_id` so that
per-website or per-store overrides are respected.

After changing the setting flush the config cache:

```bash
docker compose run --rm deploy php /app/bin/magento cache:clean config
```

---

## 7. File structure

```
app/code/Tactacam/Order/
├── Console/
│   └── Command/
│       └── DiagnoseOrder.php    # bin/magento tactacam:order:diagnose
├── Plugin/
│   └── OrderPlugin.php          # Both plugin methods live here
├── Test/
│   └── Unit/
│       └── Plugin/
│           └── OrderPluginTest.php   # 15 PHPUnit tests, no bootstrap required
├── etc/
│   ├── adminhtml/
│   │   └── system.xml           # Admin config field (Enabled toggle)
│   ├── config.xml               # Default value: enabled = 1
│   ├── di.xml                   # Plugin + CLI command registrations
│   └── module.xml
├── composer.json
├── registration.php
└── README.md
```

---

## 8. Diagnostic command

`bin/magento tactacam:order:diagnose` is a Magento CLI command that investigates
the address discrepancy for a specific order. It lives inside the module at
`Console/Command/DiagnoseOrder.php`, so it is available to every developer and
deploy environment without any extra setup.

**Usage:**

```bash
docker compose run --rm deploy \
    php /app/bin/magento tactacam:order:diagnose --increment-id=000000001
```

**Why a CLI command instead of a standalone script?**

| | Standalone `php OrderTest.php` | `bin/magento tactacam:order:diagnose` |
|---|---|---|
| Lives in the module | ✘ root of the project | ✔ `Console/Command/` |
| Discoverable | ✘ must know the filename | ✔ `bin/magento list tactacam` |
| Argument validation | ✘ edit the file | ✔ `--increment-id` option with help text |
| Coloured output | ✘ plain `echo` | ✔ Symfony Console tags (`<info>`, `<error>`) |
| Xdebug support | ✔ | ✔ (same `XDEBUG_SESSION` approach) |
| Magento DI | ✘ ObjectManager directly | ✔ constructor injection |

**What it prints:**

| Section | Content |
|---|---|
| 1 | Order header (status, created, updated) |
| 2 | Shipping address stored on `sales_order_address` (the snapshot) |
| 3 | Customer record + current default shipping address from `customer_address_entity` |
| 3 (diff) | Field-by-field comparison — highlights any mismatches |
| 4 | Full `sales_order_status_history` log |
| 5-A | Interceptor existence check — confirms `getData` wrapper is present |
| 5-B | `afterGet` live test via `OrderRepositoryInterface::get()` |
| 5-C | `afterGetData` live test via `$order->getCustomer()` |

> **Note:** `OrderTest.php` in the Magento root is a deprecated shim that prints
> the new command and exits. It can be deleted once all team members have updated
> their workflows.

---

## 9. Unit tests

Tests live in `Test/Unit/Plugin/OrderPluginTest.php`. They use PHPUnit mocks
only — no Magento bootstrap, no database.

**Run all 15 tests:**

```bash
docker compose run --rm deploy \
    php /app/vendor/bin/phpunit \
        --configuration /app/dev/tests/unit/phpunit.xml.dist \
        --filter OrderPluginTest \
        --testdox
```

**Test coverage:**

| Group | Tests |
|---|---|
| `afterGetData` – early exits | non-customer key, null customer, module disabled, virtual order, address has no ID |
| `afterGetData` – notice path | customer has no default shipping address |
| `afterGetData` – happy path | all 9 setters called with snapshot values |
| `afterGet` – early exits | guest order, module disabled, no shipping address, address has no ID, customer not found |
| `afterGet` – notice path | customer has no default shipping address (notice + customer still attached) |
| `afterGet` – happy path | all 9 setters called + `setData('customer', …)` |
| `afterGet` – safety net | exception caught, error logged, order returned safely |

**PHPUnit 9 mock note — `CustomerAddress` setter split:**

`setStreet()` and `setRegionId()` are real declared methods on
`AbstractAddress` / `Address` → use `onlyMethods()`.
All other setters (`setFirstname`, `setLastname`, `setCity`, `setRegion`,
`setPostcode`, `setCountryId`, `setTelephone`) are magic `__call` methods →
use `addMethods()`. PHPUnit 9 enforces this split strictly.

---

## 10. Debugging with Xdebug

The `fpm_xdebug` container has Xdebug 3 configured in `php-xdebug.ini`:

```ini
xdebug.mode               = debug
xdebug.start_with_request = trigger   # must pass XDEBUG_SESSION env var
xdebug.client_host        = host.docker.internal
xdebug.client_port        = 9001
xdebug.idekey             = PHPSTORM
```

**PhpStorm prerequisites:**
- Settings → PHP → Debug → port = **9001**
- Server name = `www.tactacam.local` (matches `PHP_IDE_CONFIG` in `docker-compose.yml`)
- "Start Listening for PHP Debug Connections" phone icon is **active**

**Debug the diagnostic command:**

```bash
docker compose run --rm \
    -e XDEBUG_SESSION=PHPSTORM \
    fpm_xdebug \
    php /app/bin/magento tactacam:order:diagnose --increment-id=000000001
```

**Debug the unit tests:**

```bash
docker compose run --rm \
    -e XDEBUG_SESSION=PHPSTORM \
    fpm_xdebug \
    php /app/vendor/bin/phpunit \
        --configuration /app/dev/tests/unit/phpunit.xml.dist \
        --filter OrderPluginTest
```

**After any plugin change — recompile DI:**

```bash
docker compose run --rm deploy bash -c \
    "rm -rf /app/generated/code/Magento/Sales/Model/Order && \
     php /app/bin/magento setup:di:compile && \
     php /app/bin/magento cache:flush"
```
