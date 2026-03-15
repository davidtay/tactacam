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

```php
namespace Tactacam\CartPromotion\Observer;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class AutoAddFreeGift implements ObserverInterface
{
    // In production, SKUs and the ratio come from a config model or
    // a custom attribute on the Cart Price Rule, not hardcoded constants.
    private const SKU_X = 'product-x-sku';
    private const SKU_Y = 'product-y-sku';

    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly ProductRepositoryInterface $productRepository
    ) {}

    public function execute(Observer $observer): void
    {
        $quote = $this->checkoutSession->getQuote();

        // 1. Count how many X items are currently in the cart.
        $xQty = 0;
        foreach ($quote->getAllVisibleItems() as $item) {
            if ($item->getSku() === self::SKU_X) {
                $xQty += (int)$item->getQty();
            }
        }

        // 2. Find any Y item already managed by this promotion.
        $yItem = null;
        foreach ($quote->getAllVisibleItems() as $item) {
            if ($item->getSku() === self::SKU_Y
                && $item->getOptionByCode('is_free_gift')
            ) {
                $yItem = $item;
                break;
            }
        }

        // 3. If X was removed entirely, remove the free Y too.
        if ($xQty === 0) {
            if ($yItem) {
                $quote->removeItem($yItem->getId());
            }
            return;
        }

        // 4. Strict 1:1 ratio – required Y qty always equals X qty.
        $requiredYQty = $xQty;

        if ($yItem) {
            // Update the existing free-gift item to the new qty.
            $yItem->setQty($requiredYQty);
        } else {
            // Auto-add Y and lock it so the customer cannot remove it manually.
            // Removal is handled by the sales_quote_remove_item observer, which
            // decrements X by the same amount to keep the ratio honest.
            $product = $this->productRepository->get(self::SKU_Y);
            $yItem   = $quote->addProduct($product, $requiredYQty);
            $yItem->addOption(['code' => 'is_free_gift', 'value' => '1']);
        }

        // 5. Mark the quote for totals recollection on the next page load.
        //    The Cart Price Rule in the discount collector will apply the
        //    100 % discount to the Y items during collectTotals().
        $quote->setTriggerRecollect(1)->save();
    }
}
```

#### Step 5 – Before-plugin: enforce ratio integrity at order placement

A customer can manipulate the cart between page loads (e.g. via direct POST or
a browser devtools edit). The before-plugin on `QuoteManagement::placeOrder()`
re-validates the X:Y ratio as the final gate before the order is written to the
database.

```php
namespace Tactacam\CartPromotion\Plugin;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteManagement;

class EnforceGiftRatio
{
    private const SKU_X = 'product-x-sku';
    private const SKU_Y = 'product-y-sku';

    public function __construct(
        private readonly CartRepositoryInterface $cartRepository
    ) {}

    /**
     * Before placeOrder fires AFTER collectTotals but BEFORE the order rows
     * are written. If Y qty exceeds X qty (cart manipulation) the excess Y
     * items are silently trimmed so the order is always placed with a valid
     * 1:1 ratio.
     *
     * Returning the (possibly modified) arguments re-enters the normal
     * placeOrder flow with no visible disruption to the customer.
     */
    public function beforePlaceOrder(
        QuoteManagement $subject,
        int $cartId,
        $paymentMethod = null
    ): array {
        $quote = $this->cartRepository->get($cartId);

        $xQty   = 0;
        $yItems = [];

        foreach ($quote->getAllVisibleItems() as $item) {
            if ($item->getSku() === self::SKU_X) {
                $xQty += (int)$item->getQty();
            }
            if ($item->getSku() === self::SKU_Y
                && $item->getOptionByCode('is_free_gift')
            ) {
                $yItems[] = $item;
            }
        }

        // Walk the free-gift items and cap their total qty at xQty.
        $allowedYQty = $xQty;
        foreach ($yItems as $yItem) {
            $itemQty = (int)$yItem->getQty();
            if ($allowedYQty <= 0) {
                $quote->removeItem($yItem->getId());
            } elseif ($itemQty > $allowedYQty) {
                $yItem->setQty($allowedYQty);
                $allowedYQty = 0;
            } else {
                $allowedYQty -= $itemQty;
            }
        }

        $this->cartRepository->save($quote);

        // Return all original arguments unchanged so placeOrder proceeds normally.
        return [$cartId, $paymentMethod];
    }
}
```

#### Decision tree

```
Does the promotion require auto-adding Y to the cart?
  No  → Native Cart Price Rule (buy_x_get_y, step=1, amount=1, max=0)
  Yes → Native rule for pricing  +  observer to manage Y qty  +  before-plugin on placeOrder
```

---

## 3. Problem solving – Different images for PDP vs API

### Requirement

A simple product must display one set of images on the Magento storefront PDP
and expose a *different* set of images through the REST/GraphQL API, which a
mobile app uses as its image source.

### Approach

The guiding principle is to keep both image sets inside the native Magento media
gallery pipeline so that all existing tooling (image resizing, CDN flushing,
import/export) works without modification.

#### Step 1 – Add a custom image role

Magento supports custom image roles through
`view.xml` (`media/images/image[@id]`). Add a new role, for example `app_image`,
to the theme's `view.xml`:

```xml
<!-- Magento_Catalog view.xml -->
<image id="app_image" type="image">
    <width>1200</width>
    <height>1200</height>
</image>
```

Merchants assign images to this role via the product admin gallery — the same UI
they already use for `base`, `small_image`, and `thumbnail`. No new attribute is
needed; roles are stored as a flag on the existing `catalog_product_entity_media_gallery_value` row.

#### Step 2 – Plugin on the Product REST/GraphQL API to swap the gallery

A plugin on `Magento\Catalog\Api\ProductRepositoryInterface::get()` (and
`getList()`) intercepts the API response and replaces `media_gallery_entries`
with only the entries assigned to the `app_image` role, falling back to the full
gallery if no `app_image` entries are found.

```php
public function afterGet(
    ProductRepositoryInterface $subject,
    ProductInterface $result
): ProductInterface {
    // Only substitute when request comes from the API area
    if ($this->state->getAreaCode() !== Area::AREA_WEBAPI_REST) {
        return $result;
    }

    $apiEntries = array_values(array_filter(
        $result->getMediaGalleryEntries() ?? [],
        fn($e) => in_array('app_image', $e->getTypes(), true)
    ));

    if (!empty($apiEntries)) {
        $result->setMediaGalleryEntries($apiEntries);
    }

    return $result;
}
```

Because the plugin runs in the `webapi_rest` area only, the storefront PDP
request — which goes through `frontend` or `graphql` area — is completely
unaffected and continues to display the full gallery.

For GraphQL the same logic is applied via a plugin on
`Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\Product`.

#### Step 3 – Fallback strategy

| Scenario | Result |
|---|---|
| Product has `app_image` entries | API returns only `app_image` entries |
| Product has no `app_image` entries | API falls back to the full gallery |
| Storefront PDP (any scenario) | Always shows the full gallery — plugin never fires |

#### Step 4 – Merchant workflow

1. Open the product in the admin.
2. Upload or select images in the **Images and Videos** tab.
3. For images intended for the app, click the image and assign the **App Image** role.
4. Images without that role are shown on the PDP only.
5. No additional UI, attribute, or code changes are required per product.

#### Why not a separate attribute?

A separate media attribute (e.g. `api_gallery`) is a common alternative but it
duplicates the storage backend, breaks the native image-resizing and CDN
pipelines, requires a custom admin UI widget, and makes import/export more
complex. The image-role approach reuses all of that infrastructure with only a
plugin and a `view.xml` entry.

