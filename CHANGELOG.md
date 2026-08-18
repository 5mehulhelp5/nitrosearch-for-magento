# Changelog

All notable changes to NitroSearch for Magento are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html). The version of
a release is its **git tag** — Composer and Packagist take the tag as authoritative, and
this repository deliberately declares it nowhere else.

## [Unreleased]

## [1.2.0] — 2026-08-18

### Added

- **The search panel now speaks your store view's language.** The panel a shopper sees —
  its filters, its "Add to cart", its result counts — was English on every store,
  whatever locale the view was set to, because it is drawn by a shared component that
  carries no translations of its own. It now receives them from the module, in 23
  languages: Czech, Danish, Dutch, English (UK), Finnish, French, German, Greek,
  Indonesian, Italian, Japanese, Norwegian, Polish, Portuguese (Portugal and Brazil),
  Romanian, Russian, Spanish, Swedish, Turkish, Ukrainian, Vietnamese and Chinese
  (Simplified).

  Result counts agree with the language's own grammar rather than adding an "s" —
  German says "1 Produkt gefunden" and "14 Produkte gefunden", and Romanian, Russian,
  Polish and Czech each choose between three forms depending on the number.

  Nothing to configure. A store view set to a locale not on that list is unchanged, and
  one set to a regional variant reads the closest language we publish — a de_AT or
  de_CH view reads German. Chinese (Simplified) is matched from `zh_Hans_CN`;
  Traditional Chinese is left in English rather than being shown a script it does not
  use.

## [1.1.0] — 2026-08-17

### Added

- **Appearance and storefront settings** in Stores → Configuration → Services → NitroSearch. Result
  density, colour scheme (light, dark, or match the shopper's device), corner style, accent colour,
  panel width and where filters appear — plus whether NitroSearch takes over the search results page
  and whether the "Powered by" credit is shown.

  The last two were previously fixed in code and sent on every page load with no way to change them.
  **Their defaults are unchanged**, so an existing store sees exactly what it saw before.

  Settings are stored as preset names rather than raw values, so what "compact" means can improve in
  a later release without any store's saved configuration having to change. The accent's label text
  is chosen automatically for contrast, so a pale accent gets dark text rather than an unreadable
  white — and an accent that is not a hex colour is now **refused with an explanation** instead of
  being saved and then quietly ignored on the storefront.

## [1.0.2] — 2026-08-15

### Fixed

- **Disconnect told you the opposite of what it had just done.** It reported that change detection
  was still installed and asked you to run `bin/magento nitrosearch:unsubscribe` — while disconnect
  had already removed it, as its first action. The command would have found nothing to do. The
  message now says what actually happened, and says something different in the one case where the
  removal genuinely failed, which it previously could not tell apart.

## [1.0.1] — 2026-08-10

### Fixed

- **Stores that do not price in dollars, euros or pounds were reporting the wrong revenue.** An
  order's value was scaled as though every currency had two decimal places. A store pricing in yen —
  which has no minor unit at all — reported **one hundred times** its real revenue; a store pricing
  in Kuwaiti dinar, Bahraini dinar, Jordanian dinar, Omani rial or Tunisian dinar reported a tenth
  of it, and lost the third decimal on the way. Every order, since the module was first released.
  The value is now scaled by the currency's own definition.

- **Orders that came from a search are no longer thrown away when the service is busy or briefly
  unavailable.** The module treated any refusal as final, so an order placed while the store was
  still being verified, or while the service was rate-limiting a burst of sales, was dropped and
  never counted. During a rush, every order past the per-minute limit was discarded — so **the
  busiest hour reported the least revenue**. Reports are now retried with widening gaps, and one
  that truly cannot be delivered is recorded rather than lost silently.

- **Revenue can no longer be counted twice.** The timestamp identifying an order was recalculated
  on each attempt, so a retry across a daylight-saving change looked like a second, different order.
  It is fixed when the order is queued and re-sent unchanged.

## [1.0.0] — 2026-08-08

The first release. Everything below was proven against Magento Open Source 2.4.8 with
Luma and Hyvä: connect, verify, change detection, catalogue sync, the storefront widget,
add-to-cart, and search-attributed revenue through a real guest checkout.

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
- **Ten release guards**, each of which must fail on purpose before the build trusts it.
- **Units sold and your own filter attributes**, closing the last two field gaps against the
  WooCommerce, PrestaShop and OpenCart connectors. Facets are the attributes you marked *Use in
  Layered Navigation* — Magento's own answer, so adding an attribute adds it to search and no list
  here goes stale. Popularity comes from Magento's bestseller figures, and a store that has never
  run the sales aggregation sends none rather than zero for everything.
- **A contributor guide, a release guide and continuous integration**, matching the other three
  connectors. Every push and pull request runs the unit cases and the full package validation across
  PHP 8.1–8.4.
- **`nitrosearch:status` reports your page-cache posture**, because one setting decides whether a
  search-key renewal can reach your edge at all. Magento sends purges only to `http_cache_hosts` in
  `app/etc/env.php`; with Varnish in front and that key unset, the origin re-renders and the edge
  keeps serving a dead key until its TTL expires — storefront search stops and nothing reports it.
  Measured on a real Varnish, both ways round.
- **Content Security Policy now allows the hosts YOUR store was given**, rather than a constant.
  The engine host was already derived at runtime; the widget's own script host and the analytics
  endpoint were not, so a strict-CSP storefront refused the loader and the only trace was a console
  message on the shopper's machine. Found by installing Hyvä.

### Fixed

Three defects, all found by placing a real order on a real store, and none of them
visible in the code.

- **Products indexed as out of stock, so search results had no Add to cart.** The
  serializer sent eight keys and omitted `in_stock`; the wire reads an absent
  `in_stock` as out of stock. Every product on every store therefore indexed unbuyable,
  the results grid rendered "Out of stock" throughout, and the widget correctly refused
  to offer Add to cart — which meant search-attributed revenue could not be measured
  once. Nothing errored and the sync reported success on all 2,040 products. The
  serializer now also sends the image, description, categories, brand, sale status and,
  for configurables, every variation's SKU, price, stock and option values.
- **Bundle products priced at zero.** The price came from `final_price`, falling back to
  `min_price` only when it was null — and on a bundle `final_price` is 0, because the
  bundle itself carries no price. A kit the storefront lists as "From $61.00" was
  indexed at $0.00. Which column is the headline price turns out to be a product-type
  question, and it is now answered against what each type's own page renders.
- **Attributed orders were never recorded, and would all have been the same order.**
  Two faults in one path. The observer was registered for the frontend area, but
  Magento's one-page checkout places orders through the REST API in `webapi_rest`, so
  it never ran. Underneath that, `sales_order_place_after` fires before the order is
  saved, so the order id was 0 — locally each report would overwrite the last through
  its unique key, and on the wire the hashed order reference became a constant that
  deduped every order a store ever attributed into one.

[Unreleased]: https://github.com/NitroSearch/nitrosearch-for-magento/compare/1.1.0...HEAD
[1.2.0]: https://github.com/NitroSearch/nitrosearch-for-magento/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/NitroSearch/nitrosearch-for-magento/compare/1.0.2...1.1.0
[1.0.2]: https://github.com/NitroSearch/nitrosearch-for-magento/compare/1.0.1...1.0.2
[1.0.1]: https://github.com/NitroSearch/nitrosearch-for-magento/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/NitroSearch/nitrosearch-for-magento/releases/tag/1.0.0
