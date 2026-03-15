<?php
declare(strict_types=1);

namespace Tactacam\Order\Console\Command;

use Magento\Customer\Model\AddressFactory as CustomerAddressFactory;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\App\State;
use Magento\Framework\App\Area;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\OrderFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento tactacam:order:diagnose --increment-id=<ID>
 *
 * Investigates why the shipping address the ERP received at import time may
 * differ from the address currently on the order in Magento.
 *
 * Sections printed:
 *   1  Order header
 *   2  Shipping address on sales_order_address (the order snapshot)
 *   3  Customer record + current default shipping address from customer_address_entity
 *   3  Side-by-side diff
 *   4  Full sales_order_status_history log
 *   5A Interceptor existence check (getData wrapper)
 *   5B afterGet plugin live test  (OrderRepository path)
 *   5C afterGetData plugin live test ($order->getCustomer() path)
 *
 * Debug with Xdebug:
 *   docker compose run --rm \
 *       -e XDEBUG_SESSION=PHPSTORM \
 *       fpm_xdebug \
 *       php /app/bin/magento tactacam:order:diagnose --increment-id=000000001
 */
class DiagnoseOrder extends Command
{
    private const OPTION_INCREMENT_ID = 'increment-id';

    public function __construct(
        private readonly OrderFactory $orderFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CustomerFactory $customerFactory,
        private readonly CustomerAddressFactory $customerAddressFactory,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('tactacam:order:diagnose')
             ->setDescription(
                 'Diagnose shipping-address discrepancy between the ERP snapshot ' .
                 'and the current order/customer record.'
             )
             ->addOption(
                 self::OPTION_INCREMENT_ID,
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Order increment ID to investigate (e.g. 000000001)'
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Ensure a store area is active so price/locale formatters work in CLI.
        try {
            $this->appState->setAreaCode(Area::AREA_FRONTEND);
        } catch (\Exception $e) {
            // Area already set – safe to ignore.
        }

        $incrementId = (string)$input->getOption(self::OPTION_INCREMENT_ID);

        if ($incrementId === '') {
            $output->writeln('<error>--increment-id is required.</error>');
            $output->writeln('Example: bin/magento tactacam:order:diagnose --increment-id=000000001');
            return Command::FAILURE;
        }

        // ─────────────────────────────────────────────────────────────────────
        // 1. Load order – M1-style model load (same path the ERP connector uses)
        // ─────────────────────────────────────────────────────────────────────
        $order = $this->orderFactory->create()->loadByIncrementId($incrementId);

        if (!$order->getId()) {
            $output->writeln("<error>Order #{$incrementId} not found.</error>");
            return Command::FAILURE;
        }

        $output->writeln('════════════════════════════════════════════════════════════════');
        $output->writeln(" ORDER #{$order->getIncrementId()}  (entity_id: {$order->getId()})");
        $output->writeln(" Status  : {$order->getStatus()}");
        $output->writeln(" Created : {$order->getCreatedAt()}");
        $output->writeln(" Updated : {$order->getUpdatedAt()}");
        $output->writeln('════════════════════════════════════════════════════════════════');
        $output->writeln('');

        // ─────────────────────────────────────────────────────────────────────
        // 2. Shipping address stored ON the order (sales_order_address snapshot)
        // ─────────────────────────────────────────────────────────────────────
        $orderShipping = $order->getShippingAddress();
        $orderAddrData = [];

        $output->writeln('── ORDER SHIPPING ADDRESS (snapshot on sales_order_address) ────');
        if ($orderShipping) {
            $orderAddrData = [
                'firstname'  => (string)$orderShipping->getFirstname(),
                'lastname'   => (string)$orderShipping->getLastname(),
                'street'     => $orderShipping->getStreet(),
                'city'       => (string)$orderShipping->getCity(),
                'region'     => (string)($orderShipping->getRegionCode() ?: $orderShipping->getRegion()),
                'postcode'   => (string)$orderShipping->getPostcode(),
                'country_id' => (string)$orderShipping->getCountryId(),
                'telephone'  => (string)$orderShipping->getTelephone(),
            ];

            $output->writeln(" Name      : {$orderAddrData['firstname']} {$orderAddrData['lastname']}");
            $output->writeln(" Street    : " . implode(', ', (array)$orderAddrData['street']));
            $output->writeln(" City      : {$orderAddrData['city']}");
            $output->writeln(" Region    : {$orderAddrData['region']}");
            $output->writeln(" Postcode  : {$orderAddrData['postcode']}");
            $output->writeln(" Country   : {$orderAddrData['country_id']}");
            $output->writeln(" Phone     : {$orderAddrData['telephone']}");
            $output->writeln(" address_id: {$orderShipping->getId()}");
        } else {
            $output->writeln(' (no shipping address on order – may be virtual/downloadable)');
        }
        $output->writeln('');

        // ─────────────────────────────────────────────────────────────────────
        // 3. Customer + current default shipping address from address book
        // ─────────────────────────────────────────────────────────────────────
        $customerId = (int)$order->getCustomerId();

        $output->writeln("── CUSTOMER (customer_entity, id: {$customerId}) ───────────────");

        if ($customerId) {
            $customer = $this->customerFactory->create()->load($customerId);

            $output->writeln(" Name      : {$customer->getFirstname()} {$customer->getLastname()}");
            $output->writeln(" Email     : {$customer->getEmail()}");
            $output->writeln(" Created   : {$customer->getCreatedAt()}");
            $output->writeln('');

            $defaultShippingId = $customer->getDefaultShipping();
            $output->writeln('── CUSTOMER DEFAULT SHIPPING ADDRESS (customer_address_entity) ─');

            if ($defaultShippingId) {
                $customerAddr = $this->customerAddressFactory->create()->load($defaultShippingId);

                $customerAddrData = [
                    'firstname'  => (string)$customerAddr->getFirstname(),
                    'lastname'   => (string)$customerAddr->getLastname(),
                    'street'     => $customerAddr->getStreet(),
                    'city'       => (string)$customerAddr->getCity(),
                    'region'     => (string)($customerAddr->getRegionCode() ?: $customerAddr->getRegion()),
                    'postcode'   => (string)$customerAddr->getPostcode(),
                    'country_id' => (string)$customerAddr->getCountryId(),
                    'telephone'  => (string)$customerAddr->getTelephone(),
                ];

                $output->writeln(" Name      : {$customerAddrData['firstname']} {$customerAddrData['lastname']}");
                $output->writeln(" Street    : " . implode(', ', (array)$customerAddrData['street']));
                $output->writeln(" City      : {$customerAddrData['city']}");
                $output->writeln(" Region    : {$customerAddrData['region']}");
                $output->writeln(" Postcode  : {$customerAddrData['postcode']}");
                $output->writeln(" Country   : {$customerAddrData['country_id']}");
                $output->writeln(" Phone     : {$customerAddrData['telephone']}");
                $output->writeln(" address_id: {$defaultShippingId}");
                $output->writeln('');

                $output->writeln('── ADDRESS COMPARISON ──────────────────────────────────────────');
                $mismatch = $this->diffAddresses($output, $orderAddrData, $customerAddrData);

                if (!$mismatch) {
                    $output->writeln(' <info>✔  Order snapshot matches customer default shipping address.</info>');
                    $output->writeln('    If the ERP received a different address the discrepancy is in');
                    $output->writeln('    the ERP connector – it likely read customer_address_entity');
                    $output->writeln('    after the customer updated their address book post-order-placement.');
                } else {
                    $output->writeln('');
                    $output->writeln(' <comment>⚠  Addresses differ. The customer has updated their address book</comment>');
                    $output->writeln('    since this order was placed. The order snapshot (section 2) is the');
                    $output->writeln('    correct address that should have gone to the ERP.');
                }
            } else {
                $output->writeln(' (customer has no default shipping address saved)');
                $customerAddrData = [];
            }
        } else {
            $output->writeln(' Guest order – no customer_entity record.');
            $output->writeln(' The ERP connector must rely solely on sales_order_address.');
            $customerAddrData = [];
        }
        $output->writeln('');

        // ─────────────────────────────────────────────────────────────────────
        // 4. Order status-history log
        // ─────────────────────────────────────────────────────────────────────
        $output->writeln('── ORDER STATUS HISTORY (sales_order_status_history) ───────────');
        $historyItems = $order->getStatusHistories();

        if ($historyItems) {
            foreach ($historyItems as $history) {
                $output->writeln(sprintf(
                    ' [%s] status=%-20s comment=%s',
                    $history->getCreatedAt(),
                    (string)$history->getStatus(),
                    strip_tags((string)$history->getComment())
                ));
            }
        } else {
            $output->writeln(' (no history records found)');
        }

        // ─────────────────────────────────────────────────────────────────────
        // 5. OrderPlugin debugger
        // ─────────────────────────────────────────────────────────────────────
        $output->writeln('');
        $output->writeln('════════════════════════════════════════════════════════════════');
        $output->writeln(' SECTION 5 – OrderPlugin Debugger');
        $output->writeln('════════════════════════════════════════════════════════════════');
        $output->writeln('');

        // 5-A – Interceptor existence check ───────────────────────────────────
        $output->writeln('── 5-A: Interceptor check ──────────────────────────────────────');

        $orderInterceptorPath = BP . '/generated/code/Magento/Sales/Model/Order/Interceptor.php';
        $repoInterceptorPath  = BP . '/generated/code/Magento/Sales/Model/OrderRepository/Interceptor.php';

        if (file_exists($orderInterceptorPath)) {
            $output->writeln(' <info>✔  Order Interceptor found:</info>');
            $output->writeln("    {$orderInterceptorPath}");

            $interceptorSource = file_get_contents($orderInterceptorPath);
            if (strpos($interceptorSource, 'function getData') !== false) {
                $output->writeln(' <info>✔  Interceptor wraps getData() – afterGetData plugin WILL fire.</info>');
            } else {
                $output->writeln(' <error>✘  Interceptor exists but has NO getData wrapper.</error>');
                $output->writeln('    Re-run:');
                $output->writeln('      rm -rf generated/code/Magento/Sales/Model/Order');
                $output->writeln('      bin/magento setup:di:compile && bin/magento cache:flush');
            }
        } else {
            $output->writeln(' <error>✘  Order Interceptor NOT found at:</error>');
            $output->writeln("    {$orderInterceptorPath}");
            $output->writeln('    Run: bin/magento setup:di:compile && bin/magento cache:flush');
        }

        $output->writeln('');

        if (file_exists($repoInterceptorPath)) {
            $repoSource = file_get_contents($repoInterceptorPath);
            $output->writeln(' <info>✔  OrderRepository Interceptor found.</info>');
            $output->writeln('    afterGet is declared: ' .
                (strpos($repoSource, 'function get(') !== false ? 'YES' : 'NO'));
        } else {
            $output->writeln(' <error>✘  OrderRepository Interceptor NOT found – afterGet will not fire.</error>');
        }

        $orderClass = get_class($order);
        $output->writeln('');
        $output->writeln(" Runtime class of \$order (M1-style load): {$orderClass}");
        if (str_ends_with($orderClass, 'Interceptor')) {
            $output->writeln(' <info>✔  $order IS the Interceptor – model-level plugins are active.</info>');
        } else {
            $output->writeln(' <comment>⚠  $order is the raw model – model-level plugins are NOT active on this instance.</comment>');
        }
        $output->writeln('');

        // 5-B – afterGet (OrderRepository path) ──────────────────────────────
        $output->writeln('── 5-B: afterGet plugin test (OrderRepository path) ───────────');

        $repoOrder        = $this->orderRepository->get((int)$order->getId());
        $output->writeln(' Runtime class of $repoOrder: ' . get_class($repoOrder));
        $output->writeln('');

        $customerAfterGet = $repoOrder->getData('customer');

        if ($customerAfterGet === null) {
            $output->writeln(' <error>✘  afterGet did NOT attach a customer to the order.</error>');
            $output->writeln('    Either the plugin did not fire (no Interceptor) or an exception');
            $output->writeln('    was thrown inside afterGet. Check var/log/system.log for errors.');
        } else {
            $output->writeln(' <info>✔  afterGet attached a customer (class: ' . get_class($customerAfterGet) . ')</info>');
            $output->writeln('    Customer id : ' . $customerAfterGet->getId());

            $afterGetShipping = $customerAfterGet->getDefaultShippingAddress();

            if ($afterGetShipping === null) {
                $output->writeln(' <comment>⚠  Customer has no default shipping address – plugin skipped override.</comment>');
                $output->writeln('    Check var/log/system.log for a notice from afterGet.');
            } else {
                $afterGetAddrData = $this->addrFromModel($afterGetShipping);
                $output->writeln('');
                $output->writeln(' Address on customer AFTER afterGet plugin override:');
                foreach ($afterGetAddrData as $k => $v) {
                    $output->writeln("   {$k}: {$v}");
                }
                $output->writeln('');
                $output->writeln(' Diff vs order snapshot:');
                $snapForDiff = array_merge($orderAddrData, [
                    'street' => implode(', ', (array)($orderAddrData['street'] ?? [])),
                ]);
                $noMismatch = !$this->diffAddresses($output, $snapForDiff, $afterGetAddrData, 'afterGet  ');
                if ($noMismatch) {
                    $output->writeln('   <info>✔  afterGet override is CORRECT – all fields match the order snapshot.</info>');
                } else {
                    $output->writeln('   <error>✘  Fields above still differ. Check setters in OrderPlugin::afterGet.</error>');
                }
            }
        }
        $output->writeln('');

        // 5-C – afterGetData ($order->getCustomer() path) ─────────────────────
        $output->writeln('── 5-C: afterGetData plugin test (model path via getCustomer()) ─');

        if (!$customerId) {
            $output->writeln(' (guest order – skipping afterGetData test)');
        } else {
            // Load a fresh raw customer (no plugin involvement on the customer itself)
            $rawCustomer     = $this->customerFactory->create()->load($customerId);
            $rawShipping     = $rawCustomer->getDefaultShippingAddress();
            $rawAddrData     = $this->addrFromModel($rawShipping);

            $output->writeln(' BEFORE (raw customer_address_entity, no plugin):');
            foreach ($rawAddrData as $k => $v) {
                $output->writeln("   {$k}: {$v}");
            }
            $output->writeln('');

            // Attach the raw customer then call getCustomer() to trigger afterGetData
            $order->setData('customer', $rawCustomer);
            $customerViaPlugin   = $order->getCustomer();    // getData('customer') → afterGetData fires
            $afterPluginShipping = $customerViaPlugin
                ? $customerViaPlugin->getDefaultShippingAddress()
                : null;
            $afterPluginAddrData = $this->addrFromModel($afterPluginShipping);

            $output->writeln(' AFTER ($order->getCustomer()->getDefaultShippingAddress() via plugin):');
            foreach ($afterPluginAddrData as $k => $v) {
                $output->writeln("   {$k}: {$v}");
            }
            $output->writeln('');

            $output->writeln(' Diff BEFORE vs AFTER (fields the plugin should have changed):');
            $changed = false;
            foreach (array_keys($rawAddrData) as $f) {
                $before = $rawAddrData[$f]       ?? '';
                $after  = $afterPluginAddrData[$f] ?? '';
                if ($before !== $after) {
                    $output->writeln("   [{$f}]  before: {$before}  →  after: {$after}");
                    $changed = true;
                }
            }
            if (!$changed) {
                $output->writeln('   (no fields changed by the plugin)');
            }

            $output->writeln('');
            $output->writeln(' Diff AFTER vs order snapshot (plugin should produce a zero diff):');
            $snapForDiff = array_merge($orderAddrData, [
                'street' => implode(', ', (array)($orderAddrData['street'] ?? [])),
            ]);
            $noMismatch = !$this->diffAddresses($output, $snapForDiff, $afterPluginAddrData, 'afterGetData');
            if ($noMismatch) {
                $output->writeln('   <info>✔  afterGetData override is CORRECT – address matches order snapshot.</info>');
            } else {
                $output->writeln('   <error>✘  Mismatch remains after plugin. Possible causes:</error>');
                $output->writeln('      · Interceptor not generated (see 5-A) – run setup:di:compile.');
                $output->writeln('      · Plugin threw an exception silently (check var/log/system.log).');
                $output->writeln('      · Customer has no default shipping address (plugin skips null).');
                $output->writeln('      · afterGetData plugin guard ($key !== \'customer\') is wrong.');
            }
        }

        $output->writeln('');
        $output->writeln('════════════════════════════════════════════════════════════════');
        $output->writeln(' Diagnostic complete.');
        $output->writeln('════════════════════════════════════════════════════════════════');

        return Command::SUCCESS;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Extract a normalised address array from any model that has the standard
     * getFirstname() / getStreet() etc. getters.
     */
    private function addrFromModel($addr): array
    {
        if (!$addr) {
            return [];
        }

        return [
            'firstname'  => (string)$addr->getFirstname(),
            'lastname'   => (string)$addr->getLastname(),
            'street'     => implode(', ', (array)$addr->getStreet()),
            'city'       => (string)$addr->getCity(),
            'region'     => method_exists($addr, 'getRegionCode')
                                ? ((string)$addr->getRegionCode() ?: (string)$addr->getRegion())
                                : (string)$addr->getRegion(),
            'postcode'   => (string)$addr->getPostcode(),
            'country_id' => (string)$addr->getCountryId(),
            'telephone'  => (string)$addr->getTelephone(),
        ];
    }

    /**
     * Print a field-by-field diff between two address arrays.
     * Returns true if any mismatch was found.
     *
     * @param array  $snap       The order snapshot (expected)
     * @param array  $test       The address under test (actual)
     * @param string $testLabel  Label used in mismatch lines
     */
    private function diffAddresses(
        OutputInterface $output,
        array $snap,
        array $test,
        string $testLabel = 'Current'
    ): bool {
        $fields   = ['firstname', 'lastname', 'street', 'city', 'region', 'postcode', 'country_id', 'telephone'];
        $mismatch = false;

        foreach ($fields as $f) {
            $s = is_array($snap[$f] ?? null)
                ? implode(', ', $snap[$f])
                : (string)($snap[$f] ?? '');
            $t = is_array($test[$f] ?? null)
                ? implode(', ', $test[$f])
                : (string)($test[$f] ?? '');

            if ($s !== $t) {
                $output->writeln("   <error>MISMATCH [{$f}]</error>");
                $output->writeln("     Snapshot   : {$s}");
                $output->writeln("     {$testLabel}: {$t}");
                $mismatch = true;
            }
        }

        return $mismatch;
    }
}

