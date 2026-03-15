<?php
declare(strict_types=1);

namespace Tactacam\Order\Test\Unit\Plugin;

use Magento\Customer\Model\Address as CustomerAddress;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tactacam\Order\Plugin\OrderPlugin;

/**
 * Unit tests for \Tactacam\Order\Plugin\OrderPlugin
 *
 * Covers every branch in both plugin methods:
 *   • afterGetData – fires when $order->getCustomer() is called anywhere in the
 *                    codebase (resolves through AbstractModel::__call → getData('customer'))
 *   • afterGet     – fires after OrderRepositoryInterface::get() returns an order
 *
 * No Magento bootstrap is required; all dependencies are pure PHPUnit mocks.
 *
 * Run with Xdebug (step-debugging):
 *   docker compose run --rm \
 *       -e XDEBUG_SESSION=PHPSTORM \
 *       fpm_xdebug \
 *       php /app/vendor/bin/phpunit \
 *           --configuration /app/dev/tests/unit/phpunit.xml.dist \
 *           --filter OrderPluginTest
 *
 * Run without Xdebug:
 *   docker compose run --rm deploy \
 *       php /app/vendor/bin/phpunit \
 *           --configuration /app/dev/tests/unit/phpunit.xml.dist \
 *           --filter OrderPluginTest
 *
 * @covers \Tactacam\Order\Plugin\OrderPlugin
 */
class OrderPluginTest extends TestCase
{
    // ── Test-fixture constants ────────────────────────────────────────────────

    private const STORE_ID = 1;

    /**
     * Representative order-snapshot address that every "happy-path" test uses.
     * Values are deliberately different from the customer's address book so that
     * any setter that is NOT called (a regression) becomes immediately visible.
     */
    private const SNAPSHOT = [
        'firstname'  => 'Jane',
        'lastname'   => 'Doe',
        'street'     => ['123 Main St', 'Apt 4'],
        'city'       => 'Springfield',
        'region_id'  => 12,
        'region'     => 'IL',
        'postcode'   => '62701',
        'country_id' => 'US',
        'telephone'  => '5555551234',
    ];

    // ── System-under-test ─────────────────────────────────────────────────────

    private OrderPlugin $plugin;

    // ── Mocks ─────────────────────────────────────────────────────────────────

    /** @var ScopeConfigInterface&MockObject */
    private $scopeConfigMock;

    /** @var CustomerFactory&MockObject */
    private $customerFactoryMock;

    /** @var LoggerInterface&MockObject */
    private $loggerMock;

    /** @var Order&MockObject – $subject passed to afterGetData */
    private $orderSubjectMock;

    /** @var Order&MockObject – $result returned by OrderRepository::get() */
    private $orderResultMock;

    /** @var OrderAddress&MockObject – the sales_order_address snapshot */
    private $orderAddressMock;

    /** @var Customer&MockObject */
    private $customerMock;

    /** @var CustomerAddress&MockObject */
    private $customerAddressMock;

    /** @var OrderRepositoryInterface&MockObject */
    private $orderRepoMock;

    // ── Set-up ────────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        $this->scopeConfigMock     = $this->createMock(ScopeConfigInterface::class);
        $this->loggerMock          = $this->createMock(LoggerInterface::class);
        $this->orderRepoMock       = $this->createMock(OrderRepositoryInterface::class);

        $this->customerFactoryMock = $this->getMockBuilder(CustomerFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Customer model – load() returns $this so chaining works correctly
        $this->customerMock = $this->getMockBuilder(Customer::class)
            ->disableOriginalConstructor()
            ->getMock();

        // CustomerAddress has a mix of real and magic setter methods.
        // PHPUnit 9 requires them to be split correctly:
        //   onlyMethods() → real declared methods that exist on the class
        //   addMethods()  → magic methods resolved through AbstractModel::__call
        //
        // Real declared: setStreet() (AbstractAddress), setRegionId() (Address)
        // Magic (__call): setFirstname, setLastname, setCity, setRegion,
        //                 setPostcode, setCountryId, setTelephone
        $this->customerAddressMock = $this->getMockBuilder(CustomerAddress::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setStreet', 'setRegionId'])
            ->addMethods(['setFirstname', 'setLastname', 'setCity', 'setRegion', 'setPostcode', 'setCountryId', 'setTelephone'])
            ->getMock();

        $this->orderAddressMock = $this->getMockBuilder(OrderAddress::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Order used as the $subject of afterGetData
        $this->orderSubjectMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Order used as the $result of afterGet (same concrete class that
        // implements OrderDataInterface AND carries getShippingAddress())
        $this->orderResultMock = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();

        // CustomerFactory always hands back the same $customerMock
        $this->customerFactoryMock->method('create')->willReturn($this->customerMock);

        $this->plugin = new OrderPlugin(
            $this->scopeConfigMock,
            $this->customerFactoryMock,
            $this->loggerMock
        );
    }

    // =========================================================================
    //  afterGetData – early-exit / guard paths
    // =========================================================================

    /**
     * getData() is called hundreds of times per request for every property on
     * Order. The plugin must ignore every key that is not 'customer' with zero
     * side-effects.
     */
    public function testAfterGetDataPassesThroughForNonCustomerKey(): void
    {
        $sentinel = new \stdClass();
        $result   = $this->plugin->afterGetData($this->orderSubjectMock, $sentinel, 'grand_total');

        $this->assertSame($sentinel, $result, 'A non-customer key must be returned unchanged.');
    }

    /**
     * When getData('customer') returns null the customer object has not yet
     * been loaded onto the order – nothing to override.
     */
    public function testAfterGetDataPassesThroughForNullCustomer(): void
    {
        $result = $this->plugin->afterGetData($this->orderSubjectMock, null, 'customer');

        $this->assertNull($result, 'A null customer must be returned as-is.');
    }

    /**
     * The Magento admin dashboard (and some ERP connectors) store the customer
     * name as a plain string under the 'customer' key, e.g.:
     *   $order->setData('customer', 'David Tay')
     *
     * Calling getDefaultShippingAddress() on a string would be fatal.
     * The instanceof guard must catch this and return the string unchanged.
     */
    public function testAfterGetDataPassesThroughForStringCustomerName(): void
    {
        $result = $this->plugin->afterGetData($this->orderSubjectMock, 'David Tay', 'customer');

        $this->assertSame('David Tay', $result, 'A string stored under the customer key must be returned unchanged.');
    }

    /**
     * The plugin must be a complete no-op when the admin toggle is off.
     * (Stores → Configuration → Tactacam → Order Customizations → Enabled = No)
     */
    public function testAfterGetDataPassesThroughWhenModuleIsDisabled(): void
    {
        $this->orderSubjectMock->method('getStoreId')->willReturn(0);
        $this->scopeConfigMock->method('isSetFlag')->willReturn(false);

        $customerMock = $this->createMock(Customer::class);
        $result       = $this->plugin->afterGetData($this->orderSubjectMock, $customerMock, 'customer');

        $this->assertSame($customerMock, $result, 'Customer must be returned unchanged when disabled.');
    }

    /**
     * Virtual / downloadable orders have no shipping address row.
     * getShippingAddress() returns null; the plugin must return the customer untouched.
     */
    public function testAfterGetDataPassesThroughForVirtualOrder(): void
    {
        $this->enablePlugin();
        $this->orderSubjectMock->method('getShippingAddress')->willReturn(null);

        $customerMock = $this->createMock(Customer::class);
        $result       = $this->plugin->afterGetData($this->orderSubjectMock, $customerMock, 'customer');

        $this->assertSame($customerMock, $result, 'Virtual orders have no shipping snapshot – customer unchanged.');
    }

    /**
     * A shipping address object that has no entity ID should be treated as absent.
     * This can occur when the object is populated in memory but not yet persisted.
     */
    public function testAfterGetDataPassesThroughWhenOrderAddressHasNoId(): void
    {
        $this->enablePlugin();
        $this->orderAddressMock->method('getId')->willReturn(null);
        $this->orderSubjectMock->method('getShippingAddress')->willReturn($this->orderAddressMock);

        $customerMock = $this->createMock(Customer::class);
        $result       = $this->plugin->afterGetData($this->orderSubjectMock, $customerMock, 'customer');

        $this->assertSame($customerMock, $result);
    }

    /**
     * If the customer has never saved a shipping address the override is
     * impossible. The plugin must log a notice (visible in var/log/system.log)
     * and return the customer object without modification.
     */
    public function testAfterGetDataLogsNoticeWhenCustomerHasNoDefaultShippingAddress(): void
    {
        $this->enablePlugin();
        $this->orderSubjectMock->method('getStoreId')->willReturn(self::STORE_ID);
        $this->orderSubjectMock->method('getIncrementId')->willReturn('100000001');
        $this->orderAddressMock->method('getId')->willReturn(99);
        $this->orderSubjectMock->method('getShippingAddress')->willReturn($this->orderAddressMock);

        $this->customerMock->method('getId')->willReturn(42);
        $this->customerMock->method('getDefaultShippingAddress')->willReturn(null);

        $this->loggerMock->expects($this->once())->method('notice');

        $result = $this->plugin->afterGetData($this->orderSubjectMock, $this->customerMock, 'customer');

        $this->assertSame($this->customerMock, $result);
    }

    // =========================================================================
    //  afterGetData – happy path
    // =========================================================================

    /**
     * Core scenario – ported from OrderTest.php Section 5-C.
     *
     * The M1-style ERP connector calls:
     *   $order->getCustomer()->getDefaultShippingAddress()
     *
     * Before the plugin:  returns the customer's current address book entry
     *                     (which may have changed since the order was placed).
     * After  the plugin:  every field is replaced in-memory with the value
     *                     from sales_order_address (the placement-time snapshot).
     *
     * All nine setters must be called exactly once with the snapshot values.
     * The customer object itself must be returned (same reference).
     * No save() call must occur (address book must remain untouched in the DB).
     */
    public function testAfterGetDataOverridesCustomerShippingAddressWithOrderSnapshot(): void
    {
        $this->enablePlugin();
        $this->orderSubjectMock->method('getStoreId')->willReturn(self::STORE_ID);
        $this->orderAddressMock->method('getId')->willReturn(99);
        $this->orderSubjectMock->method('getShippingAddress')->willReturn($this->orderAddressMock);

        $this->configureOrderAddressSnapshot($this->orderAddressMock);
        $this->expectCustomerAddressSetters($this->customerAddressMock);

        $this->customerMock->method('getId')->willReturn(42);
        $this->customerMock->method('getDefaultShippingAddress')->willReturn($this->customerAddressMock);

        $result = $this->plugin->afterGetData($this->orderSubjectMock, $this->customerMock, 'customer');

        $this->assertSame(
            $this->customerMock,
            $result,
            'The (in-memory-modified) customer object must be returned.'
        );
    }

    // =========================================================================
    //  afterGet – early-exit / guard paths
    // =========================================================================

    /**
     * Guest orders have no customer_entity row. The plugin must skip them
     * immediately after reading getCustomerId() === 0.
     */
    public function testAfterGetPassesThroughForGuestOrder(): void
    {
        $this->orderResultMock->method('getCustomerId')->willReturn(0);

        $result = $this->plugin->afterGet($this->orderRepoMock, $this->orderResultMock, 1);

        $this->assertSame($this->orderResultMock, $result, 'Guest orders must be returned untouched.');
    }

    /**
     * Module disabled via admin config – afterGet must be a no-op.
     */
    public function testAfterGetPassesThroughWhenModuleIsDisabled(): void
    {
        $this->orderResultMock->method('getCustomerId')->willReturn(42);
        $this->orderResultMock->method('getStoreId')->willReturn(0);
        $this->scopeConfigMock->method('isSetFlag')->willReturn(false);

        $result = $this->plugin->afterGet($this->orderRepoMock, $this->orderResultMock, 1);

        $this->assertSame($this->orderResultMock, $result, 'Order must be returned unchanged when disabled.');
    }

    /**
     * Virtual / downloadable order – getShippingAddress() returns null.
     */
    public function testAfterGetPassesThroughWhenNoShippingAddress(): void
    {
        $this->enablePlugin();
        $this->orderResultMock->method('getCustomerId')->willReturn(42);
        $this->orderResultMock->method('getStoreId')->willReturn(self::STORE_ID);
        $this->orderResultMock->method('getShippingAddress')->willReturn(null);

        $result = $this->plugin->afterGet($this->orderRepoMock, $this->orderResultMock, 1);

        $this->assertSame($this->orderResultMock, $result);
    }

    /**
     * Shipping address object exists but carries no entity ID – treat as absent.
     */
    public function testAfterGetPassesThroughWhenOrderAddressHasNoId(): void
    {
        $this->enablePlugin();
        $this->orderResultMock->method('getCustomerId')->willReturn(42);
        $this->orderResultMock->method('getStoreId')->willReturn(self::STORE_ID);
        $this->orderAddressMock->method('getId')->willReturn(null);
        $this->orderResultMock->method('getShippingAddress')->willReturn($this->orderAddressMock);

        $result = $this->plugin->afterGet($this->orderRepoMock, $this->orderResultMock, 1);

        $this->assertSame($this->orderResultMock, $result);
    }

    /**
     * The customer_id on the order does not resolve to a real customer_entity row
     * (deleted account, data inconsistency, etc.).
     */
    public function testAfterGetPassesThroughWhenCustomerNotFound(): void
    {
        $this->enablePlugin();
        $this->orderResultMock->method('getCustomerId')->willReturn(42);
        $this->orderResultMock->method('getStoreId')->willReturn(self::STORE_ID);
        $this->orderAddressMock->method('getId')->willReturn(99);
        $this->orderResultMock->method('getShippingAddress')->willReturn($this->orderAddressMock);

        $this->customerMock->method('load')->willReturnSelf();
        $this->customerMock->method('getId')->willReturn(0); // not found in DB

        $result = $this->plugin->afterGet($this->orderRepoMock, $this->orderResultMock, 1);

        $this->assertSame($this->orderResultMock, $result, 'Unknown customer – order must be returned unchanged.');
    }

    /**
     * Customer exists but has no default shipping address.
     * The plugin must:
     *   1. Log a notice so the gap is visible in var/log/system.log.
     *   2. Still attach the customer to the order so the ERP has customer data.
     */
    public function testAfterGetLogsNoticeAndAttachesCustomerWhenNoDefaultShippingAddress(): void
    {
        $this->enablePlugin();
        $this->orderResultMock->method('getCustomerId')->willReturn(42);
        $this->orderResultMock->method('getStoreId')->willReturn(self::STORE_ID);
        $this->orderResultMock->method('getIncrementId')->willReturn('100000001');
        $this->orderAddressMock->method('getId')->willReturn(99);
        $this->orderResultMock->method('getShippingAddress')->willReturn($this->orderAddressMock);

        $this->customerMock->method('load')->willReturnSelf();
        $this->customerMock->method('getId')->willReturn(42);
        $this->customerMock->method('getDefaultShippingAddress')->willReturn(null);

        $this->loggerMock->expects($this->once())->method('notice');

        $this->orderResultMock->expects($this->once())
            ->method('setData')
            ->with('customer', $this->customerMock);

        $result = $this->plugin->afterGet($this->orderRepoMock, $this->orderResultMock, 1);

        $this->assertSame($this->orderResultMock, $result);
    }

    // =========================================================================
    //  afterGet – happy path
    // =========================================================================

    /**
     * Core scenario – ported from OrderTest.php Section 5-B.
     *
     * Repository path:  $orderRepository->get($orderId)
     * Expected outcome:
     *   • All nine address setters are called with the order snapshot values.
     *   • The corrected customer is attached via $order->setData('customer', …).
     *   • Any subsequent $order->getData('customer') returns the pre-loaded,
     *     pre-corrected customer without a second DB hit.
     */
    public function testAfterGetOverridesShippingAddressAndAttachesCustomerToOrder(): void
    {
        $this->enablePlugin();
        $this->orderResultMock->method('getCustomerId')->willReturn(42);
        $this->orderResultMock->method('getStoreId')->willReturn(self::STORE_ID);
        $this->orderAddressMock->method('getId')->willReturn(99);
        $this->orderResultMock->method('getShippingAddress')->willReturn($this->orderAddressMock);

        $this->configureOrderAddressSnapshot($this->orderAddressMock);
        $this->expectCustomerAddressSetters($this->customerAddressMock);

        $this->customerMock->method('load')->willReturnSelf();
        $this->customerMock->method('getId')->willReturn(42);
        $this->customerMock->method('getDefaultShippingAddress')->willReturn($this->customerAddressMock);

        $this->orderResultMock->expects($this->once())
            ->method('setData')
            ->with('customer', $this->customerMock);

        $result = $this->plugin->afterGet($this->orderRepoMock, $this->orderResultMock, 1);

        $this->assertSame($this->orderResultMock, $result);
    }

    /**
     * Any exception thrown inside afterGet (e.g. a DB outage while loading the
     * customer) must be caught, logged, and must NOT propagate – order loading
     * must always succeed regardless of the plugin's internal state.
     */
    public function testAfterGetCatchesExceptionAndLogsErrorWithoutBreakingOrderLoad(): void
    {
        $this->enablePlugin();
        $this->orderResultMock->method('getCustomerId')->willReturn(42);
        $this->orderResultMock->method('getStoreId')->willReturn(self::STORE_ID);
        $this->orderAddressMock->method('getId')->willReturn(99);
        $this->orderResultMock->method('getShippingAddress')->willReturn($this->orderAddressMock);

        $this->customerMock->method('load')
            ->willThrowException(new \Exception('DB connection lost'));

        $this->loggerMock->expects($this->once())->method('error');

        $result = $this->plugin->afterGet($this->orderRepoMock, $this->orderResultMock, 1);

        $this->assertSame(
            $this->orderResultMock,
            $result,
            'afterGet must always return the order result even when an exception occurs.'
        );
    }

    // =========================================================================
    //  Private helpers
    // =========================================================================

    /**
     * Tell scopeConfig to report the module as enabled.
     *
     * Uses a loose willReturn(true) without argument constraints so tests do not
     * need to predict the exact store-ID argument (which is derived from
     * getStoreId() stubs that vary per test).
     */
    private function enablePlugin(): void
    {
        $this->scopeConfigMock->method('isSetFlag')->willReturn(true);
    }

    /**
     * Configure the order-address mock to return the SNAPSHOT values for every
     * getter that OrderPlugin reads.
     */
    private function configureOrderAddressSnapshot(MockObject $address): void
    {
        $address->method('getFirstname')->willReturn(self::SNAPSHOT['firstname']);
        $address->method('getLastname')->willReturn(self::SNAPSHOT['lastname']);
        $address->method('getStreet')->willReturn(self::SNAPSHOT['street']);
        $address->method('getCity')->willReturn(self::SNAPSHOT['city']);
        $address->method('getRegionId')->willReturn(self::SNAPSHOT['region_id']);
        $address->method('getRegion')->willReturn(self::SNAPSHOT['region']);
        $address->method('getPostcode')->willReturn(self::SNAPSHOT['postcode']);
        $address->method('getCountryId')->willReturn(self::SNAPSHOT['country_id']);
        $address->method('getTelephone')->willReturn(self::SNAPSHOT['telephone']);
    }

    /**
     * Assert that every setter on a CustomerAddress mock is called exactly once
     * with the corresponding SNAPSHOT value, and configure each to return $this
     * so the fluent chain inside the plugin does not break.
     */
    private function expectCustomerAddressSetters(MockObject $address): void
    {
        $address->expects($this->once())->method('setFirstname')
            ->with(self::SNAPSHOT['firstname'])->willReturnSelf();
        $address->expects($this->once())->method('setLastname')
            ->with(self::SNAPSHOT['lastname'])->willReturnSelf();
        $address->expects($this->once())->method('setStreet')
            ->with(self::SNAPSHOT['street'])->willReturnSelf();
        $address->expects($this->once())->method('setCity')
            ->with(self::SNAPSHOT['city'])->willReturnSelf();
        $address->expects($this->once())->method('setRegionId')
            ->with(self::SNAPSHOT['region_id'])->willReturnSelf();
        $address->expects($this->once())->method('setRegion')
            ->with(self::SNAPSHOT['region'])->willReturnSelf();
        $address->expects($this->once())->method('setPostcode')
            ->with(self::SNAPSHOT['postcode'])->willReturnSelf();
        $address->expects($this->once())->method('setCountryId')
            ->with(self::SNAPSHOT['country_id'])->willReturnSelf();
        $address->expects($this->once())->method('setTelephone')
            ->with(self::SNAPSHOT['telephone'])->willReturnSelf();
    }
}


