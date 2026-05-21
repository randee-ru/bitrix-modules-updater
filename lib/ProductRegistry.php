<?php

declare(strict_types=1);

namespace Randee\Update;

final class ProductRegistry
{
    public function normalizeCatalog(array $catalogResponse): array
    {
        $products = $catalogResponse['products'] ?? [];
        return is_array($products) ? array_values($products) : [];
    }

    public function indexById(array $catalogResponse): array
    {
        $indexed = [];
        foreach ($this->normalizeCatalog($catalogResponse) as $product) {
            if (!is_array($product)) {
                continue;
            }
            $productId = (string)($product['product_id'] ?? '');
            if ($productId === '') {
                continue;
            }
            $indexed[$productId] = $product;
        }

        return $indexed;
    }

    public function selectProduct(array $catalogResponse, string $productId): ?array
    {
        $indexed = $this->indexById($catalogResponse);
        return $indexed[$productId] ?? null;
    }

    public function firstProduct(array $catalogResponse): ?array
    {
        $products = $this->normalizeCatalog($catalogResponse);
        return $products[0] ?? null;
    }

    public function latestRelease(array $product): ?array
    {
        $release = $product['latest_release'] ?? null;
        return is_array($release) ? $release : null;
    }
}
