# Changelog

All notable changes to NitroSearch for Magento are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html). The version of
a release is its **git tag** — Composer and Packagist take the tag as authoritative, and
this repository deliberately declares it nowhere else.

## [Unreleased]

Everything below is built and unreleased. **No version has been tagged**: the module has
not yet been proven from install to live storefront search on a store a shopper can
reach, which is the bar every other NitroSearch connector was released against.

### Added

- **Change detection through Magento's own Mview**, rather than a product-save event.
  Fourteen subscribed tables — the twelve `Magento_CatalogSearch` uses, plus tier prices
  and legacy stock. A save hook misses catalog price rules, tier and customer-group
  prices, MSI reservations, `bin/magento import` and direct SQL; on Magento that does not
  fail, it drifts. Known gap, stated rather than glossed: a *full* catalog-rule reindex
  builds through a temporary table, which no trigger can see.
- **A catalogue serializer** keyed on the merchant's own `visibility` attribute, so a
  product counts when they made it findable rather than when it has no parent. Prices
  come from the price index, not raw attributes.
- **A time-boxed drain** that stops on the first failure, never throws, and runs in its
  own cron group.
- **The admin connect screen** — Stores → Configuration → Services → NitroSearch.
- **The storefront config blob and loader**, one layout file serving Luma, Hyvä and
  custom themes, with a cache tag so renewing the search key re-renders only the pages
  that carry it.
- **Two clocks** — a 300s status poll and an 86,400s search-key refresh — as separate
  cron jobs. They cannot be merged: the status call carries no key, so a store that only
  polled would hold its onboarding key until it expired and storefront search would
  silently stop.
- **Search-attributed revenue.** The real order id is hashed with the install id before
  it leaves the store; the customer, address, payment and basket never do. Queued during
  checkout, sent by cron.
- **Automatic trigger management** — created on connect, removed on disconnect, and
  re-asserted on every `setup:upgrade` for a connected store.
- **Six release guards**, each of which must fail on purpose before the build trusts it.

[Unreleased]: https://github.com/NitroSearch/nitrosearch-for-magento/commits/main
