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

The actual cause is that the ERP connector was written in the **Magento 1 style**,
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

See `Order/README.md` for full documentation.

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

#### Step 3 – Custom implementation for gap 1 (auto-add Y)

If Y must be added to the cart automatically a plugin or observer approach is
needed:

```
Observer: checkout_cart_product_add_after
          checkout_cart_update_items_after
```

The observer:
1. Reads the current cart to count X quantity.
2. Calculates the required Y quantity (`floor(qtyX / 1)` for 1:1).
3. Adds or updates Y in the cart to match.
4. Marks Y items with a custom quote-item option (`is_free_gift = 1`) so they
   are locked from manual removal.
5. A second observer on `sales_quote_remove_item` prevents direct removal of
   the locked Y item and instead decrements X to keep the ratio honest.

Pricing is handled by a standard Cart Price Rule (discount Y by 100 %) so that
revenue reporting, invoice lines, and refunds remain in the native totals
pipeline.

#### Step 4 – Enforce ratio integrity at order placement

A plugin on `\Magento\Quote\Model\QuoteManagement::placeOrder()` (before-plugin)
re-validates the X:Y ratio one final time before the order is committed, removing
excess free items silently if the cart was manipulated client-side.

#### Decision tree

```
Does the promotion require auto-adding Y to the cart?
  No  → Native Cart Price Rule (buy_x_get_y, step=1, amount=1, max=0)
  Yes → Native rule for pricing + observer to manage Y qty in cart
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

