<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Internal\ModuleContext;

/** Shipping methods with zones and brackets (`$client->shipping`; module `orders`, server token). Options for a cart: `cart->shippingOptions`. */
final class Shipping
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /** @param array<string, mixed> $options @return array{methods: list<array<string, mixed>>, total: int} */
    public function listMethods(array $options = []): array
    {
        return $this->ctx->execute(['path' => '/shipping/methods', 'options' => $options])->data;
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function getMethod(string $id, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/shipping/methods/' . ModuleContext::seg($id), 'options' => $options])->data['method'] ?? [];
    }

    /** `key`, `label` and `priceCents` are required. @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> */
    public function createMethod(array $input, array $options = []): array
    {
        return $this->ctx->write('POST', '/shipping/methods', $input, $options)['method'] ?? [];
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $options @return array<string, mixed> `method`, `changed` */
    public function updateMethod(string $id, array $input, array $options = []): array
    {
        return $this->ctx->write('PATCH', '/shipping/methods/' . ModuleContext::seg($id), $input, $options);
    }

    /** @param array<string, mixed> $options @return array<string, mixed> */
    public function deleteMethod(string $id, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/shipping/methods/' . ModuleContext::seg($id), null, $options);
    }
}
