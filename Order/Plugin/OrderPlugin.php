<?php
namespace Tactacam\Order\Plugin;

use Magento\Customer\Model\Customer;
use Magento\Customer\Model\CustomerFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Api\Data\OrderInterface as OrderDataInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class OrderPlugin
{
    /**
     * Admin config path that controls whether both plugins are active.
     * Matches system.xml: section=tactacam_order_config / group=general / field=enabled
     * Default value (enabled) is set in etc/config.xml.
     */
    const CONFIG_PATH_ENABLED = 'tactacam_order_config/general/enabled';

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * Returns true when the module is enabled in Stores → Configuration.
     *
     * We use the order's store_id when available so that a per-website or
     * per-store override in the admin is respected.
     *
     * @param int|null $storeId
     * @return bool
     */
    private function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @var CustomerFactory
     *
     * We use the legacy model factory (not CustomerRepositoryInterface) because
     * the ERP connector expects a \Magento\Customer\Model\Customer instance
     * from $order->getCustomer(), not a CustomerInterface DTO.
     * TODO: migrate to CustomerRepositoryInterface + DataObjectHelper if the
     *       ERP is updated to accept the service-contract type.
     */
    protected $customerFactory;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        CustomerFactory $customerFactory,
        LoggerInterface $logger
    ) {
        $this->scopeConfig     = $scopeConfig;
        $this->customerFactory = $customerFactory;
        $this->logger          = $logger;
    }

    // =========================================================================
    // Plugin 1: Magento\Sales\Model\Order::getData('customer')
    //
    // WHY getData() AND NOT getCustomer():
    //   getCustomer() is declared only as a "@method" docblock on Order (line 74
    //   of vendor/magento/module-sales/Model/Order.php).  It is NOT a real PHP
    //   method – it resolves through AbstractModel::__call() → getData().
    //   Magento's interceptor generator only wraps *real declared* methods, so
    //   afterGetCustomer is NEVER emitted in the generated Interceptor.
    //   getData() IS a real declared method on \Magento\Framework\DataObject,
    //   so its after-wrapper IS generated and this plugin fires every time.
    //
    // We guard on $key === 'customer' so the extra overhead applies only to
    // that one key and not to the hundreds of other getData() calls on Order.
    // =========================================================================

    /**
     * Intercept getData() when the key is 'customer'.
     *
     * $order->getCustomer() → AbstractModel::__call() → $this->getData('customer')
     * which is the call we catch here.
     *
     * @param Order  $subject
     * @param mixed  $result  Value already returned by getData()
     * @param string $key     The data key being fetched
     * @param mixed  $index   Optional index (unused here)
     * @return mixed
     */
    public function afterGetData(Order $subject, $result, $key = '', $index = null)
    {
        // Only act when the 'customer' key returns an actual Customer model.
        // The admin dashboard (and some ERP connectors) store the customer's
        // name as a plain string under this same key, e.g.:
        //   $order->setData('customer', 'David Tay')
        // A null / string / array result must be passed through untouched;
        // calling getDefaultShippingAddress() on a non-object would be fatal.
        if ($key !== 'customer' || !($result instanceof Customer)) {
            return $result;
        }

        if (!$this->isEnabled((int)$subject->getStoreId() ?: null)) {
            return $result;
        }

        $orderSnapshot = $subject->getShippingAddress();

        if (!$orderSnapshot || !$orderSnapshot->getId()) {
            // Virtual / downloadable order – no shipping address exists
            return $result;
        }

        // Attempt to get the customer's default shipping address model
        $customerShipping = $result->getDefaultShippingAddress();

        if ($customerShipping === null) {
            // Customer has no saved address – nothing to override
            $this->logger->notice(
                'Tactacam\Order\Plugin\OrderPlugin::afterGetCustomer – ' .
                'customer #' . $result->getId() . ' has no default shipping address; ' .
                'order #' . $subject->getIncrementId() . ' snapshot was not applied.'
            );
            return $result;
        }

        // Override every shipping field with the order snapshot value.
        // This is an in-memory change only – $customerShipping->save() is
        // deliberately NOT called, so the customer's address book is untouched.
        $customerShipping
            ->setFirstname($orderSnapshot->getFirstname())
            ->setLastname($orderSnapshot->getLastname())
            ->setStreet($orderSnapshot->getStreet())          // returns array
            ->setCity($orderSnapshot->getCity())
            ->setRegionId($orderSnapshot->getRegionId())
            ->setRegion($orderSnapshot->getRegion())
            ->setPostcode($orderSnapshot->getPostcode())
            ->setCountryId($orderSnapshot->getCountryId())
            ->setTelephone($orderSnapshot->getTelephone());

        return $result;
    }

    // =========================================================================
    // Plugin 2: Magento\Sales\Api\OrderRepositoryInterface::get()
    //
    // Intercept order loading at the repository / REST API layer.
    // After the order is fetched, load the customer, apply the same address
    // override, and call setCustomer() on the order so any subsequent
    // $order->getCustomer() call in the same request returns the corrected
    // customer without hitting the DB again.
    //
    // This covers:
    //   - REST API: GET /rest/V1/orders/:id
    //   - PHP: $orderRepository->get($id)
    //   - Any other service-contract consumer
    // =========================================================================

    /**
     * @param OrderRepositoryInterface $subject
     * @param OrderDataInterface       $result   Concrete \Magento\Sales\Model\Order
     * @param int                      $id
     * @return OrderDataInterface
     */
    public function afterGet(OrderRepositoryInterface $subject, OrderDataInterface $result, $id)
    {
        $customerId = (int)$result->getCustomerId();

        if ($customerId === 0) {
            // Guest order – no customer_entity record to load
            return $result;
        }

        if (!$this->isEnabled((int)$result->getStoreId() ?: null)) {
            return $result;
        }

        // getShippingAddress() is on the concrete Order model; OrderDataInterface
        // doesn't declare it, but the concrete class always implements it.
        $orderSnapshot = method_exists($result, 'getShippingAddress')
            ? $result->getShippingAddress()
            : null;

        if (!$orderSnapshot || !$orderSnapshot->getId()) {
            return $result;
        }

        try {
            /** @var \Magento\Customer\Model\Customer $customer */
            $customer = $this->customerFactory->create()->load($customerId);

            if (!$customer->getId()) {
                return $result;
            }

            $customerShipping = $customer->getDefaultShippingAddress();

            if ($customerShipping === null) {
                $this->logger->notice(
                    'Tactacam\Order\Plugin\OrderPlugin::afterGet – ' .
                    'customer #' . $customerId . ' has no default shipping address; ' .
                    'order #' . $result->getIncrementId() . ' snapshot was not applied.'
                );
                // Still attach the customer so ERP has customer data
                $result->setData('customer', $customer);
                return $result;
            }

            // Override in memory – no DB write
            $customerShipping
                ->setFirstname($orderSnapshot->getFirstname())
                ->setLastname($orderSnapshot->getLastname())
                ->setStreet($orderSnapshot->getStreet())
                ->setCity($orderSnapshot->getCity())
                ->setRegionId($orderSnapshot->getRegionId())
                ->setRegion($orderSnapshot->getRegion())
                ->setPostcode($orderSnapshot->getPostcode())
                ->setCountryId($orderSnapshot->getCountryId())
                ->setTelephone($orderSnapshot->getTelephone());

            // Attach the corrected customer to the order so any subsequent
            // $order->getCustomer() call in this request returns our version.
            $result->setData('customer', $customer);

        } catch (\Exception $e) {
            // Never break order loading because of the address fix
            $this->logger->error(
                'Tactacam\Order\Plugin\OrderPlugin::afterGet error – ' . $e->getMessage(),
                ['order_id' => $id, 'customer_id' => $customerId]
            );
        }

        return $result;
    }
}
