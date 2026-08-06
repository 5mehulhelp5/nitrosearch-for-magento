# adapter-kit

A tiny, dependency-free PHP package for building NitroSearch ingest payloads.

Nothing here is required — the [schema](../schema/ingest-batch.v1.schema.json) and the
[conformance fixtures](../fixtures/) define the contract, and a JSON payload built any way you like
is equally valid. The kit exists because three specific mistakes account for most of what goes
wrong, and it makes them hard to write.

## Install

**Vendor it, do not Composer-require it.** wordpress.org, PrestaShop Addons and Magento Marketplace
all expect a self-contained package, and a module that resolves dependencies at install time will
fail review or fail on a merchant's host. Copy `src/` into your module and register the three
classes with your own autoloader — there are no other files and no dependencies.

Targets **PHP 7.4** so the kit is never the reason your module cannot ship. That is not an
endorsement of 7.4, which is end-of-life; it is a statement that this package will not be your
binding constraint.

## The three mistakes it prevents

### 1. Money scaled by the wrong power of ten

This line is in most integrations, and it is wrong for about fifty currencies:

```php
'price' => (int) round($price * 100),   // ← sends a ¥1,000 product as 100000
```

Yen has no minor unit; Kuwaiti dinar has three. Use `Money`, which looks the exponent up:

```php
Money::ofMinor(1999, 'USD')                 // $19.99
Money::ofMinor(1000, 'JPY')                 // ¥1,000, not ¥100,000
Money::fromDecimalString('19.995', 'KWD')   // 19995

Money::ofMinor(19.99, 'USD')                // throws — that is a decimal, not minor units
Money::fromDecimalString('19.999', 'USD')   // throws — USD has 2 places; round it yourself
```

`fromDecimalString` scales by moving digits, never by float multiplication: `(int) (19.99 * 100)`
is **1998**, because 19.99 is not representable in binary.

### 2. Variations sent as separate products

One product is one indexed object and **one unit of the merchant's quota**, however many variations
it has. Sending variations as top-level items fills a shopper's results with near-duplicates and
multiplies what the merchant is charged against their plan. Nest them:

```php
ItemBuilder::product(107)
    ->name('T-shirt')
    ->price(Money::fromDecimalString('15.00', 'GBP'))
    ->variant(1071, 'TS-R-S', Money::ofMinor(1500, 'GBP'), true, ['Colour' => ['Red']])
    ->variant(1072, 'TS-B-L', Money::ofMinor(1800, 'GBP'), false, ['Colour' => ['Blue']]);
```

Their SKUs become searchable, their attributes become facets, and the indexed price becomes the
range across the parent and every variation.

### 3. A price without its exponent

`ItemBuilder::price()` takes a `Money` and emits `price`, `currency` and `price_exponent`
together. There is no way to set one without the others, because a payload carrying a price and
no exponent is treated as coming from a pre-1.x module that scaled by 100 whatever the currency.

## Also worth knowing

- **`visible` fails closed.** Anything not explicitly `true` is indexed but unreachable through the
  public search key — the most common cause of "my products are not appearing". `ItemBuilder`
  always emits the field, so you can see the value in your own payload rather than infer it from a
  missing key.
- **`version` is your clock and last write wins.** An item whose version is not greater than the
  indexed one is skipped as stale. Milliseconds since epoch is the convention. A constant or absent
  version means out-of-order delivery silently overwrites newer data with older.
- **`Batch` refuses at 100 items rather than splitting.** Only your loop knows how to resume if the
  second half fails, so taking that over would be taking over error handling the kit cannot do.

## Checking your work

The [conformance fixtures](../fixtures/) carry a `wire` payload and the `expected_document`
NitroSearch produces from it. Every expected value was produced by running the real pipeline, so a
case cannot promise behaviour the backend does not have. Run them in your own CI — no secrets, no
network, no account.
