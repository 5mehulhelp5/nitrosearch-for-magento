<?php

namespace NitroSearch\AdapterKit;

use InvalidArgumentException;

/**
 * A batch of items for `POST /v1/ingest/batch`.
 *
 * The only thing it enforces is the item ceiling, and it enforces it by REFUSING
 * rather than by splitting. Silently splitting would be friendlier and wrong: the
 * caller owns the loop that produced these items, and it is the only thing that knows
 * how to resume, how to order, and what to do if the second half fails. A kit that
 * quietly turns one call into three has taken over error handling it cannot perform.
 *
 *     $batch = new Batch();
 *     foreach ($chunk as $product) {
 *         $batch->add(ItemBuilder::product($product->id)->name($product->name)->toArray());
 *     }
 *     $json = $batch->toJson();
 *
 * Targets PHP 7.4 — see {@see Money}.
 */
final class Batch
{
    /**
     * The wire's ceiling. Generated alongside the schema from the value the endpoint
     * actually enforces, so this cannot drift into optimism.
     */
    const MAX_ITEMS = 100;

    /** @var array<int, array<string, mixed>> */
    private $items = [];

    /** @param array<string, mixed> $item an ItemBuilder::toArray() result */
    public function add(array $item): self
    {
        if (count($this->items) >= self::MAX_ITEMS) {
            throw new InvalidArgumentException(sprintf(
                'A batch holds at most %d items and this is number %d. Send this batch and start '
                .'another — the kit will not split it for you, because only your own loop knows how '
                .'to resume if the second half fails.',
                self::MAX_ITEMS,
                count($this->items) + 1
            ));
        }

        if (! isset($item['data']) || ! is_array($item['data']) || ! isset($item['data']['id'])) {
            throw new InvalidArgumentException(
                'Each item needs a data array with an id. Build items with ItemBuilder.'
            );
        }

        $this->items[] = $item;

        return $this;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function isFull(): bool
    {
        return count($this->items) >= self::MAX_ITEMS;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        if ($this->items === []) {
            throw new InvalidArgumentException(
                'A batch must carry at least one item; the endpoint rejects an empty one with a 422.'
            );
        }

        return ['items' => $this->items];
    }

    public function toJson(): string
    {
        $json = json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new InvalidArgumentException(
                'This batch could not be encoded as JSON: '.json_last_error_msg().'. The usual cause '
                .'is a string that is not valid UTF-8 — product data straight out of a legacy '
                .'database often is not.'
            );
        }

        return $json;
    }
}
