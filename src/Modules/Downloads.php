<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Modules;

use FullStackStudio\Content\Http\UploadFile;
use FullStackStudio\Content\Internal\ModuleContext;

/** Digital products (`$client->downloads`): the files of a product and the download links of an order. Server token, except `forBuyer`. */
final class Downloads
{
    public function __construct(private readonly ModuleContext $ctx)
    {
    }

    /** @param array<string, mixed> $options @return list<array<string, mixed>> */
    public function listFiles(string $product, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/products/' . ModuleContext::seg($product) . '/files', 'options' => $options])->data['files'] ?? [];
    }

    /**
     * Uploads a file for a product (multipart). `variantId` limits it to one variant; `fileName` overrides the file's name.
     *
     * @param array{variantId?: string, fileName?: string} $input
     * @param array<string, mixed> $options
     * @return array<string, mixed> the file
     */
    public function addFile(string $product, UploadFile $file, array $input = [], array $options = []): array
    {
        $name = $input['fileName'] ?? null;
        $form = ['file' => $name ? new UploadFile($file->contents, $name, $file->contentType) : $file];

        if (!empty($input['variantId'])) {
            $form['variantId'] = (string) $input['variantId'];
        }

        return $this->ctx->execute(['method' => 'POST', 'path' => '/products/' . ModuleContext::seg($product) . '/files', 'form' => $form, 'options' => $options])->data['file'] ?? [];
    }

    /** @param array<string, mixed> $options @return array{deleted: true, id: string} */
    public function removeFile(string $product, string $fileId, array $options = []): array
    {
        return $this->ctx->write('DELETE', '/products/' . ModuleContext::seg($product) . '/files/' . ModuleContext::seg($fileId), null, $options);
    }

    /** The download links of an order. @param array<string, mixed> $options @return list<array<string, mixed>> */
    public function forOrder(string $order, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/orders/' . ModuleContext::seg($order) . '/downloads', 'options' => $options])->data['downloads'] ?? [];
    }

    /** A new link for one order line. @param array<string, mixed> $options @return array<string, mixed> the download */
    public function resetLink(string $order, string $lineId, array $options = []): array
    {
        return $this->ctx->write('POST', '/orders/' . ModuleContext::seg($order) . '/downloads/' . ModuleContext::seg($lineId) . '/reset', [], $options)['download'] ?? [];
    }

    /** @param array<string, mixed> $options @return array{sent: true, links: int} */
    public function resendLinks(string $order, array $options = []): array
    {
        return $this->ctx->write('POST', '/orders/' . ModuleContext::seg($order) . '/downloads/resend', [], $options);
    }

    /** The buyer's downloads with the order's access token (header `X-FSC-Order-Token`). @param array<string, mixed> $options @return list<array<string, mixed>> */
    public function forBuyer(string $orderToken, array $options = []): array
    {
        return $this->ctx->execute(['path' => '/downloads/by-order', 'headers' => ['X-FSC-Order-Token' => $orderToken], 'options' => $options])->data['downloads'] ?? [];
    }
}
