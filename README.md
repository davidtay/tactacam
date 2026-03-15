# Tactacam – Magento 2 Technical Assessment

**Candidate:** David Tay
**Date:** March 15, 2026
**Platform:** Adobe Commerce 2.4.7-p8

---

## Table of contents

1. [Coding exercise – ERP shipping address discrepancy](#1-coding-exercise--erp-shipping-address-discrepancy)
2. [Problem solving – Buy X Get Y (strict 1:1)](#2-problem-solving--buy-x-get-y-strict-11)
3. [Problem solving – Different images for PDP vs API](#3-problem-solving--different-images-for-pdp-vs-api)

---

## 1. Coding exercise – ERP shipping address discrepancy

### Problem

Customer Service reports that the shipping address the ERP received at import
time sometimes differs from the one currently on the order in Magento. There are
no admin-log entries showing an order edit and no cron job that updates orders
after ERP submission.

### Root cause analysis

The discrepancy is not caused by anything changing *on the order itself*. Magento
stores a point-in-time snapshot of the shipping address in `sales_order_address`
when the order is placed — this row never changes unless an admin explicitly
edits the order.

The most likely cause is that the ERP connector was written in the **Magento 1 style**,
loading the customer's live address book rather than the order snapshot:

```php
// ✘ Bad – reads customer_address_entity (changes whenever customer edits My Account)
$address = $order->getCustomer()->getDefaultShippingAddress();

// ✔ Correct – reads sales_order_address (the placement-time snapshot)
$address = $order->getShippingAddress();
```

When a customer updates their default shipping address after placing an order the
two records diverge. Because the change happens entirely within the customer
account — outside the order workflow — no order-change log entry is created,
which explains why there is no trace in the logs.

### Solution

The fix must not modify the ERP connector directly (risk of regression; connector
may be a third-party package). Instead, two **Magento 2 plugins** intercept every
code path that could return the wrong address and override the customer's
in-memory default shipping address with the order snapshot, without writing to
the database.

| Plugin | Registered on | Intercepts |
|---|---|---|
| `afterGetData` | `Magento\Sales\Model\Order` | `$order->getCustomer()` — the M1-style call (resolves via `getData('customer')`) |
| `afterGet` | `Magento\Sales\Api\OrderRepositoryInterface` | `$orderRepository->get($id)` — service-contract and REST API path |

Both plugins are gated behind an admin toggle
(`Stores → Configuration → Tactacam → Order Customizations → Enabled`) and
respect per-website/per-store scope, so the fix can be disabled in staging
without affecting production.

### Summary

The ERP connector reads the customer's live address book via the Magento 1
pattern `$order->getCustomer()->getDefaultShippingAddress()` rather than the
order's own `sales_order_address` snapshot, causing a divergence whenever a
customer updates their address after placing an order. Because `getCustomer()`
is a magic `@method` docblock on `Order` — not a real PHP method — a standard
`afterGetCustomer` plugin is silently ignored by the interceptor generator; the
correct interception point is `afterGetData` guarded on `$key === 'customer'`,
which is the real method the magic call resolves to at runtime. Two plugins
cover both the direct model path and the repository/REST API path, overriding
the customer's default shipping address in memory with the order snapshot on
every call, with no database write and no change to the ERP connector. An admin
toggle scoped per store allows the fix to be disabled in staging, and a CLI
diagnostic command (`bin/magento tactacam:order:diagnose`) provides a
field-by-field comparison of the two address sources for any order.

### Key technical note — why `afterGetData` not `afterGetCustomer`

`getCustomer()` is declared only as a `@method` docblock on `Order`. It is not
a real PHP method — it resolves at runtime through `AbstractModel::__call()` →
`getData('customer')`. Magento's interceptor generator only wraps **real declared
methods**, so an `afterGetCustomer` plugin is silently ignored. `getData()` *is*
a real method on `DataObject`, so its interceptor wrapper is always generated.

```php
$order->getCustomer()
// ↓ AbstractModel::__call()
$order->getData('customer')   // ← afterGetData fires here
```

### Implementation

The full implementation lives in `app/code/Tactacam/Order/`:

```
Tactacam/Order/
├── Console/Command/DiagnoseOrder.php   # bin/magento tactacam:order:diagnose
├── Plugin/OrderPlugin.php              # afterGetData + afterGet
├── Test/Unit/Plugin/OrderPluginTest.php  # 15 passing unit tests
└── etc/
    ├── di.xml           # plugin + CLI command registration
    ├── config.xml       # default: enabled = 1
    └── adminhtml/
        └── system.xml   # Stores → Config toggle
```

**Run the diagnostic tool** to compare the order snapshot against the customer's
current address book for any order:

```bash
docker compose run --rm deploy \
    php /app/bin/magento tactacam:order:diagnose --increment-id=000000001
```

**Run the unit tests:**

```bash
docker compose run --rm deploy \
    php /app/vendor/bin/phpunit \
        --configuration /app/dev/tests/unit/phpunit.xml.dist \
        --filter OrderPluginTest --testdox
```

See the **[Tactacam\_Order module README](Order/README.md)** for full documentation.

---

## 2. Problem solving – Buy X Get Y (strict 1:1)

### Requirement

A promotion where the discount scales strictly in proportion to the quantity
purchased: buy 1 of X → get 1 of Y free, buy 2 of X → get 2 of Y free, and so
on. Y must never exceed the quantity of X in the cart.

### Summary

When X and Y are already in the cart, Magento's native `buy_x_get_y` Cart
Price Rule handles strict 1:1 with no custom code — `Discount Amount = 1`,
`Discount Qty Step = 1`, and `Maximum Qty = 0` scales the discount
proportionally with quantity. Where Y must be auto-added, a custom
`free_gift_sku` attribute is added to the `salesrule` table so the merchandiser
specifies the gift product directly on the rule in the admin, keeping all
promotion configuration in one place. An observer on
`checkout_cart_product_add_after` reads the rule's Conditions to count X items
and auto-adds or adjusts Y accordingly, while the Cart Price Rule continues to
own all discount pricing through the native totals collector pipeline. A
before-plugin on `QuoteManagement::placeOrder()` acts as a final integrity
gate, re-evaluating the same Conditions immediately before the order is written
to ensure any client-side cart manipulation cannot result in more free items
than purchased items.

### Approach

#### Step 1 – Evaluate the native engine first

Magento's Cart Price Rules include a built-in action type:
**"Buy X get Y free (discount amount is Y)"** (`buy_x_get_y`). With the correct
field values it already implements strict 1:1 natively:

| Rule field | Value | Meaning |
|---|---|---|
| Discount Amount | `1` | Give 1 unit of Y free per trigger |
| Discount Qty Step (Buy X) | `1` | Trigger fires for every 1 unit of X |
| Maximum Qty Discount is Applied To | `0` | `0` = no cap → scales with qty |

**Conditions tab** — define which SKUs / attribute sets qualify as X.
**Actions tab** — define which SKUs qualify as Y.

When the cart contains 3 of X and 3 of Y, the rule fires three times and
discounts all three Y items to zero. This covers the majority of 1:1 use cases
without any custom code.

#### Step 2 – Identify where native falls short

The native engine has two common gaps with strict 1:1:

1. **Y is not already in the cart.** The native rule can only discount items
   already present; it cannot auto-add Y.
2. **X and Y are the same SKU** (e.g. "buy 2, get the 3rd free"). The rule
   requires explicit separation between the condition set and the action set.

#### Step 3 – How the totals collector works with cart price rules

Understanding this pipeline is essential before adding any custom code, because
it determines *where* custom logic should and should not live.

`$quote->collectTotals()` is called on every cart page load, mini-cart update,
and immediately before order placement. Internally it delegates to
`TotalsCollector::collect($quote)`, which iterates every address on the quote and
calls each registered **total model** in `sortOrder` sequence as declared in
`sales.xml`:

```
subtotal   → raw item row totals
discount   → cart price rules  ← rules fire here
shipping   → carrier rates
tax        → tax calculation on (subtotal − discount + shipping)
grand_total → final sum
```

The `discount` total model (`Magento\SalesRule\Model\Quote\Discount`) drives the
rule engine:

1. Calls `Validator::initTotals($items, $address)` to load all active, applicable
   rules ordered by priority.
2. Iterates every visible quote item and calls `Validator::process($item)`.
3. For each matching rule, delegates discount calculation to a rule-type-specific
   **discount calculator**. For `buy_x_get_y` rules this is
   `Magento\SalesRule\Model\Rule\Action\Discount\BuyXGetY`, which:
   - Scans the full item collection for items matching the **Conditions** (X).
   - For each qualifying X item it identifies Y items via the **Actions** conditions.
   - Calculates the free-item discount and writes it to `$item->setDiscountAmount()`.
4. The `grand_total` collector then aggregates all item discounts into the quote
   address totals.

**Why this matters for the custom implementation:**

Because all pricing math — discounts, tax, revenue reporting, and refund amounts —
flows through this pipeline, the custom code must **only manage cart item
quantities**. Setting Y's price to zero directly on the item would bypass tax
calculation and break invoice/credit memo reporting. The Cart Price Rule handles
the discount; the observer handles the qty.

```
checkout_cart_product_add_after  →  observer adds/adjusts Y qty
                                          ↓
                              $quote->collectTotals()
                                          ↓
                       discount total model: BuyXGetY calculator
                       sets discount_amount on Y items  ← rule does pricing
                                          ↓
                              grand_total aggregation
```

#### Step 4 – Observer: auto-add and adjust Y quantity

Two events cover all entry points where X quantity can change:

- `checkout_cart_product_add_after` — single product add
- `checkout_cart_update_items_after` — qty update from the cart page

X items are identified by evaluating the rule's **Conditions** against each
quote item — `$rule->getConditions()->validate($item)` — so the merchandiser
controls which products qualify as X entirely through the standard rule admin UI.
Y cannot be derived from the native rule schema alone because the `buy_x_get_y`
calculator discounts items already in the cart rather than specifying a separate
gift SKU. A lightweight custom attribute — `free_gift_sku` — is added to the
`salesrule` table and exposed in the admin **Actions** tab. The merchandiser
types the SKU once; all promotion configuration stays in one place and no code
change is needed to run a different promotion.

```php
namespace Tactacam\CartPromotion\Observer;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

class AutoAddFreeGift implements ObserverInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly RuleCollectionFactory $ruleCollectionFactory,
        private readonly StoreManagerInterface $storeManager
    ) {}

    public function execute(Observer $observer): void
    {
        $quote = $this->checkoutSession->getQuote();

        // Load every active buy_x_get_y rule that has a free_gift_sku set.
        // addWebsiteGroupDateFilter scopes to the current website, customer
        // group, and date range – the same filters the native discount
        // collector uses, so only rules that would fire in collectTotals()
        // are considered here.
        $rules = $this->ruleCollectionFactory->create()
            ->addWebsiteGroupDateFilter(
                $this->storeManager->getStore()->getWebsiteId(),
                $quote->getCustomerGroupId()
            )
            ->addFieldToFilter('simple_action', 'buy_x_get_y')
            ->addFieldToFilter('free_gift_sku', ['notnull' => true])
            ->setOrder('sort_order', 'ASC');

        foreach ($rules as $rule) {
            $this->processRule($rule, $quote);
        }

        $quote->setTriggerRecollect(1)->save();
    }

    private function processRule(\Magento\SalesRule\Model\Rule $rule, \Magento\Quote\Model\Quote $quote): void
    {
        // Count X items: those that satisfy the rule's Conditions tab.
        // The merchandiser controls which products qualify as X entirely
        // through the standard rule admin UI – no code change required.
        $xQty = 0;
        foreach ($quote->getAllVisibleItems() as $item) {
            if ($rule->getConditions()->validate($item)) {
                $xQty += (int)$item->getQty();
            }
        }

        // Y is the product the merchandiser specified in free_gift_sku.
        // We also track rule_id on the quote item so multiple simultaneous
        // promotions can each manage their own free-gift line independently.
        $freeGiftSku = $rule->getData('free_gift_sku');

        $yItem = null;
        foreach ($quote->getAllVisibleItems() as $item) {
            $ruleOpt = $item->getOptionByCode('free_gift_rule_id');
            if ($item->getSku() === $freeGiftSku
                && $item->getOptionByCode('is_free_gift')
                && $ruleOpt
                && (int)$ruleOpt->getValue() === (int)$rule->getId()
            ) {
                $yItem = $item;
                break;
            }
        }

        if ($xQty === 0) {
            if ($yItem) {
                $quote->removeItem($yItem->getId());
            }
            return;
        }

        $requiredYQty = $xQty; // strict 1:1

        if ($yItem) {
            $yItem->setQty($requiredYQty);
        } else {
            $product = $this->productRepository->get($freeGiftSku);
            $yItem   = $quote->addProduct($product, $requiredYQty);
            // Lock Y so the customer cannot remove it manually.
            // The sales_quote_remove_item observer decrements X by the same
            // amount when a locked Y is removed to keep the ratio honest.
            $yItem->addOption(['code' => 'is_free_gift',      'value' => '1']);
            $yItem->addOption(['code' => 'free_gift_rule_id', 'value' => (string)$rule->getId()]);
        }
    }
}
```

#### Step 5 – Before-plugin: enforce ratio integrity at order placement

```php
namespace Tactacam\CartPromotion\Plugin;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteManagement;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

class EnforceGiftRatio
{
    public function __construct(
        private readonly CartRepositoryInterface $cartRepository,
        private readonly RuleCollectionFactory $ruleCollectionFactory,
        private readonly StoreManagerInterface $storeManager
    ) {}

    /**
     * Before placeOrder fires AFTER collectTotals but BEFORE the order rows
     * are written. For each active buy_x_get_y rule the plugin re-counts X
     * using the same rule Conditions evaluation as the observer, then caps
     * the free-gift Y qty at that value in case the cart was manipulated
     * between page loads.
     */
    public function beforePlaceOrder(
        QuoteManagement $subject,
        int $cartId,
        $paymentMethod = null
    ): array {
        $quote = $this->cartRepository->get($cartId);

        $rules = $this->ruleCollectionFactory->create()
            ->addWebsiteGroupDateFilter(
                $this->storeManager->getStore()->getWebsiteId(),
                $quote->getCustomerGroupId()
            )
            ->addFieldToFilter('simple_action', 'buy_x_get_y')
            ->addFieldToFilter('free_gift_sku', ['notnull' => true]);

        foreach ($rules as $rule) {
            // Re-evaluate X qty from rule Conditions – same logic as the observer.
            $xQty = 0;
            foreach ($quote->getAllVisibleItems() as $item) {
                if ($rule->getConditions()->validate($item)) {
                    $xQty += (int)$item->getQty();
                }
            }

            $freeGiftSku = $rule->getData('free_gift_sku');
            $allowedYQty = $xQty;

            foreach ($quote->getAllVisibleItems() as $item) {
                $ruleOpt = $item->getOptionByCode('free_gift_rule_id');
                if ($item->getSku() !== $freeGiftSku
                    || !$item->getOptionByCode('is_free_gift')
                    || !$ruleOpt
                    || (int)$ruleOpt->getValue() !== (int)$rule->getId()
                ) {
                    continue;
                }

                $itemQty = (int)$item->getQty();
                if ($allowedYQty <= 0) {
                    $quote->removeItem($item->getId());
                } elseif ($itemQty > $allowedYQty) {
                    $item->setQty($allowedYQty);
                    $allowedYQty = 0;
                } else {
                    $allowedYQty -= $itemQty;
                }
            }
        }

        $this->cartRepository->save($quote);

        return [$cartId, $paymentMethod];
    }
}
```

#### Decision tree

```
Does the promotion require auto-adding Y to the cart?
  No  → Native Cart Price Rule (buy_x_get_y, step=1, amount=1, max=0)
  Yes → Add free_gift_sku attribute to salesrule table
        + Native rule for pricing
        + AutoAddFreeGift observer (reads Conditions for X, free_gift_sku for Y)
        + EnforceGiftRatio before-plugin on placeOrder
```

---

## 3. Problem solving – Different images for PDP vs API

### Requirement

A simple product must display one set of images on the Magento storefront PDP
and expose a *different* set of images through the REST/GraphQL API, which a
mobile app uses as its image source. Multiple API consumers may exist — mobile
app, affiliate site requiring watermarked images, etc. — each needing their own
optimised image files.

### Summary

The key insight is that the API response **structure** must stay identical across
all consumers — the same `image`, `small_image`, and `thumbnail` role names —
but the actual image files returned must differ by consumer. The standard roles
are product EAV attributes with `frontend_input = 'media_image'`; consumer-
specific variants (`mobile_image`, `affiliate_image`, etc.) are added the same
way via a setup data patch, which causes them to appear automatically in the
product admin gallery UI with no custom UI work. A plugin on
`ProductRepositoryInterface` reads an `X-Consumer-Type` request header, maps it
to the appropriate role prefix, and rewrites `media_gallery_entries` in place so
the consuming app always receives standard role names carrying the correct images.
The web PDP never sends the header and always receives the default gallery
untouched; new consumer types require only a data patch and one line in the
plugin's role-prefix map.

### Approach

The response contract is fixed — `image`, `small_image`, `thumbnail` must always
be present with those names. What changes per consumer is which image file backs
each role.

#### Step 1 – Understand how image roles are defined

The roles `image`, `small_image`, and `thumbnail` that appear in the product
admin gallery UI are **product EAV attributes** with `frontend_input =
'media_image'`, defined in
`Magento\Catalog\Setup\CategorySetup::getDefaultEntities()`. They are not
`view.xml` entries.

The gallery role dropdown is populated at runtime by
`Product\Media\Config::getMediaAttributeCodes()` which calls
`getAttributeCodesByFrontendType('media_image')` — a live database query against
`eav_attribute`. Any attribute created with `input = 'media_image'` automatically
appears as an assignable role in the admin UI.

`view.xml` image entries (e.g. `product_page_image_large`) are **rendering
configurations** — they define resize dimensions and display contexts for the
frontend. They are entirely separate from the role assignment system.

To add consumer-specific roles, create a **setup data patch** that registers new
`media_image` attributes:

```php
namespace Tactacam\Catalog\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddConsumerImageRoles implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $roles = [
            // Mobile – compressed images optimised for small screens
            'mobile_image'        => 'Mobile Base Image',
            'mobile_small_image'  => 'Mobile Small Image',
            'mobile_thumbnail'    => 'Mobile Thumbnail',
            // Affiliate – full-resolution images with watermark baked in
            'affiliate_image'        => 'Affiliate Base Image',
            'affiliate_small_image'  => 'Affiliate Small Image',
            'affiliate_thumbnail'    => 'Affiliate Thumbnail',
        ];

        foreach ($roles as $code => $label) {
            $eavSetup->addAttribute(Product::ENTITY, $code, [
                'type'                    => 'varchar',
                'label'                   => $label,
                'input'                   => 'media_image',   // ← makes it an assignable role
                'required'                => false,
                'sort_order'              => 10,
                'global'                  => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_STORE,
                'used_in_product_listing' => false,
                'visible'                 => false,
                'group'                   => 'Images',
            ]);
        }

        return $this;
    }

    public static function getDependencies(): array { return []; }
    public function getAliases(): array { return []; }
}
```

After running `bin/magento setup:upgrade`, the new roles appear in the product
admin gallery UI alongside `image`, `small_image`, and `thumbnail`.

#### Step 2 – Consumer identification via request header

API consumers identify themselves with a custom HTTP request header:

```
X-Consumer-Type: mobile
X-Consumer-Type: affiliate
```

A header is preferable to a query parameter because it separates consumer context
from resource addressing, keeps URLs cacheable and consistent, and is trivially
added to any HTTP client or SDK. The web storefront never sends the header, so it
always receives the default gallery.

#### Step 3 – Plugin: remap `media_gallery_entries` per consumer

A plugin on `ProductRepositoryInterface::get()` and `getList()` reads the header
and rewrites `media_gallery_entries` so the response always uses standard role
names but carries the consumer-specific image files. If no consumer-specific
image has been assigned for a given role the plugin falls back to the standard
role entry, guaranteeing the consumer always receives a complete set of images.

```php
namespace Tactacam\Catalog\Plugin;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\RequestInterface;

class ConsumerImagePlugin
{
    // Map each consumer type to its role prefix.
    // New consumers are added here and in view.xml only.
    private const CONSUMER_ROLE_PREFIX = [
        'mobile'    => 'mobile_',
        'affiliate' => 'affiliate_',
    ];

    private const STANDARD_ROLES = ['image', 'small_image', 'thumbnail'];

    public function __construct(
        private readonly RequestInterface $request
    ) {}

    public function afterGet(
        ProductRepositoryInterface $subject,
        ProductInterface $result
    ): ProductInterface {
        return $this->remapGallery($result);
    }

    public function afterGetById(
        ProductRepositoryInterface $subject,
        ProductInterface $result
    ): ProductInterface {
        return $this->remapGallery($result);
    }

    private function remapGallery(ProductInterface $product): ProductInterface
    {
        $consumerType = $this->request->getHeader('X-Consumer-Type');

        // No header or unknown consumer – return default gallery untouched.
        if (!$consumerType || !isset(self::CONSUMER_ROLE_PREFIX[$consumerType])) {
            return $product;
        }

        $prefix  = self::CONSUMER_ROLE_PREFIX[$consumerType];
        $entries = $product->getMediaGalleryEntries() ?? [];

        // Build a lookup of all entries indexed by their assigned roles.
        $byRole = [];
        foreach ($entries as $entry) {
            foreach ($entry->getTypes() as $role) {
                $byRole[$role] = $entry;
            }
        }

        // For each standard role, substitute the consumer-specific entry.
        // Falls back to the standard entry if no consumer variant is assigned.
        $remapped = [];
        foreach (self::STANDARD_ROLES as $standardRole) {
            $consumerRole = $prefix . $standardRole;

            if (isset($byRole[$consumerRole])) {
                // Clone the entry and set the standard role name so the consuming
                // app sees an identical response structure regardless of consumer type.
                $entry = clone $byRole[$consumerRole];
                $entry->setTypes([$standardRole]);
                $remapped[] = $entry;
            } elseif (isset($byRole[$standardRole])) {
                $remapped[] = $byRole[$standardRole]; // fallback
            }
        }

        if (!empty($remapped)) {
            $product->setMediaGalleryEntries($remapped);
        }

        return $product;
    }
}
```

#### Step 4 – Fallback strategy

| Scenario | `image` returned | `small_image` returned | `thumbnail` returned |
|---|---|---|---|
| No `X-Consumer-Type` header | Standard `image` | Standard `small_image` | Standard `thumbnail` |
| `X-Consumer-Type: mobile`, all mobile roles assigned | `mobile_image` file | `mobile_small_image` file | `mobile_thumbnail` file |
| `X-Consumer-Type: mobile`, `mobile_thumbnail` missing | `mobile_image` file | `mobile_small_image` file | Standard `thumbnail` (fallback) |
| Storefront PDP | Standard gallery — plugin never fires | | |

#### Step 5 – Merchant workflow

1. Open the product in the admin.
2. Upload the web-optimised images and assign `image`, `small_image`, `thumbnail`.
3. Upload mobile-optimised images and assign `mobile_image`, `mobile_small_image`,
   `mobile_thumbnail`.
4. Upload watermarked images and assign `affiliate_image`, `affiliate_small_image`,
   `affiliate_thumbnail`.
5. Any standard role without a consumer-specific counterpart falls back
   automatically — partial assignment is always safe.

#### Step 6 – Adding a new consumer

1. Add the three `media_image` attributes (`{consumer}_image`,
   `{consumer}_small_image`, `{consumer}_thumbnail`) to the setup data patch
   and run `bin/magento setup:upgrade`.
2. Add the consumer key and prefix to `CONSUMER_ROLE_PREFIX` in the plugin.
3. No other code changes required. Merchants begin assigning images to the new
   roles immediately.

