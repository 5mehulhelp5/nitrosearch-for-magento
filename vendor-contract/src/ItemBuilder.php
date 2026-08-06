<?php

namespace NitroSearch\AdapterKit;

use InvalidArgumentException;

/**
 * Builds one ingest item, so the shapes that go wrong cannot be expressed.
 *
 * Three mistakes account for most of what an adapter gets wrong, and each is made
 * structurally impossible here rather than documented:
 *
 *  1. **A price without its exponent.** {@see price()} takes a {@see Money}, and
 *     `price`, `currency` and `price_exponent` are emitted together or not at all.
 *     There is no way to set one without the others.
 *  2. **Variations as separate top-level items.** {@see variant()} nests them. One
 *     product is one indexed object and one unit of the merchant's quota however many
 *     variations it has, so sending them separately both breaks search results and
 *     multiplies what the merchant is charged against their plan.
 *  3. **Forgetting `visible`.** It fails closed, so an item that never calls
 *     {@see visible()} is indexed and unreachable. This builder therefore emits
 *     `visible` explicitly on every item — false if you did not say otherwise — so the
 *     value is always a decision you can see in your own code rather than an omission.
 *
 * Usage:
 *
 *     $item = ItemBuilder::product(107)
 *         ->name('T-shirt')
 *         ->visible(true)
 *         ->price(Money::fromDecimalString('15.00', 'GBP'))
 *         ->sku('TS')
 *         ->attribute('Colour', ['Red'])
 *         ->variant(1071, 'TS-R-S', Money::ofMinor(1500, 'GBP'), true)
 *         ->permalink('https://shop.example/t-shirt')
 *         ->version((int) (microtime(true) * 1000))
 *         ->toArray();
 *
 * Targets PHP 7.4 — see {@see Money}.
 */
final class ItemBuilder
{
    /** @var array<string, mixed> */
    private $data = [];

    /** @var array<int, array<string, mixed>> */
    private $variants = [];

    /** @var array<string, array<int, string>> */
    private $attributes = [];

    /** @var string */
    private $op = 'upsert';

    /** @var int|null */
    private $version;

    /** @var bool */
    private $isProduct;

    private function __construct($id, string $objectType, bool $isProduct)
    {
        if ($id === null || $id === '' || (is_array($id))) {
            throw new InvalidArgumentException('An item needs an id — got '.var_export($id, true).'.');
        }

        $this->isProduct = $isProduct;
        $this->data['id'] = $id;

        // Products send no object_type: absent means product on the wire, and every
        // deployed module predating whole-site search omits it. Content must say so.
        if (! $isProduct) {
            $this->data['object_type'] = $objectType;
        }
    }

    /** @param int|string $id */
    public static function product($id): self
    {
        return new self($id, 'product', true);
    }

    /**
     * A page or blog post. `$objectType` is 'page' or 'post'.
     *
     * @param  int|string  $id
     */
    public static function content($id, string $objectType): self
    {
        if ($objectType === '' || strlen($objectType) > 20) {
            throw new InvalidArgumentException(
                'object_type must be a short slug such as "page" or "post", got '
                .var_export($objectType, true).'.'
            );
        }

        return new self($id, $objectType, false);
    }

    public function name(string $name): self
    {
        $this->data['name'] = $name;

        return $this;
    }

    /** Shoppers may find this. Anything else is unreachable through the public key. */
    public function visible(bool $visible): self
    {
        $this->data['visible'] = $visible;

        return $this;
    }

    public function permalink(string $url): self
    {
        $this->data['permalink'] = $url;

        return $this;
    }

    public function image(string $url): self
    {
        $this->data['image'] = $url;

        return $this;
    }

    /** @param array<int, string> $categories */
    public function categories(array $categories): self
    {
        $this->data['categories'] = array_values($categories);

        return $this;
    }

    /** Price, currency and exponent — all three, together, or none. */
    public function price(Money $price): self
    {
        $this->requireProduct('price');

        $this->data['price'] = $price->minor();
        $this->data['currency'] = $price->currency();
        $this->data['price_exponent'] = $price->exponent();

        return $this;
    }

    public function sku(string $sku): self
    {
        $this->requireProduct('sku');
        $this->data['sku'] = $sku;

        return $this;
    }

    public function brand(string $brand): self
    {
        $this->requireProduct('brand');
        $this->data['brand'] = $brand;

        return $this;
    }

    public function inStock(bool $inStock): self
    {
        $this->requireProduct('in_stock');
        $this->data['in_stock'] = $inStock;

        return $this;
    }

    public function onSale(bool $onSale): self
    {
        $this->requireProduct('on_sale');
        $this->data['on_sale'] = $onSale;

        return $this;
    }

    public function description(string $description): self
    {
        $this->requireProduct('description');
        $this->data['description'] = $description;

        return $this;
    }

    /** A ranking hint — units sold is the usual choice. Higher sorts earlier on ties. */
    public function popularity(int $score): self
    {
        $this->requireProduct('popularity_score');
        $this->data['popularity_score'] = $score;

        return $this;
    }

    /**
     * A variation of THIS product. Never a separate item.
     *
     * @param  int|string  $id
     * @param  array<string, array<int, string>>  $attributes
     */
    public function variant($id, string $sku, Money $price, bool $inStock = true, array $attributes = []): self
    {
        $this->requireProduct('variants');

        $variant = [
            'id' => $id,
            'sku' => $sku,
            'price' => $price->minor(),
            'in_stock' => $inStock,
        ];

        if ($attributes !== []) {
            $variant['attributes'] = $attributes;
        }

        $this->variants[] = $variant;

        return $this;
    }

    /**
     * A facetable attribute. Names are normalised backend-side, so "Colour Way" and
     * "colour-way" become one facet; VALUES are not, so normalise those yourself.
     *
     * @param  array<int, string>  $values
     */
    public function attribute(string $name, array $values): self
    {
        $this->requireProduct('attributes');
        $this->attributes[$name] = array_values($values);

        return $this;
    }

    /** A short summary for a page or post. The page body is never sent. */
    public function excerpt(string $excerpt): self
    {
        $this->requireContent('excerpt');
        $this->data['excerpt'] = $excerpt;

        return $this;
    }

    /** Publication time, Unix seconds. */
    public function publishedAt(int $timestamp): self
    {
        $this->requireContent('published_at');
        $this->data['published_at'] = $timestamp;

        return $this;
    }

    /**
     * Your own monotonic clock for this object. Last write wins, so a constant or
     * absent version lets out-of-order delivery overwrite newer data with older.
     * Milliseconds since epoch is the convention.
     */
    public function version(int $version): self
    {
        $this->version = $version;

        return $this;
    }

    /** Remove this object from the index. */
    public function delete(): self
    {
        $this->op = 'delete';

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = $this->data;

        // Emitted unconditionally, because absent means false and a merchant debugging
        // "my products don't appear" should be able to see the value in their payload
        // rather than infer it from a missing key.
        if (! array_key_exists('visible', $data)) {
            $data['visible'] = false;
        }

        if ($this->attributes !== []) {
            $data['attributes'] = $this->attributes;
        }

        if ($this->variants !== []) {
            $data['variants'] = $this->variants;
        }

        $item = ['op' => $this->op, 'data' => $data];

        if ($this->version !== null) {
            $item['version'] = $this->version;
        }

        return $item;
    }

    private function requireProduct(string $field): void
    {
        if (! $this->isProduct) {
            throw new InvalidArgumentException(
                "'{$field}' is a product field and this item is content ("
                .$this->data['object_type'].'). Pages and posts carry no price, stock, '
                .'SKU, brand or variants.'
            );
        }
    }

    private function requireContent(string $field): void
    {
        if ($this->isProduct) {
            throw new InvalidArgumentException(
                "'{$field}' applies to pages and posts, not products. Build it with "
                .'ItemBuilder::content($id, \'page\').'
            );
        }
    }
}
